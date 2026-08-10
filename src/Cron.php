<?php

namespace GlpiPlugin\Pellissarisync;

use CommonDBTM;
use CronTask;

/**
 * Scheduled tasks.
 *
 * CronTask resolves the callback as `<itemtype>::cron<Name>`, so the method names
 * below must keep matching the names registered at install time.
 */
class Cron extends CommonDBTM
{
    protected static $notable = true;

    public static function getTypeName($nb = 0)
    {
        return 'Pellissari Sync';
    }

    public static function cronInfo($name)
    {
        return match ($name) {
            'outbox' => [
                'description' => __('Retry pending mirror deliveries', 'pellissarisync'),
                'parameter'   => __('Maximum number of deliveries per run', 'pellissarisync'),
            ],
            'cleanup' => [
                'description' => __('Purge the mirror idempotency ledger', 'pellissarisync'),
                'parameter'   => __('Retention in days', 'pellissarisync'),
            ],
            default => [],
        };
    }

    public static function cronOutbox(CronTask $task): int
    {
        $limit = (int) ($task->fields['param'] ?? 50);
        if ($limit <= 0) {
            $limit = 50;
        }

        $delivered = Outbox::flush($limit);
        $task->addVolume($delivered);

        return $delivered > 0 ? 1 : 0;
    }

    public static function cronCleanup(CronTask $task): int
    {
        $days = (int) ($task->fields['param'] ?? 30);
        if ($days <= 0) {
            $days = 30;
        }

        $purged = Inbox::purge($days);
        $task->addVolume($purged);

        return $purged > 0 ? 1 : 0;
    }
}
