<?php

namespace GlpiPlugin\Pellissarisync\Protocol;

/**
 * Wire format shared by both ends.
 *
 * The signature covers the raw request body, so it must always be computed and
 * verified against the exact bytes sent -- never against a re-encoded array.
 */
final class Envelope
{
    public const HEADER_UUID  = 'X-PSync-Uuid';
    public const HEADER_TOKEN = 'X-PSync-Token';
    public const HEADER_SIGN  = 'X-PSync-Sign';
    public const HEADER_IDEM  = 'X-PSync-Idem';

    public const ACTION_HANDSHAKE      = 'handshake';
    public const ACTION_PING           = 'ping';
    public const ACTION_TICKET_CREATE  = 'ticket.create';
    public const ACTION_TICKET_CONTENT = 'ticket.content.update';
    public const ACTION_TICKET_STATUS  = 'ticket.status.update';
    public const ACTION_TICKET_ACTORS  = 'ticket.actors.update';
    public const ACTION_TICKET_DELETE  = 'ticket.delete';
    public const ACTION_TICKET_RESTORE = 'ticket.restore';
    public const ACTION_FUP_CREATE     = 'followup.create';
    public const ACTION_FUP_UPDATE     = 'followup.update';
    public const ACTION_DOC_CREATE     = 'document.create';
    public const ACTION_SOL_CREATE     = 'solution.create';
    public const ACTION_TASK_CREATE    = 'task.create';
    public const ACTION_TASK_UPDATE    = 'task.update';
    public const ACTION_COST_CREATE    = 'cost.create';
    public const ACTION_COST_UPDATE    = 'cost.update';
    public const ACTION_VAL_CREATE     = 'validation.create';
    public const ACTION_VAL_UPDATE     = 'validation.update';

    /**
     * ACTION_FUP_CREATE still carries solutions coming from a peer that predates
     * ACTION_SOL_CREATE (they arrived flagged `is_solution`), so it must keep
     * being accepted even though this end no longer emits solutions that way.
     */
    public static function actions(): array
    {
        return [
            self::ACTION_HANDSHAKE,
            self::ACTION_PING,
            self::ACTION_TICKET_CREATE,
            self::ACTION_TICKET_CONTENT,
            self::ACTION_TICKET_STATUS,
            self::ACTION_TICKET_ACTORS,
            self::ACTION_TICKET_DELETE,
            self::ACTION_TICKET_RESTORE,
            self::ACTION_FUP_CREATE,
            self::ACTION_FUP_UPDATE,
            self::ACTION_DOC_CREATE,
            self::ACTION_SOL_CREATE,
            self::ACTION_TASK_CREATE,
            self::ACTION_TASK_UPDATE,
            self::ACTION_COST_CREATE,
            self::ACTION_COST_UPDATE,
            self::ACTION_VAL_CREATE,
            self::ACTION_VAL_UPDATE,
        ];
    }

    public static function sign(string $rawBody, string $secret): string
    {
        return hash_hmac('sha256', $rawBody, $secret);
    }

    public static function verify(string $rawBody, string $secret, string $signature): bool
    {
        if ($secret === '' || $signature === '') {
            return false;
        }

        return hash_equals(self::sign($rawBody, $secret), $signature);
    }

    /**
     * Deterministic key so a retried delivery is applied at most once.
     */
    public static function idempotencyKey(string $action, string ...$parts): string
    {
        return substr(hash('sha256', $action . '|' . implode('|', $parts)), 0, 48);
    }

    public static function encode(array $payload): string
    {
        return (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public static function decode(string $rawBody): array
    {
        $decoded = json_decode($rawBody, true);

        return is_array($decoded) ? $decoded : [];
    }
}
