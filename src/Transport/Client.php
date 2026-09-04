<?php

namespace GlpiPlugin\Pellissarisync\Transport;

use GlpiPlugin\Pellissarisync\Agent;
use GlpiPlugin\Pellissarisync\Compat;
use GlpiPlugin\Pellissarisync\Config;
use GlpiPlugin\Pellissarisync\Log;
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
    /**
     * Endpoint paths, relative to the peer's GLPI base URL.
     *
     * Two of them, because the path depends on WHERE THE PEER installed the
     * plugin and that cannot be read from here. GLPI serves the `plugins`
     * directory at /plugins/... and the marketplace directory at
     * /marketplace/..., and this end has no way of knowing which one the other
     * end chose -- Plugin::getWebDir() only ever describes the local install.
     *
     * Measured, not assumed: a GLPI 11 peer routes both paths to the plugin
     * whichever directory it sits in, so the first entry answers there in every
     * case. A GLPI 10 peer serves the files directly and therefore answers only
     * on the path they really live in, replying 404 on the other one -- which is
     * exactly the break this list exists to absorb, since the plugin's own
     * Makefile installs into the marketplace directory.
     */
    private const ENDPOINTS = [
        '/plugins/pellissarisync/front/api.php',
        '/marketplace/pellissarisync/front/api.php',
    ];

    /**
     * The path that last answered, per peer base URL.
     *
     * Request-scoped on purpose: it spares an outbox flush one wasted probe per
     * event without adding a column that would then have to be migrated, guessed
     * at install time, and kept correct when a peer is moved between directories.
     *
     * @var array<string, string>
     */
    private static array $resolved = [];

    /**
     * The action travels as a query parameter rather than as PATH_INFO: GLPI 11
     * routes every request through public/index.php and rewrites PATH_INFO
     * accordingly, so a trailing path segment is not reliably readable from a
     * legacy plugin script.
     */
    public static function endpointFor(string $baseUrl, string $action, ?string $path = null): string
    {
        $path ??= self::pathsFor(self::key($baseUrl))[0];

        return rtrim($baseUrl, '/') . $path . '?action=' . urlencode($action);
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

        $key    = self::key($baseUrl);
        $result = self::failure(0, 'no endpoint attempted');

        foreach (self::pathsFor($key) as $path) {
            $result = self::attempt(
                $baseUrl,
                $path,
                $action,
                $payload,
                $uuid,
                $token,
                $secret,
                $idempotencyKey,
                $timeout
            );

            // 404 is the one answer that means "not at this path". No plugin code
            // ran on the peer, so nothing can have been applied and trying the
            // other layout is free of consequence. Every other outcome -- an
            // answer from the plugin, an auth refusal, a transport failure -- is
            // about the request itself and is returned as it is.
            if ($result['status'] === 404) {
                continue;
            }

            // Only a real HTTP answer proves the path; a transport failure
            // (status 0) says nothing about it and must not be recorded.
            if ($result['status'] > 0 && (self::$resolved[$key] ?? null) !== $path) {
                self::$resolved[$key] = $path;

                if ($path !== self::ENDPOINTS[0]) {
                    Log::write('peer endpoint resolved to an alternate path', [
                        'peer' => $key,
                        'path' => $path,
                    ]);
                }
            }

            return $result;
        }

        return $result;
    }

    /**
     * The paths to try, the one already known to answer first.
     *
     * @return string[]
     */
    private static function pathsFor(string $key): array
    {
        $known = self::$resolved[$key] ?? null;

        if ($known === null) {
            return self::ENDPOINTS;
        }

        return array_merge([$known], array_values(array_diff(self::ENDPOINTS, [$known])));
    }

    private static function key(string $baseUrl): string
    {
        return rtrim($baseUrl, '/');
    }

    /**
     * One HTTP attempt against one endpoint path.
     *
     * @return array{ok: bool, status: int, body: array, error: string}
     */
    private static function attempt(
        string $baseUrl,
        string $path,
        string $action,
        array $payload,
        string $uuid,
        string $token,
        string $secret,
        string $idempotencyKey,
        int $timeout
    ): array {
        $rawBody = Envelope::encode($payload);

        try {
            $client = Compat::httpClient([
                'connect_timeout' => 5,
                'timeout'         => $timeout,
                'http_errors'     => false,
            ]);

            $response = $client->request('POST', self::endpointFor($baseUrl, $action, $path), [
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
