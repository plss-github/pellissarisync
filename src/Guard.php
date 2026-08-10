<?php

namespace GlpiPlugin\Pellissarisync;

/**
 * Reentrancy guard.
 *
 * GLPI 11 has no "skip hooks" flag: CommonDBTM::add()/update() always call
 * Plugin::doHook(). Applying an inbound change therefore re-enters our own hooks,
 * and ITILFollowup::post_addItem() -> updateParentStatus() -> $ticket->update()
 * makes that indirect as well.
 *
 * Loop protection has three layers, of which this is the first:
 *   1. this per-request guard, keyed by itemtype:id;
 *   2. the Marker input key, recognised inside the hooks;
 *   3. the persisted Inbox idempotency key, which covers retries across requests.
 */
final class Guard
{
    /** @var array<string, true> */
    private static array $locks = [];

    public static function isLocked(string $itemtype, int $id): bool
    {
        return isset(self::$locks[self::key($itemtype, $id)]);
    }

    /**
     * Runs $fn with the item locked; nested calls for the same item return null.
     */
    public static function run(string $itemtype, int $id, callable $fn): mixed
    {
        $key = self::key($itemtype, $id);

        if (isset(self::$locks[$key])) {
            return null;
        }

        self::$locks[$key] = true;

        try {
            return $fn();
        } finally {
            unset(self::$locks[$key]);
        }
    }

    private static function key(string $itemtype, int $id): string
    {
        return $itemtype . ':' . $id;
    }
}
