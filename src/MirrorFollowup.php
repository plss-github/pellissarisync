<?php

namespace GlpiPlugin\Pellissarisync;

use CommonDBTM;

/**
 * Link between a local timeline entry and its counterpart on the peer.
 *
 * `origin` records which end wrote it; only that end may propagate later edits.
 *
 * `source_itemtype` distinguishes a followup from a solution: both are pushed as
 * followups to the peer, but their local ids come from different tables, so
 * without it solution #5 and followup #5 from the same origin would be treated
 * as the same object.
 */
class MirrorFollowup extends CommonDBTM
{
    public static $rightname = 'plugin_pellissarisync_mirror';

    public const SOURCE_FOLLOWUP = 'ITILFollowup';
    public const SOURCE_SOLUTION = 'ITILSolution';

    public static function getTypeName($nb = 0)
    {
        return _n('Mirrored followup', 'Mirrored followups', $nb, 'pellissarisync');
    }

    public static function forFollowup(int $itilfollowups_id, string $source = self::SOURCE_FOLLOWUP): ?self
    {
        $link = new self();

        $found = $link->getFromDBByCrit([
            'itilfollowups_id' => $itilfollowups_id,
            'source_itemtype'  => $source,
        ]);

        return $found ? $link : null;
    }

    public static function forRemoteFollowup(
        int $mirrors_id,
        int $remote_followups_id,
        string $origin,
        string $source = self::SOURCE_FOLLOWUP
    ): ?self {
        $link = new self();

        $found = $link->getFromDBByCrit([
            'plugin_pellissarisync_mirrors_id' => $mirrors_id,
            'remote_followups_id'              => $remote_followups_id,
            'origin'                           => $origin,
            'source_itemtype'                  => $source,
        ]);

        return $found ? $link : null;
    }

    public function isContentOwner(): bool
    {
        return ($this->fields['origin'] ?? '') === Config::role();
    }
}
