<?php

namespace GlpiPlugin\Pellissarisync;

use GlpiPlugin\Pellissarisync\Protocol\Envelope;
use GlpiPlugin\Pellissarisync\Transport\Client;

/**
 * Outbound queue.
 *
 * Every change is queued first. What happens next depends on the side:
 *
 *  - on the AGENT the row is pushed to the master right away and, if that fails,
 *    goes out again inside the next sync exchange (see Exchange::run());
 *  - on the MASTER the row is never pushed. It waits for the agent's next poll and
 *    travels back in the HTTP response (see Exchange::serve()), because the master
 *    usually cannot reach the agent at all.
 *
 * Queueing first is what makes the mirror survive an unreachable peer instead of
 * losing the event.
 */
final class Outbox
{
    public const TABLE = 'glpi_plugin_pellissarisync_outbox';

    public const STATE_PENDING = 'pending';
    public const STATE_SENT    = 'sent';
    public const STATE_FAILED  = 'failed';
    public const STATE_DEAD    = 'dead';

    private const MAX_TRIES     = 8;
    private const BASE_DELAY    = 60;
    private const MAX_DELAY     = 3600;
    private const FLUSH_TIMEOUT = 10;

    /**
     * How long a row waits when the peer is not linked yet. A fixed delay, not the
     * exponential backoff: this is not a failure, it is an administrator who has not
     * bound the agent to a customer entity yet, and that can take days.
     */
    private const HOLD_DELAY = 300;

    /**
     * Queues an event and, on the agent, tries to deliver it right away.
     */
    public static function push(int $agents_id, string $action, array $payload, string $idempotencyKey): bool
    {
        // Checked before enqueueing, so that a null from enqueue() means one thing
        // only: the row could not be written. It used to mean both, and a failed
        // INSERT was therefore reported as a successful push -- the event vanished
        // without a trace in the queue or in the log.
        if (self::isQueued($idempotencyKey)) {
            return true;
        }

        $id = self::enqueue($agents_id, $action, $payload, $idempotencyKey);

        if ($id === null) {
            Log::write('event NOT queued: the outbox insert failed', [
                'action' => $action,
                'agent'  => $agents_id,
            ]);

            return false;
        }

        // Queued is as far as the master goes: the row leaves in the response to
        // the agent's next sync.
        if (Config::isMaster()) {
            return true;
        }

        return self::deliver($id);
    }

    /**
     * Queues an event WITHOUT trying to deliver it now.
     *
     * For events produced *while applying* an inbound change. The peer cannot
     * address our ticket yet: it learns our id from the response to the delivery
     * we are still handling, so an immediate push is guaranteed to come back as
     * "unknown mirrored ticket". Leaving the row pending lets the next sync round
     * carry it, once that round-trip has completed.
     */
    public static function defer(int $agents_id, string $action, array $payload, string $idempotencyKey): bool
    {
        if (self::isQueued($idempotencyKey)) {
            return true;
        }

        if (self::enqueue($agents_id, $action, $payload, $idempotencyKey) === null) {
            Log::write('event NOT queued: the outbox insert failed', [
                'action' => $action,
                'agent'  => $agents_id,
            ]);

            return false;
        }

        return true;
    }

    public static function isQueued(string $idempotencyKey): bool
    {
        return countElementsInTable(self::TABLE, ['idempotency_key' => $idempotencyKey]) > 0;
    }

    /**
     * @return int|null the new row id, or null when the row could not be written
     */
    public static function enqueue(int $agents_id, string $action, array $payload, string $idempotencyKey): ?int
    {
        global $DB;

        if (self::isQueued($idempotencyKey)) {
            return null;
        }

        $now = Clock::now();

        // The payload is escaped here, not by the query builder: on GLPI 10 the
        // builder interpolates strings as-is. See Compat::escapeForDb().
        $DB->insert(self::TABLE, [
            'plugin_pellissarisync_agents_id' => $agents_id,
            'action'                          => Compat::escapeForDb($action),
            'payload'                         => Compat::escapeForDb(Envelope::encode($payload)),
            'idempotency_key'                 => Compat::escapeForDb($idempotencyKey),
            'state'                           => self::STATE_PENDING,
            'tries'                           => 0,
            'next_try_date'                   => $now,
            'create_time'                     => $now,
        ]);

        $id = (int) $DB->insertId();

        return $id > 0 ? $id : null;
    }

    /**
     * Attempts a single direct delivery and records the outcome. Agent only.
     */
    public static function deliver(int $id): bool
    {
        if (Config::isMaster()) {
            return false;
        }

        $row = self::find($id);
        if ($row === null || $row['state'] === self::STATE_SENT) {
            return true;
        }

        $agent = self::peerOf($row);
        if ($agent === null || !self::isDeliverable($row, $agent)) {
            return false;
        }

        $result = Client::sendToAgent(
            $agent,
            (string) $row['action'],
            Envelope::decode((string) $row['payload']),
            (string) $row['idempotency_key'],
            self::FLUSH_TIMEOUT
        );

        $agent->markContact((int) $result['status'], (string) $result['error']);

        return self::recordResult($row, $result);
    }

