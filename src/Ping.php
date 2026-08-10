<?php

namespace GlpiPlugin\Pellissarisync;

use GlpiPlugin\Pellissarisync\Protocol\Envelope;
use GlpiPlugin\Pellissarisync\Transport\Client;

/**
 * Connectivity check against a peer.
 */
final class Ping
{
    /**
     * @return array{ok: bool, message: string, status: int, body: array}
     */
    public static function run(Agent $agent): array
    {
        if ($agent->getUrl() === '') {
            return [
                'ok'      => false,
                'message' => __('This agent has not reported a URL yet.', 'pellissarisync'),
                'status'  => 0,
                'body'    => [],
            ];
        }

        // A fresh key every time: a ping must never be served from the
        // idempotency ledger.
        $result = Client::sendToAgent(
            $agent,
            Envelope::ACTION_PING,
            ['at' => Clock::now()],
            Envelope::idempotencyKey(Envelope::ACTION_PING, Config::uuid(), bin2hex(random_bytes(8))),
            8
        );

        $agent->markContact((int) $result['status'], (string) $result['error']);

        if (!$result['ok']) {
            return [
                'ok'      => false,
                'message' => sprintf(
                    __('Ping failed: %s', 'pellissarisync'),
                    $result['error'] !== '' ? $result['error'] : ('HTTP ' . $result['status'])
                ),
                'status'  => (int) $result['status'],
                'body'    => (array) $result['body'],
            ];
        }

        $body = (array) $result['body'];

        // When an agent pings the master, the reply carries the agent's own link
        // state; without this the agent screen would stay on "pending" forever
        // after the master finally links it.
        if (Config::isAgent() && isset($body['your_link_status'])) {
            Config::set([
                'handshake_status' => (string) $body['your_link_status'],
                'last_handshake_error' => '',
            ]);
        }

        return [
            'ok'      => true,
            'message' => sprintf(
                __('Ping OK - remote role %s, GLPI %s, plugin %s, %d mirrored tickets.', 'pellissarisync'),
                (string) ($body['role'] ?? '?'),
                (string) ($body['glpi_version'] ?? '?'),
                (string) ($body['plugin_version'] ?? '?'),
                (int) ($body['mirrored'] ?? 0)
            ),
            'status'  => (int) $result['status'],
            'body'    => $body,
        ];
    }
}
