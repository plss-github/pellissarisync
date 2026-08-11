<?php

namespace GlpiPlugin\Pellissarisync;

use DBmysql;
use Glpi\Toolbox\Sanitizer;
use GuzzleHttp\Client;
use Session;
use Ticket;
use Toolbox;
use User;

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
     * GLPI 11 has a dedicated primitive. GLPI 10 has none, and a plain call is NOT
     * equivalent: while add() really does not check rights, Ticket::update() does,
     * indirectly and destructively. CommonITILObject::handleTemplateFields() strips
     * every field but `id` when the caller has no UPDATE right on tickets, and then
     * refuses the update because only the id is left:
     *
     *     if (!Session::isCron() && !Session::haveRight(static::$rightname, UPDATE)) {
     *         $allowed_fields = ['id'];      // ... everything else dropped
     *         if (count($input) == 1) { return false; }
     *     }
     *
     * The stateless endpoint has no session, so every mirrored status and content
     * update failed silently on a GLPI 10 instance -- creations, followups and tasks
     * worked, which is what made it look like mirroring was fine. Core itself only
     * escapes through Session::isCron(), and that is not usable here: on GLPI 10
     * isCron() also demands the CLI or /cron.php, neither of which describes an
     * inbound HTTP request. So the rights are granted explicitly, for the duration
     * of the callback and nothing more.
     */
    public static function asSystem(callable $fn): mixed
    {
        if (method_exists(Session::class, 'callAsSystem')) {
            return Session::callAsSystem($fn);
        }

        $hadProfile = array_key_exists('glpiactiveprofile', $_SESSION);
        $previous   = $_SESSION['glpiactiveprofile'] ?? null;

        $profile = is_array($previous) ? $previous : [];
        $profile[Ticket::$rightname] = self::ticketRights();

        $_SESSION['glpiactiveprofile'] = $profile;

        try {
            return $fn();
        } finally {
            if ($hadProfile) {
                $_SESSION['glpiactiveprofile'] = $previous;
            } else {
                unset($_SESSION['glpiactiveprofile']);
            }
        }
    }

    /**
     * The ticket rights a mirrored write needs.
     *
     * Broader than UPDATE alone on purpose: handleTemplateFields() consults ASSIGN,
     * STEAL and OWN to decide which fields survive, and CHANGEPRIORITY guards the
     * urgency/impact recomputation. Granting only UPDATE would let the write through
     * but with fields quietly dropped, which is worse than failing.
     */
    private static function ticketRights(): int
    {
        return ALLSTANDARDRIGHT
            | Ticket::ASSIGN
            | Ticket::STEAL
            | Ticket::OWN
            | Ticket::CHANGEPRIORITY
            | Ticket::READALL;
    }

    /**
     * Runs a callback as a given local user.
     *
     * Needed for approval answers. Both versions gate the answer on the logged-in
     * user being the approver -- CommonITILValidation::canAnswer() on GLPI 11, a
     * `users_id_validate == Session::getLoginUserID()` comparison on GLPI 10 -- and
     * what they do when it fails is silently strip `status`, `comment_validation`
     * and `validation_date` from the input. The update then "succeeds" having
     * changed nothing, which is why a mirrored answer looked delivered and never
     * appeared.
     *
     * The approver is a real local user, matched by e-mail when the approval was
     * mirrored, and the answer genuinely is theirs -- it was given on the other
     * instance. Borrowing their identity for this one write states that, rather than
     * working around the check.
     */
    public static function asUser(int $users_id, callable $fn): mixed
    {
        if ($users_id <= 0) {
            return $fn();
        }

        $hadId   = array_key_exists('glpiID', $_SESSION);
        $previous = $_SESSION['glpiID'] ?? null;

        $_SESSION['glpiID'] = $users_id;

        try {
            return $fn();
        } finally {
            if ($hadId) {
                $_SESSION['glpiID'] = $previous;
            } else {
                unset($_SESSION['glpiID']);
            }
        }
    }

    /**
     * A string ready to be interpolated by the query builder.
     *
     * GLPI 11's DBmysql::quoteValue() escapes every string it receives. GLPI 10's
     * does NOT -- it wraps the value in quotes exactly as given, because that
     * version expects data to arrive already escaped (the legacy Sanitizer model):
     *
     *     // GLPI 10, DBmysql::quoteValue()
     *     $value = "'$value'";
     *
     * So a raw $DB->insert() of a JSON payload on GLPI 10 silently loses its
     * backslashes -- `class=\"x\"` is stored as `class="x"`, and the payload no
     * longer parses -- while an apostrophe in the text ends the SQL literal and the
     * INSERT fails outright. Both were invisible: the first surfaced as the peer
     * answering "unknown mirrored ticket", the second as an event that simply never
     * appeared in the queue.
     *
     * Only for the plugin's own raw $DB writes. Values going through
     * CommonDBTM::add()/update() must not be escaped here -- that path has its own
     * contract, see writeRichText().
     */
    public static function escapeForDb(string $value): string
    {
        /** @var DBmysql|null $DB */
        global $DB;

        if (!self::isLegacy() || $value === '') {
            return $value;
        }

        return $DB instanceof DBmysql && $DB->connected ? $DB->escape($value) : $value;
    }

    /**
     * Approval target, in the shape this GLPI expects on write.
     *
     * GLPI 11 models the target as a polymorphic pair (a user or a group) and only
     * keeps `users_id_validate` as a deprecated input that it rewrites -- passing
     * it there works but emits a deprecation notice. GLPI 10 has the column alone.
     *
     * @return array<string, mixed>
     */
    public static function validationTarget(int $users_id): array
    {
        if (self::isLegacy()) {
            return ['users_id_validate' => $users_id];
        }

        return [
            'itemtype_target' => User::class,
            'items_id_target' => $users_id,
        ];
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
