<?php

namespace GlpiPlugin\Pellissarisync;

use Glpi\Toolbox\Sanitizer;
use GuzzleHttp\Client;
use Session;
use Toolbox;

/**
 * Single place where GLPI 10 and GLPI 11 differ.
 *
 * The plugin supports both because customers still run 10.0.x while the support
 * desk runs 11. Every divergence is funnelled through here rather than sprinkled
 * as version checks across the code.
 *
 * The subtle one is rich text: GLPI 10 stores ticket/followup content
 * HTML-encoded (Sanitizer), GLPI 11 stores it raw. Mirroring between the two
 * without translating would either double-encode the text or show entities to
 * the customer.
 */
final class Compat
{
    public static function isLegacy(): bool
    {
        return version_compare(GLPI_VERSION, '11.0', '<');
    }

    /**
     * Value as really typed by the user, ready to travel to the peer.
     */
    public static function readRichText(?string $stored): string
    {
        $stored = (string) $stored;

        if ($stored === '' || !self::isLegacy()) {
            return $stored;
        }

        // Idempotent: Sanitizer skips values that are not encoded.
        return Sanitizer::unsanitize($stored);
    }

    /**
     * Turns values into what this GLPI expects to receive in add()/update().
     *
     * On GLPI 10 core itself does exactly this before creating a ticket from an
     * incoming e-mail (MailCollector: `$tkt = Sanitizer::sanitize($tkt)`).
     *
     * @param array<string, mixed> $input
     * @param string[]             $keys  keys holding user-visible text
     */
    public static function writeRichText(array $input, array $keys): array
    {
        if (!self::isLegacy()) {
            return $input;
        }

        foreach ($keys as $key) {
            if (isset($input[$key]) && is_string($input[$key])) {
                $input[$key] = Sanitizer::sanitize($input[$key]);
            }
        }

        return $input;
    }

    /**
     * HTML-escapes a fragment we build ourselves.
     */
    public static function escape(string $value): string
    {
        if (function_exists('htmlescape')) {
            return htmlescape($value);
        }

        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Runs a callback with rights checks disabled.
     *
     * GLPI 11 has a dedicated primitive; on GLPI 10 there is none, but neither
     * does CommonDBTM::add()/update() enforce rights there, so a plain call is
     * the equivalent.
     */
    public static function asSystem(callable $fn): mixed
    {
        if (method_exists(Session::class, 'callAsSystem')) {
            return Session::callAsSystem($fn);
        }

        return $fn();
    }

    /**
     * HTTP client, using GLPI's proxy-aware factory when it exists.
     */
    public static function httpClient(array $options): Client
    {
        if (method_exists(Toolbox::class, 'getGuzzleClient')) {
            return Toolbox::getGuzzleClient($options);
        }

        return new Client($options);
    }

    /**
     * Emits a JSON response from a legacy front script.
     *
     * GLPI 11 uses whatever Response the script returns; GLPI 10 has no such
     * mechanism, so the body is written out directly.
     */
    public static function respondJson(array $body, int $status): mixed
    {
        $json = (string) json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (!self::isLegacy() && class_exists(\Symfony\Component\HttpFoundation\JsonResponse::class)) {
            return new \Symfony\Component\HttpFoundation\JsonResponse($body, $status);
        }

        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json');
        }

        echo $json;
        exit;
    }

}
