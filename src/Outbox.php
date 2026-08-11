<?php

namespace GlpiPlugin\Pellissarisync;

use GlpiPlugin\Pellissarisync\Protocol\Envelope;
use GlpiPlugin\Pellissarisync\Transport\Client;

/**
 * Outbound queue.
 *
 * Every change is queued first and then pushed immediately; if the peer is down
 * the row stays pending and the cron task retries with an exponential backoff.
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
     * Queues an event and tries to deliver it right away.
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

        return self::deliver($id);
    }

    /**
     * Queues an event WITHOUT trying to deliver it now.
     *
     * For events produced *while applying* an inbound change. The peer cannot
     * address our ticket yet: it learns our id from the response to the delivery
     * we are still handling, so an immediate push is guaranteed to come back as
     * "unknown mirrored ticket". Leaving the row pending lets the cron flush send
     * it once that round-trip has completed.
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
     * Attempts a single delivery and records the outcome.
     */
    public static function deliver(int $id): bool
    {
        global $DB;

        $row = self::find($id);
        if ($row === null || $row['state'] === self::STATE_SENT) {
            return true;
        }

        $agent = new Agent();
        if (!$agent->getFromDB((int) $row['plugin_pellissarisync_agents_id'])) {
            self::markDead($id, 'unknown peer');
            return false;
        }

        if (!$agent->isUsable()) {
            self::reschedule($id, (int) $row['tries'], 'peer is not linked or inactive', 0);
            return false;
        }

        $result = Client::sendToAgent(
            $agent,
            (string) $row['action'],
            Envelope::decode((string) $row['payload']),
            (string) $row['idempotency_key'],
            self::FLUSH_TIMEOUT
        );

        if ($result['ok']) {
            $DB->update(self::TABLE, [
                'state'            => self::STATE_SENT,
                'tries'            => (int) $row['tries'] + 1,
                'sent_time'        => Clock::now(),
                'last_status_code' => (int) $result['status'],
                'last_error'       => '',
            ], ['id' => $id]);

            // The peer's ids are only known now, so link rows are completed here.
            Ack::handle(
                (string) $row['action'],
                Envelope::decode((string) $row['payload']),
                (array) $result['body']
            );

            $agent->markContact((int) $result['status']);

            return true;
        }

        self::reschedule($id, (int) $row['tries'], (string) $result['error'], (int) $result['status']);
        $agent->markContact((int) $result['status'], (string) $result['error']);

        Log::write('outbox delivery failed', [
            'id'     => $id,
            'action' => $row['action'],
            'status' => $result['status'],
            'error'  => $result['error'],
        ]);

        return false;
    }

    /**
     * Delivers the pending rows that are due. Returns the number of successes.
     */
    public static function flush(int $limit = 50): int
    {
        global $DB;

        $rows = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => self::TABLE,
            'WHERE'  => [
                'state'         => [self::STATE_PENDING, self::STATE_FAILED],
                'next_try_date' => ['<=', Clock::now()],
            ],
            // Oldest first, so changes on the same ticket keep their order.
            'ORDER'  => 'id ASC',
            'LIMIT'  => $limit,
        ]);

        $delivered = 0;
        foreach ($rows as $row) {
            if (self::deliver((int) $row['id'])) {
                $delivered++;
            }
        }

        return $delivered;
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

    private static function reschedule(int $id, int $tries, string $error, int $status): void
    {
        global $DB;

        $tries = $tries + 1;

        if ($tries >= self::MAX_TRIES) {
            self::markDead($id, $error, $status, $tries);
            return;
        }

        $delay = min(self::MAX_DELAY, self::BASE_DELAY * (2 ** ($tries - 1)));

        $DB->update(self::TABLE, [
            'state'            => self::STATE_FAILED,
            'tries'            => $tries,
            'next_try_date'    => date(Clock::FORMAT, strtotime(Clock::now()) + $delay),
            'last_status_code' => $status,
            'last_error'       => Compat::escapeForDb($error),
        ], ['id' => $id]);
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
