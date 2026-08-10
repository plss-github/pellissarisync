<?php

namespace GlpiPlugin\Pellissarisync;

use GlpiPlugin\Pellissarisync\Protocol\Envelope;
use GlpiPlugin\Pellissarisync\Protocol\Payload;
use GlpiPlugin\Pellissarisync\Transport\Client;
use RuntimeException;

/**
 * Trust bootstrap.
 *
 * The master generates an enrollment token at install time; an administrator
 * pastes it into the agent together with the master URL. The handshake proves
 * possession of that token (it is the HMAC key of the request, never a bearer
 * value in a header) and exchanges it for credentials dedicated to that one
 * agent -- so no shared global secret is ever used for day-to-day traffic.
 */
final class Handshake
{
    /**
     * Master side: registers or refreshes the calling agent.
     *
     * @return array{status: int, body: array}
     */
    public static function serve(string $rawBody, string $uuid, string $signature): array
    {
        if (!Config::isMaster()) {
            return ['status' => 400, 'body' => ['error' => 'this instance is not a master']];
        }

        if ($uuid === '') {
            return ['status' => 400, 'body' => ['error' => 'missing instance uuid']];
        }

        if (!Envelope::verify($rawBody, Config::enrollmentToken(), $signature)) {
            Log::write('handshake rejected: bad enrollment signature', ['uuid' => $uuid]);

            return ['status' => 401, 'body' => ['error' => 'invalid enrollment token']];
        }

        $payload = Envelope::decode($rawBody);

        $agent = Agent::findByUuid($uuid) ?? new Agent();

        $values = [
            'uuid'                  => $uuid,
            'name'                  => (string) ($payload['name'] ?? $uuid),
            'url'                   => rtrim((string) ($payload['url'] ?? ''), '/'),
            'client_name'           => (string) ($payload['client_name'] ?? ''),
            'remote_glpi_version'   => (string) ($payload['glpi_version'] ?? ''),
            'remote_plugin_version' => (string) ($payload['plugin_version'] ?? ''),
            'is_master'             => 0,
            'is_active'             => 1,
            'last_contact'          => Clock::now(),
            'last_error'            => '',
        ];

        if ($agent->isNewItem()) {
            // A brand new agent is not linked until an administrator binds it to a
            // customer entity: without that, mirrored tickets would have nowhere
            // safe to land.
            $values['entities_id'] = 0;
            $values['link_status'] = Agent::STATUS_PENDING;

            $id = (int) $agent->add($values);
            if ($id <= 0) {
                return ['status' => 500, 'body' => ['error' => 'could not register agent']];
            }
            $agent->getFromDB($id);
        } else {
            if (($agent->fields['link_status'] ?? '') === Agent::STATUS_REVOKED) {
                return ['status' => 403, 'body' => ['error' => 'this agent has been revoked']];
            }

            $values['id'] = $agent->getID();
            $agent->update($values);
            $agent->getFromDB($agent->getID());
        }

        $credentials = $agent->issueCredentials();
        $agent->getFromDB($agent->getID());

        Log::write('handshake accepted', ['uuid' => $uuid, 'agent' => $agent->getID()]);

        return [
            'status' => 200,
            'body'   => [
                'ok'             => true,
                'master_uuid'    => Config::uuid(),
                'token'          => $credentials['token'],
                'secret'         => $credentials['secret'],
                'link_status'    => (string) $agent->fields['link_status'],
                'glpi_version'   => GLPI_VERSION,
                'plugin_version' => PLUGIN_PELLISSARISYNC_VERSION,
            ],
        ];
    }

    /**
     * Agent side: contacts the master and stores the issued credentials.
     *
     * @return array{ok: bool, message: string}
     */
    public static function connect(): array
    {
        global $CFG_GLPI;

        if (!Config::isAgent()) {
            return ['ok' => false, 'message' => __('This instance is not configured as an agent.', 'pellissarisync')];
        }

        $masterUrl = Config::masterUrl();
        $token     = (string) Config::get('enrollment_token', '');

        if ($masterUrl === '' || $token === '') {
            return [
                'ok'      => false,
                'message' => __('Set the master URL and the enrollment token first.', 'pellissarisync'),
            ];
        }

        $ownUrl = (string) Config::get('own_url', '');
        if ($ownUrl === '') {
            $ownUrl = (string) ($CFG_GLPI['url_base'] ?? '');
        }

        $payload = [
            'name'           => (string) ($CFG_GLPI['name'] ?? '') ?: Payload::clientNameForEntity(0),
            'url'            => rtrim($ownUrl, '/'),
            // On the agent the entity name IS the customer name, and that is what
            // composes the [Customer] prefix on the master.
            'client_name'    => Payload::clientNameForEntity(0),
            'glpi_version'   => GLPI_VERSION,
            'plugin_version' => PLUGIN_PELLISSARISYNC_VERSION,
        ];

        $rawBody = Envelope::encode($payload);

        // The enrollment token doubles as the HMAC key for this one request.
        $result = Client::send(
            $masterUrl,
            Envelope::ACTION_HANDSHAKE,
            $payload,
            Config::uuid(),
            '',
            $token,
            Envelope::idempotencyKey(Envelope::ACTION_HANDSHAKE, Config::uuid(), (string) time())
        );

        if (!$result['ok']) {
            Config::set([
                'handshake_status'     => 'error',
                'last_handshake_error' => (string) $result['error'],
            ]);

            return ['ok' => false, 'message' => (string) $result['error']];
        }

        $body = (array) $result['body'];

        $masterUuid = (string) ($body['master_uuid'] ?? '');
        $authToken  = (string) ($body['token'] ?? '');
        $authSecret = (string) ($body['secret'] ?? '');

        if ($masterUuid === '' || $authToken === '' || $authSecret === '') {
            return ['ok' => false, 'message' => __('The master returned an incomplete response.', 'pellissarisync')];
        }

        self::storeMaster($masterUuid, $masterUrl, $body);

        Config::set([
            'handshake_status'     => (string) ($body['link_status'] ?? Agent::STATUS_PENDING),
            'last_handshake'       => Clock::now(),
            'last_handshake_error' => '',
        ]);

        return ['ok' => true, 'message' => __('Connected to the master.', 'pellissarisync')];
    }

    private static function storeMaster(string $masterUuid, string $masterUrl, array $body): void
    {
        $agent = Agent::master() ?? Agent::findByUuid($masterUuid) ?? new Agent();

        $values = [
            'uuid'                  => $masterUuid,
            'name'                  => __('Pellissari support desk', 'pellissarisync'),
            'url'                   => rtrim($masterUrl, '/'),
            'is_master'             => 1,
            'is_active'             => 1,
            // Locally, mirrored tickets from the master land in the root entity.
            'entities_id'           => 0,
            'client_name'           => Payload::clientNameForEntity(0),
            'link_status'           => Agent::STATUS_LINKED,
            'auth_token'            => Agent::encryptSecret((string) $body['token']),
            'auth_secret'           => Agent::encryptSecret((string) $body['secret']),
            'remote_glpi_version'   => (string) ($body['glpi_version'] ?? ''),
            'remote_plugin_version' => (string) ($body['plugin_version'] ?? ''),
            'last_contact'          => Clock::now(),
            'last_error'            => '',
        ];

        if ($agent->isNewItem()) {
            if ((int) $agent->add($values) <= 0) {
                throw new RuntimeException('could not store master credentials');
            }
            return;
        }

        $values['id'] = $agent->getID();
        $agent->update($values);
    }
}
