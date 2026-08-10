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
     * Business rules and entity auto-assign are skipped as well, so that the
     * master's rules cannot silently re-route a mirrored ticket.
     */
    public static function ticketFlags(): array
    {
        return [
            self::KEY            => true,
            '_auto_import'       => true,
            '_skip_rules'        => true,
            '_skip_auto_assign'  => true,
            '_no_message'        => true,
        ];
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
