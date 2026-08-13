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
     * Locks an item for the rest of the request, with no scope to come back to.
     *
     * For a purge, where the lock cannot be a callback scope: core runs
     * cleanDBonPurge() while the row still exists, and the children it destroys
     * recompute the parent -- CommonITILActor::post_deleteFromDB() sets the ticket
     * back to INCOMING when the last assignee goes away, ignoring
     * _do_not_compute_status and carrying none of our markers. That update looked
     * like a genuine local change and was propagated, which reopened the peer's copy;
     * for a ticket with no mirror row it went further and pushed a CREATION for a
     * ticket in the middle of being destroyed. Locking from pre_item_purge makes the
     * whole cascade inert.
     */
    public static function lock(string $itemtype, int $id): void
    {
        self::$locks[self::key($itemtype, $id)] = true;
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

        self::lock($itemtype, $id);

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
