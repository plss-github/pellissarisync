<?php

namespace GlpiPlugin\Pellissarisync\Transport;

use GlpiPlugin\Pellissarisync\Agent;
use GlpiPlugin\Pellissarisync\Compat;
use GlpiPlugin\Pellissarisync\Config;
use GlpiPlugin\Pellissarisync\Protocol\Envelope;
use GuzzleHttp\Exception\GuzzleException;
use Throwable;

/**
 * Outbound HTTP calls to the peer instance.
 *
 * The client comes from Compat so that GLPI 11's proxy-aware factory is used
 * where it exists, falling back to a plain Guzzle client on GLPI 10.
 */
final class Client
{
    /** Endpoint path, relative to the peer's GLPI base URL. */
    private const ENDPOINT = '/plugins/pellissarisync/front/api.php';

    /**
     * The action travels as a query parameter rather than as PATH_INFO: GLPI 11
     * routes every request through public/index.php and rewrites PATH_INFO
     * accordingly, so a trailing path segment is not reliably readable from a
     * legacy plugin script.
     */
    public static function endpointFor(string $baseUrl, string $action): string
    {
        return rtrim($baseUrl, '/') . self::ENDPOINT . '?action=' . urlencode($action);
    }

    /**
     * @return array{ok: bool, status: int, body: array, error: string}
     */
    public static function send(
        string $baseUrl,
        string $action,
        array $payload,
        string $uuid,
        string $token,
        string $secret,
        string $idempotencyKey,
        int $timeout = 10
    ): array {
        if ($baseUrl === '') {
            return self::failure(0, 'peer URL is not configured');
        }

        $rawBody = Envelope::encode($payload);

        try {
            $client = Compat::httpClient([
                'connect_timeout' => 5,
                'timeout'         => $timeout,
                'http_errors'     => false,
            ]);

            $response = $client->request('POST', self::endpointFor($baseUrl, $action), [
                'body'    => $rawBody,
                'headers' => [
                    'Content-Type'          => 'application/json',
                    Envelope::HEADER_UUID   => $uuid,
                    Envelope::HEADER_TOKEN  => $token,
                    Envelope::HEADER_SIGN   => Envelope::sign($rawBody, $secret),
                    Envelope::HEADER_IDEM   => $idempotencyKey,
                ],
            ]);

            $status = $response->getStatusCode();
            $body   = Envelope::decode((string) $response->getBody());

            return [
                'ok'     => $status >= 200 && $status < 300,
                'status' => $status,
                'body'   => $body,
                'error'  => $status >= 200 && $status < 300
                    ? ''
                    : (string) ($body['error'] ?? ('HTTP ' . $status)),
            ];
        } catch (GuzzleException | Throwable $e) {
            return self::failure(0, $e->getMessage());
        }
    }

    /**
     * Sends on behalf of a registered peer, using its stored credentials.
     */
    public static function sendToAgent(Agent $agent, string $action, array $payload, string $idempotencyKey, int $timeout = 10): array
    {
        return self::send(
            $agent->getUrl(),
            $action,
            $payload,
            Config::uuid(),
            $agent->getAuthToken(),
            $agent->getAuthSecret(),
            $idempotencyKey,
            $timeout
        );
    }

    private static function failure(int $status, string $error): array
    {
        return ['ok' => false, 'status' => $status, 'body' => [], 'error' => $error];
    }
}
