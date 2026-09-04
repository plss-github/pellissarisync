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

    /**
     * An approval that could not exist locally (no user owns the approver's
     * address) and was recorded as a followup instead. Its own source value keeps
     * it out of the followup id space: without it, remote approval #5 and remote
     * followup #5 on the same ticket would be treated as the same object.
     */
    public const SOURCE_VALIDATION = 'TicketValidation';

    /**
     * The answer to such an approval, which is a second timeline entry and needs a
     * slot of its own: keyed the same as the request, the answer would be taken for
     * a redelivery of it and dropped.
     */
    public const SOURCE_VALIDATION_ANSWER = 'TicketValidation.answer';

    /**
     * @return string[]
     */
    public static function sources(): array
    {
        return [
            self::SOURCE_FOLLOWUP,
            self::SOURCE_SOLUTION,
            self::SOURCE_VALIDATION,
            self::SOURCE_VALIDATION_ANSWER,
        ];
    }

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

    /**
     * The link of a local followup, whichever end wrote it -- what a purge of that
     * followup has to consult, since it only knows the local id.
     *
     * The `origin` split is not cosmetic. A row this end wrote for a SOLUTION keeps
     * the solution's id in `itilfollowups_id` (the legacy solution-as-followup path),
     * and a solution id can collide with a followup id, so our own rows are pinned to
     * the followup source. A row received from the peer always holds a real local
     * followup id whatever the item was on the other side, so any source counts.
     */
    public static function forLocalFollowup(int $itilfollowups_id): ?self
    {
        if ($itilfollowups_id <= 0) {
            return null;
        }

        $link = new self();

        $found = $link->getFromDBByCrit([
            'itilfollowups_id' => $itilfollowups_id,
            'OR'               => [
                ['origin' => Config::remoteRole()],
                ['origin' => Config::role(), 'source_itemtype' => self::SOURCE_FOLLOWUP],
            ],
        ]);

        return $found ? $link : null;
    }

    public function isContentOwner(): bool
    {
        return ($this->fields['origin'] ?? '') === Config::role();
    }
}
