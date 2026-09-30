<?php

namespace GlpiPlugin\Pellissarisync;

use GlpiPlugin\Pellissarisync\Protocol\Envelope;
use GlpiPlugin\Pellissarisync\Transport\Client;

/**
 * The poll: the only conversation between the two ends.
 *
 * The master is passive. It never opens a connection -- in practice it usually
 * cannot, since a customer network lets the agent out and nothing in. Everything
 * therefore rides on requests the agent makes:
 *
 *   agent  --POST sync-->  master     acks for what the master sent last time,
 *                                     plus the agent's own pending changes
 *   agent  <--response---  master     the answer to each of those changes, the
 *                                     master's pending changes, and its settings
 *
 * The POST is the way out, the HTTP response is the way back. The agent runs it
 * from its `poll` cron task (every 5 minutes by default, the interval being one of
 * the settings the master hands out) and repeats it within the same run while
 * there is anything left to move, so a burst of changes drains in one run instead
 * of one batch per interval.
 *
 * Acknowledgements travel on the NEXT request, not on the same one: the agent can
 * only answer an event after applying it, and it applies it after the response
 * arrived. A loop round therefore always follows a round that brought events, and
 * the last round of a run carries nothing but acks. Should that round be lost, the
 * master hands the events out again once their lease expires and the agent's inbox
 * turns them into no-ops that answer the cached result -- nothing is applied twice.
 */
final class Exchange
{
    /** Events per direction per round. */
    public const BATCH_LIMIT = 25;

    /**
     * Attachments travel inline; this bounds one round's body. Kept under PHP's
     * default post_max_size (8M), past which the master would receive nothing.
     */
    private const BATCH_BYTES = 4 * 1024 * 1024;

    private const MAX_ROUNDS = 10;
    private const TIMEOUT    = 60;

    public const DEFAULT_POLL_MINUTES = 5;

    // ------------------------------------------------------------- agent side

    /**
     * One synchronization run: as many rounds as needed to drain both queues.
     *
     * @return array{ok: bool, message: string, sent: int, received: int}
     */
    public static function run(): array
    {
        if (!Config::isAgent()) {
            return self::outcome(false, __('This instance is not configured as an agent.', 'pellissarisync'));
        }

        $master = Agent::master();
        if ($master === null) {
            return self::outcome(false, __('Connect to the master first.', 'pellissarisync'));
        }

        $acks     = [];
        $sent     = 0;
        $received = 0;

        for ($round = 1; $round <= self::MAX_ROUNDS; $round++) {
            $last = $round === self::MAX_ROUNDS;

            // On the last round nothing new is asked for: whatever it brought could
            // not be acknowledged before the run ends.
            $events = $last ? [] : Outbox::batch($master, self::BATCH_LIMIT, self::BATCH_BYTES, lease: false);

            $response = self::call($master, $acks, $events, $last ? 0 : self::BATCH_LIMIT);

            if (!$response['ok']) {
                Config::set([
                    'last_poll'       => Clock::now(),
                    'last_poll_error' => $response['error'],
                ]);

                Log::write('sync failed', ['round' => $round, 'error' => $response['error']]);

                return self::outcome(
                    false,
                    sprintf(__('Synchronization failed: %s', 'pellissarisync'), $response['error']),
                    $sent,
                    $received
                );
            }

            $acks = [];
            $body = $response['body'];

            self::applySettings($body);

            // Our own changes first: an answer may carry the id the master gave a
            // ticket we created, and the master's events below may address it.
            foreach ((array) ($body['results'] ?? []) as $result) {
                $result = (array) $result;

                if (Outbox::settle(
                    $master->getID(),
                    (string) ($result['key'] ?? ''),
                    (int) ($result['status'] ?? 0),
                    (array) ($result['body'] ?? []),
                    (string) ($result['error'] ?? ''),
                    leased: false
                )) {
                    $sent++;
                }
            }

            foreach ((array) ($body['events'] ?? []) as $event) {
                $event = (array) $event;
                $key   = (string) ($event['key'] ?? '');

                $applied = ApiServer::apply(
                    $master,
                    (string) ($event['action'] ?? ''),
                    (array) ($event['payload'] ?? []),
                    $key
                );

                $acks[] = [
                    'key'    => $key,
                    'status' => (int) $applied['status'],
                    'body'   => (array) $applied['body'],
                    'error'  => (string) ($applied['body']['error'] ?? ''),
                ];

                $received++;
            }

            $more = $acks !== []
                || !empty($body['more'])
                || Outbox::countDue($master->getID()) > 0;

            if (!$more) {
                break;
            }
        }

        Config::set([
            'last_poll'       => Clock::now(),
            'last_poll_error' => '',
        ]);

        return self::outcome(
            true,
            sprintf(
                __('Synchronized: %1$d change(s) sent, %2$d received.', 'pellissarisync'),
                $sent,
                $received
            ),
            $sent,
            $received
        );
    }

