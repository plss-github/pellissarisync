<?php

namespace GlpiPlugin\Pellissarisync;

use CommonDBTM;

/**
 * Marks the writes this plugin performs itself, so its own hooks can recognise
 * and ignore them.
 *
 * Underscore-prefixed input keys are copied into $item->input before any hook
 * runs and are never persisted (CommonDBTM filters out keys starting with `_`),
 * which is exactly how core carries `_job`, `_trigger` and friends.
 */
final class Marker
{
    public const KEY = '_psync_apply';

    /**
     * Flags applied to every mirrored write.
     *
     * `_auto_import` is the canonical "automated importer" flag: it skips ticket
     * template mandatory-field validation and the users_id_recipient auto-set.
     *
     * `_skip_auto_assign` stays on regardless: that is the entity auto-assignment,
     * and a mirrored ticket's entity is the customer binding, not a guess.
     *
     * @param bool $withRules true lets the local business rules see the ticket. Used
     *                        by the master, where a customer ticket must be routed,
     *                        assigned and given an SLA like any other. Rules can
     *                        rewrite fields, so the caller is responsible for
     *                        re-checking whatever must not change -- see
     *                        TicketSync::protectEntity().
     */
    public static function ticketFlags(bool $withRules = false): array
    {
        $flags = [
            self::KEY            => true,
            '_auto_import'       => true,
            '_skip_auto_assign'  => true,
            '_no_message'        => true,
        ];

        if (!$withRules) {
            $flags['_skip_rules'] = true;
        }

        return $flags;
    }

    /**
     * Followups additionally must not touch the parent's status: status is
     * propagated explicitly, so letting a mirrored followup reopen or solve the
     * ticket would fight the explicit propagation.
     */
    public static function followupFlags(): array
    {
        return [
            self::KEY                 => true,
            '_do_not_compute_status'  => true,
            '_no_reopen'              => true,
            '_no_message'             => true,
        ];
    }

    /**
     * A task, a cost or an approval carries the same risk as a followup: core
     * recomputes the parent ticket after writing it.
     */
    public static function timelineFlags(): array
    {
        return self::followupFlags();
    }

    /**
     * Adding an actor can move the ticket to "assigned" and notify, neither of
     * which may be a side effect of mirroring.
     */
    public static function actorFlags(): array
    {
        return [
            self::KEY                => true,
            '_do_not_compute_status' => true,
            '_no_reopen'             => true,
            '_no_message'            => true,
            '_disablenotif'          => true,
        ];
    }

    /**
     * Document::post_addItem() creates the Document_Item link and can move the
     * parent ticket's status, so a mirrored attachment must not do either.
     */
    public static function documentFlags(): array
    {
        return [
            self::KEY                => true,
            '_do_not_compute_status' => true,
            '_no_reopen'             => true,
            '_no_message'            => true,
            '_disablenotif'          => true,
        ];
    }

    public static function isOwnWrite(CommonDBTM $item): bool
    {
        return !empty($item->input[self::KEY]);
    }
}
