<?php

namespace GlpiPlugin\Pellissarisync;

use CommonDBTM;

/**
 * Link between a local timeline item and its counterpart on the peer, for the
 * kinds that keep their own itemtype on both ends: tasks, costs, approvals and
 * solutions.
 *
 * Separate from MirrorFollowup because that table records a translation -- a
 * solution that arrived as a *followup* -- and its `itilfollowups_id` column
 * cannot honestly hold a cost or an approval id. Here local and remote itemtype
 * are always the same, so one `itemtype` column is enough.
 *
 * `origin` records which end created the item; only that end may propagate later
 * edits, exactly as with tickets and followups.
 */
class MirrorItem extends CommonDBTM
{
    public static $rightname = 'plugin_pellissarisync_mirror';

    public static function getTypeName($nb = 0)
    {
        return _n('Mirrored item', 'Mirrored items', $nb, 'pellissarisync');
    }

    public static function forItem(string $itemtype, int $items_id): ?self
    {
        $link = new self();

        $found = $link->getFromDBByCrit([
            'itemtype' => $itemtype,
            'items_id' => $items_id,
        ]);

        return $found ? $link : null;
    }

    public static function forRemoteItem(
        int $mirrors_id,
        string $itemtype,
        int $remote_items_id,
        string $origin
    ): ?self {
        $link = new self();

        $found = $link->getFromDBByCrit([
            'plugin_pellissarisync_mirrors_id' => $mirrors_id,
            'itemtype'                         => $itemtype,
            'remote_items_id'                  => $remote_items_id,
            'origin'                           => $origin,
        ]);

        return $found ? $link : null;
    }

    /**
     * The link to a peer item, whoever created it.
     *
     * Needed for approval answers: those travel from the end that ANSWERED, which
     * is not the end that created the approval, so the caller cannot know which
     * `origin` the row carries. Dropping the filter is safe -- within one mirror and
     * one itemtype, `remote_items_id` already identifies a single object on the peer:
     * the peer's item #N is either the copy of ours or the original of ours, never
     * both.
     */
    public static function forPeerItem(int $mirrors_id, string $itemtype, int $remote_items_id): ?self
    {
        if ($remote_items_id <= 0) {
            return null;
        }

        $link = new self();

        $found = $link->getFromDBByCrit([
            'plugin_pellissarisync_mirrors_id' => $mirrors_id,
            'itemtype'                         => $itemtype,
            'remote_items_id'                  => $remote_items_id,
        ]);

        return $found ? $link : null;
    }

    /**
     * Creates the link row for an item this end originated. `remote_items_id`
     * stays 0 until the delivery is acknowledged (see Ack).
     */
    public static function track(int $mirrors_id, string $itemtype, int $items_id): void
    {
        if (self::forItem($itemtype, $items_id) !== null) {
            return;
        }

        $link = new self();
        $link->add([
            'plugin_pellissarisync_mirrors_id' => $mirrors_id,
            'itemtype'                         => $itemtype,
            'items_id'                         => $items_id,
            'remote_items_id'                  => 0,
            'origin'                           => Config::role(),
        ]);
    }

    public function isContentOwner(): bool
    {
        return ($this->fields['origin'] ?? '') === Config::role();
    }

    public function getMirror(): ?Mirror
    {
        $mirror = new Mirror();
        $id     = (int) ($this->fields['plugin_pellissarisync_mirrors_id'] ?? 0);

        return $mirror->getFromDB($id) ? $mirror : null;
    }
}