    /**
     * One round trip, with the answer authenticated.
     *
     * @return array{ok: bool, body: array, error: string}
     */
    private static function call(Agent $master, array $acks, array $events, int $limit): array
    {
        $result = Client::sendToAgent(
            $master,
            Envelope::ACTION_SYNC,
            [
                'acks'           => $acks,
                'events'         => $events,
                'limit'          => $limit,
                'glpi_version'   => GLPI_VERSION,
                'plugin_version' => PLUGIN_PELLISSARISYNC_VERSION,
            ],
            // Never served from the idempotency ledger: every sync is a new question.
            Envelope::idempotencyKey(Envelope::ACTION_SYNC, Config::uuid(), bin2hex(random_bytes(8))),
            self::TIMEOUT
        );

        $master->markContact((int) $result['status'], (string) $result['error']);

        if (!$result['ok']) {
            return ['ok' => false, 'body' => [], 'error' => $result['error'] !== '' ? $result['error'] : ('HTTP ' . $result['status'])];
        }

        // What comes back is about to be written into this GLPI, so it must provably
        // come from the master: signed with the secret only the two ends share.
        if (!Envelope::verify((string) $result['raw'], $master->getAuthSecret(), (string) $result['signature'])) {
            return ['ok' => false, 'body' => [], 'error' => 'the master response is not signed'];
        }

        return ['ok' => true, 'body' => (array) $result['body'], 'error' => ''];
    }

    /**
     * Stores what the master decided for this agent: its link state and the poll
     * interval, which is applied to the cron task right away.
     */
    public static function applySettings(array $body): void
    {
        $values = [];

        if (isset($body['link_status'])) {
            $values['handshake_status'] = (string) $body['link_status'];
        }

        $settings = (array) ($body['settings'] ?? []);

        if (isset($settings['poll_interval'])) {
            $minutes = self::clampMinutes((int) $settings['poll_interval']);

            if ($minutes !== (int) Config::get('poll_interval', self::DEFAULT_POLL_MINUTES)) {
                $values['poll_interval'] = $minutes;
            }

            Cron::ensurePollTask($minutes * MINUTE_TIMESTAMP);
        }

        if ($values !== []) {
            Config::set($values);
        }

        $master = Agent::master();
        if ($master !== null && isset($body['plugin_version'])) {
            $master->update([
                'id'                    => $master->getID(),
                'remote_glpi_version'   => (string) ($body['glpi_version'] ?? ''),
                'remote_plugin_version' => (string) $body['plugin_version'],
                '_no_history'           => true,
                '_no_message'           => true,
            ]);
        }
    }

    // ------------------------------------------------------------ master side

    /**
     * Answers one poll.
     *
     * Order matters: acks first, so that a ticket the agent just created for us has
     * its id recorded before anything else is decided; then the agent's changes,
     * whose side effects (actors added by the rules, for instance) are queued and
     * can already leave in this very response; then our own queue.
     */
    public static function serve(Agent $agent, array $request): array
    {
        $agents_id = $agent->getID();

        foreach ((array) ($request['acks'] ?? []) as $ack) {
            $ack = (array) $ack;

            Outbox::settle(
                $agents_id,
                (string) ($ack['key'] ?? ''),
                (int) ($ack['status'] ?? 0),
                (array) ($ack['body'] ?? []),
                (string) ($ack['error'] ?? ''),
                leased: true
            );
        }

        $results = [];

        foreach (array_slice((array) ($request['events'] ?? []), 0, self::BATCH_LIMIT) as $event) {
            $event = (array) $event;
            $key   = (string) ($event['key'] ?? '');

            $applied = ApiServer::apply(
                $agent,
                (string) ($event['action'] ?? ''),
                (array) ($event['payload'] ?? []),
                $key
            );

            $results[] = [
                'key'    => $key,
                'status' => (int) $applied['status'],
                'body'   => (array) $applied['body'],
                'error'  => (string) ($applied['body']['error'] ?? ''),
            ];
        }

        // Reloaded: applying may not change the row, but the ack handling and the
        // contact update above did, and the link state must be the current one.
        $agent->getFromDB($agents_id);

        if (isset($request['plugin_version'])) {
            $agent->update([
                'id'                    => $agents_id,
                'remote_glpi_version'   => (string) ($request['glpi_version'] ?? ''),
                'remote_plugin_version' => (string) $request['plugin_version'],
                '_no_history'           => true,
                '_no_message'           => true,
            ]);
        }

        $limit  = max(0, min(self::BATCH_LIMIT, (int) ($request['limit'] ?? self::BATCH_LIMIT)));
        $events = $agent->isUsable() ? Outbox::batch($agent, $limit, self::BATCH_BYTES, lease: true) : [];

        return [
            'ok'             => true,
            'master_uuid'    => Config::uuid(),
            'link_status'    => (string) $agent->fields['link_status'],
            'glpi_version'   => GLPI_VERSION,
            'plugin_version' => PLUGIN_PELLISSARISYNC_VERSION,
            'settings'       => self::settings(),
            'results'        => $results,
            'events'         => $events,
            'more'           => $limit > 0 && Outbox::countDue($agents_id) > 0,
        ];
    }

    /**
     * What the master tells every agent, on the handshake and on every poll.
     */
    public static function settings(): array
    {
        return [
            'poll_interval' => self::clampMinutes((int) Config::get('poll_interval', self::DEFAULT_POLL_MINUTES)),
        ];
    }

    public static function clampMinutes(int $minutes): int
    {
        // Between one minute and one day: below that is GLPI's cron resolution,
        // above it an agent would look dead.
        return max(1, min(1440, $minutes > 0 ? $minutes : self::DEFAULT_POLL_MINUTES));
    }

    /**
     * @return array{ok: bool, message: string, sent: int, received: int}
     */
    private static function outcome(bool $ok, string $message, int $sent = 0, int $received = 0): array
    {
        return ['ok' => $ok, 'message' => $message, 'sent' => $sent, 'received' => $received];
    }
}
