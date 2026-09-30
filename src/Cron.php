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
            'poll' => [
                'description' => __('Synchronize with the master (agent only)', 'pellissarisync'),
            ],
            'cleanup' => [
                'description' => __('Purge the mirror idempotency ledger', 'pellissarisync'),
                'parameter'   => __('Retention in days', 'pellissarisync'),
            ],
            default => [],
        };
    }

    /**
     * The agent's poll. Registered on both ends, because the role is not known at
     * install time, and a no-op on the master -- which never initiates anything.
     */
    public static function cronPoll(CronTask $task): int
    {
        if (!Config::isAgent() || Agent::master() === null) {
            return 0;
        }

        $result = Exchange::run();
        $task->addVolume($result['sent'] + $result['received']);

        // Logged and reported as "nothing done", not as a negative value: that one
        // means "unfinished, run again now", and a master that is down would have
        // the task hammering it.
        if (!$result['ok']) {
            $task->log($result['message']);
            return 0;
        }

        return ($result['sent'] + $result['received']) > 0 ? 1 : 0;
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

    /**
     * Creates the poll task if missing and keeps its frequency on what the master
     * asked for.
     */
    public static function ensurePollTask(int $seconds): void
    {
        $task = new CronTask();

        if (!$task->getFromDBbyName(self::class, 'poll')) {
            CronTask::register(self::class, 'poll', $seconds, [
                'state' => CronTask::STATE_WAITING,
                'mode'  => CronTask::MODE_EXTERNAL,
            ]);

            return;
        }

        if ((int) $task->fields['frequency'] !== $seconds) {
            $task->update([
                'id'        => $task->getID(),
                'frequency' => $seconds,
            ]);
        }
    }
}