    /**
     * The rows due for one peer, ready to travel inside a sync exchange.
     *
     * On the master ($lease = true) handing a row out counts as an attempt: its
     * tries go up and it is pushed back by the usual backoff, so an agent that
     * keeps failing to acknowledge an event -- or crashes while applying it --
     * cannot have it forever. The acknowledgement settles it (see settle()); a
     * lease that expires unacknowledged simply hands it out again, and the agent's
     * inbox turns that repeat into a no-op.
     *
     * On the agent nothing is leased: the rows go out in the request body and the
     * answer to each one comes back in the same response.
     *
     * @return list<array{key: string, action: string, payload: array}>
     */
    public static function batch(Agent $agent, int $limit, int $maxBytes, bool $lease): array
    {
        global $DB;

        if ($limit <= 0) {
            return [];
        }

        $rows = $DB->request([
            'FROM'   => self::TABLE,
            'WHERE'  => [
                'plugin_pellissarisync_agents_id' => $agent->getID(),
                'state'                           => [self::STATE_PENDING, self::STATE_FAILED],
                'next_try_date'                   => ['<=', Clock::now()],
            ],
            // Oldest first, so changes on the same ticket keep their order.
            'ORDER'  => 'id ASC',
            // Some rows may be discarded below (orphans, exhausted leases), so a
            // little more than the limit is read.
            'LIMIT'  => $limit * 2,
        ]);

        $events = [];
        $bytes  = 0;

        foreach ($rows as $row) {
            if (count($events) >= $limit) {
                break;
            }

            if (!self::isDeliverable($row, $agent)) {
                continue;
            }

            if ($lease && (int) $row['tries'] >= self::MAX_TRIES) {
                self::markDead((int) $row['id'], 'never acknowledged by the agent', 0, (int) $row['tries']);
                continue;
            }

            // Attachments travel inline, so the batch is bounded by size as well as
            // by count -- but a single oversized row still goes out on its own,
            // or it would block the queue behind it forever.
            $size = strlen((string) $row['payload']);
            if ($events !== [] && $bytes + $size > $maxBytes) {
                break;
            }
            $bytes += $size;

            if ($lease) {
                self::leaseRow($row);
            }

            $events[] = [
                'key'     => (string) $row['idempotency_key'],
                'action'  => (string) $row['action'],
                'payload' => Envelope::decode((string) $row['payload']),
            ];
        }

        return $events;
    }

    /**
     * Records the peer's answer to one event it received in a sync exchange.
     *
     * Looked up by key AND peer: an agent can only ever settle the rows that were
     * addressed to it.
     *
     * @param bool $leased true on the master, where handing the row out already
     *                     counted the attempt
     */
    public static function settle(int $agents_id, string $key, int $status, array $body, string $error, bool $leased): bool
    {
        global $DB;

        if ($key === '') {
            return false;
        }

        $rows = $DB->request([
            'FROM'  => self::TABLE,
            'WHERE' => [
                'plugin_pellissarisync_agents_id' => $agents_id,
                'idempotency_key'                 => $key,
            ],
        ]);

        foreach ($rows as $row) {
            if ($row['state'] === self::STATE_SENT || $row['state'] === self::STATE_DEAD) {
                return $row['state'] === self::STATE_SENT;
            }

            return self::recordResult($row, [
                'ok'     => $status >= 200 && $status < 300,
                'status' => $status,
                'body'   => $body,
                'error'  => $error !== '' ? $error : ('HTTP ' . $status),
            ], countTry: !$leased);
        }

        return false;
    }

    /**
     * Records the outcome of one delivery attempt, whichever way it travelled.
     *
     * @param bool $countTry false when the attempt was already counted (a leased
     *                       row on the master)
     */
    private static function recordResult(array $row, array $result, bool $countTry = true): bool
    {
        global $DB;

        $id     = (int) $row['id'];
        $status = (int) $result['status'];
        $tries  = (int) $row['tries'] + ($countTry ? 1 : 0);

        if ($result['ok']) {
            $DB->update(self::TABLE, [
                'state'            => self::STATE_SENT,
                'tries'            => max(1, $tries),
                'sent_time'        => Clock::now(),
                'last_status_code' => $status,
                'last_error'       => '',
            ], ['id' => $id]);

            // The peer's ids are only known now, so link rows are completed here.
            Ack::handle((string) $row['action'], Envelope::decode((string) $row['payload']), (array) $result['body']);

            return true;
        }

        // The master answers 409 while it has not bound this agent to a customer
        // entity yet. That is the enrollment, not a failure: wait, without spending
        // one of the tries.
        if ($status === 409) {
            self::hold($id, (string) $result['error']);
            return false;
        }

        // Status 0 is a transport failure: the peer never saw the event, so nothing
        // was refused. It is retried with backoff but does not bring the row closer
        // to dead -- a master offline for a day must not cost the customer its queue.
        if ($status === 0 && $countTry) {
            $tries = (int) $row['tries'];
        }

        self::reschedule($id, $tries, (string) $result['error'], $status);

        Log::write('outbox delivery failed', [
            'id'     => $id,
            'action' => $row['action'],
            'status' => $status,
            'error'  => $result['error'],
        ]);

        return false;
    }

