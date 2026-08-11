<?php

namespace GlpiPlugin\Pellissarisync;

/**
 * Inbound idempotency ledger.
 *
 * The per-request Guard cannot help when a delivery is retried in a later
 * request, which happens routinely after a network failure. Recording every
 * applied key is what makes a replay a no-op instead of a duplicate ticket or a
 * duplicate followup.
 */
final class Inbox
{
    public const TABLE = 'glpi_plugin_pellissarisync_inbox';

    public static function findResult(int $agents_id, string $idempotencyKey): ?array
    {
        global $DB;

        if ($idempotencyKey === '') {
            return null;
        }

        $rows = $DB->request([
            'FROM'  => self::TABLE,
            'WHERE' => [
                'plugin_pellissarisync_agents_id' => $agents_id,
                'idempotency_key'                 => $idempotencyKey,
            ],
        ]);

        foreach ($rows as $row) {
            $decoded = json_decode((string) $row['result'], true);

            return is_array($decoded) ? $decoded : [];
        }

        return null;
    }

    public static function record(int $agents_id, string $idempotencyKey, string $action, array $result): void
    {
        global $DB;

        if ($idempotencyKey === '') {
            return;
        }

        // Escaped here because GLPI 10's query builder interpolates strings as-is;
        // an apostrophe in a recorded result would otherwise break the INSERT and
        // the ledger would lose the entry that makes a replay a no-op.
        $DB->insert(self::TABLE, [
            'plugin_pellissarisync_agents_id' => $agents_id,
            'idempotency_key'                 => Compat::escapeForDb($idempotencyKey),
            'action'                          => Compat::escapeForDb($action),
            'received_date'                   => Clock::now(),
            'result'                          => Compat::escapeForDb(
                (string) json_encode($result, JSON_UNESCAPED_UNICODE)
            ),
        ]);
    }

    /**
     * Drops ledger entries older than the retention window; without this the
     * table grows without bound.
     */
    public static function purge(int $days = 30): int
    {
        global $DB;

        $cutoff = date(Clock::FORMAT, strtotime(Clock::now()) - ($days * DAY_TIMESTAMP));

        $DB->delete(self::TABLE, ['received_date' => ['<', $cutoff]]);

        return (int) $DB->affectedRows();
    }
}