    /**
     * Whether a row can travel now. Settles the row itself when it cannot.
     */
    private static function isDeliverable(array $row, Agent $agent): bool
    {
        $id = (int) $row['id'];

        // The local side of the event has to still exist. A ticket purged after the
        // event was queued has nothing left to talk about, and delivering it anyway
        // would have the peer create or update a copy of something that is gone.
        if (self::isOrphan(Envelope::decode((string) $row['payload']))) {
            self::markDead($id, 'the local ticket was purged');
            return false;
        }

        if (!$agent->isUsable()) {
            // Revoked or deactivated is a decision, and it is not coming back on its
            // own; pending is a step in the documented enrollment, so the row waits
            // instead of spending one of its eight tries on it.
            if (($agent->fields['link_status'] ?? '') === Agent::STATUS_PENDING
                && (int) ($agent->fields['is_active'] ?? 0) === 1
            ) {
                self::hold($id, 'peer is not linked to a customer entity yet');
            } else {
                self::markDead($id, 'peer is revoked or inactive');
            }

            return false;
        }

        return true;
    }

    private static function peerOf(array $row): ?Agent
    {
        $agent = new Agent();

        if (!$agent->getFromDB((int) $row['plugin_pellissarisync_agents_id'])) {
            self::markDead((int) $row['id'], 'unknown peer');
            return null;
        }

        return $agent;
    }

    /**
     * Counts the hand-out as an attempt and pushes the row back by the backoff, so
     * it is not handed out again before the agent had the chance to acknowledge it.
     */
    private static function leaseRow(array $row): void
    {
        global $DB;

        $tries = (int) $row['tries'] + 1;

        $DB->update(self::TABLE, [
            'tries'         => $tries,
            'next_try_date' => date(Clock::FORMAT, strtotime(Clock::now()) + self::delay($tries)),
            'last_error'    => 'awaiting acknowledgement from the agent',
        ], ['id' => (int) $row['id']]);
    }

    /**
     * Rows of one peer that are due right now.
     */
    public static function countDue(int $agents_id): int
    {
        return countElementsInTable(self::TABLE, [
            'plugin_pellissarisync_agents_id' => $agents_id,
            'state'                           => [self::STATE_PENDING, self::STATE_FAILED],
            'next_try_date'                   => ['<=', Clock::now()],
        ]);
    }

    public static function countPending(): int
    {
        return countElementsInTable(self::TABLE, [
            'state' => [self::STATE_PENDING, self::STATE_FAILED],
        ]);
    }

    private static function find(int $id): ?array
    {
        global $DB;

        foreach ($DB->request(['FROM' => self::TABLE, 'WHERE' => ['id' => $id]]) as $row) {
            return $row;
        }

        return null;
    }

    /**
     * True when the ticket this event is about no longer has a live mirror here.
     *
     * `ticket.remote_id` is always the SENDER's local id, so it is our own ticket id
     * in every action -- one lookup covers all of them.
     */
    private static function isOrphan(array $payload): bool
    {
        $tickets_id = (int) ($payload['ticket']['remote_id'] ?? 0);

        if ($tickets_id <= 0) {
            return false;
        }

        $mirror = Mirror::forTicket($tickets_id);

        return $mirror === null || $mirror->isPurged();
    }

    /**
     * Postpones a row without holding it against its retry budget.
     */
    private static function hold(int $id, string $reason): void
    {
        global $DB;

        $DB->update(self::TABLE, [
            'state'         => self::STATE_PENDING,
            'next_try_date' => date(Clock::FORMAT, strtotime(Clock::now()) + self::HOLD_DELAY),
            'last_error'    => Compat::escapeForDb($reason),
        ], ['id' => $id]);
    }

    /**
     * @param int $tries the tries count to store, this attempt included
     */
    private static function reschedule(int $id, int $tries, string $error, int $status): void
    {
        global $DB;

        if ($tries >= self::MAX_TRIES) {
            self::markDead($id, $error, $status, $tries);
            return;
        }

        $DB->update(self::TABLE, [
            'state'            => self::STATE_FAILED,
            'tries'            => $tries,
            'next_try_date'    => date(Clock::FORMAT, strtotime(Clock::now()) + self::delay($tries)),
            'last_status_code' => $status,
            'last_error'       => Compat::escapeForDb($error),
        ], ['id' => $id]);
    }

    private static function delay(int $tries): int
    {
        return min(self::MAX_DELAY, self::BASE_DELAY * (2 ** (max(1, $tries) - 1)));
    }

    private static function markDead(int $id, string $error, int $status = 0, ?int $tries = null): void
    {
        global $DB;

        $values = [
            'state'            => self::STATE_DEAD,
            'last_status_code' => $status,
            'last_error'       => Compat::escapeForDb($error),
        ];

        if ($tries !== null) {
            $values['tries'] = $tries;
        }

        $DB->update(self::TABLE, $values, ['id' => $id]);

        Log::write('outbox entry gave up', ['id' => $id, 'error' => $error]);
    }
}
