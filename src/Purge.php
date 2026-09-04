<?php

namespace GlpiPlugin\Pellissarisync;

use CommonDBTM;
use GlpiPlugin\Pellissarisync\Protocol\Envelope;
use ITILFollowup;
use ITILSolution;
use Session;
use Throwable;
use Ticket;
use TicketCost;
use TicketTask;
use TicketValidation;

/**
 * Removal of a timeline item: followup, task, cost, approval, solution.
 *
 * None of these has a bin. `glpi_itilfollowups` and friends carry no `is_deleted`
 * column, so the delete button in the timeline is a straight purge -- which is why
 * this lives on the purge hooks and not on item_delete like the ticket does.
 *
 * One rule decides everything, the same one that governs title, description and
 * edits: whoever wrote the item owns it.
 *
 *  - the OWNER purging its item purges the copy on the peer;
 *  - the other end may NOT purge its copy, because that copy is a mirror and the
 *    original stays where it was written. The attempt is REFUSED rather than
 *    silently propagated or silently dropped: CommonDBTM::delete() aborts and
 *    returns false when a pre_item_purge hook leaves a non-array in `input`, so
 *    the item simply stays in the timeline and the user is told why.
 *
 * The ticket is deliberately not part of this. A ticket that came from the peer
 * can be purged locally without asking: it leaves a tombstone (see Mirror::purge())
 * and nothing travels, because recreating a ticket would mean duplicating it.
 */
final class Purge
{
    /**
     * Fields read while the row still existed, kept only for the case where this
     * GLPI ignores the veto -- see restore().
     *
     * @var array<string, array<string, mixed>>
     */
    private static array $snapshots = [];

    /**
     * Tickets whose own purge is under way, told to us by Hook::onTicketPrePurge().
     *
     * The ticket lock cannot answer this question. It is taken for a sibling item
     * purge too, and inferring "the ticket is being destroyed" from it made the
     * second and every later item of a massive action look like part of a cascade,
     * which silently stopped their removal from travelling.
     *
     * @var array<int, true>
     */
    private static array $purging = [];

    /**
     * The itemtypes handled here, mapped to the action that carries their removal.
     */
    private const ACTIONS = [
        ITILFollowup::class     => Envelope::ACTION_FUP_DELETE,
        ITILSolution::class     => Envelope::ACTION_SOL_DELETE,
        TicketTask::class       => Envelope::ACTION_TASK_DELETE,
        TicketCost::class       => Envelope::ACTION_COST_DELETE,
        TicketValidation::class => Envelope::ACTION_VAL_DELETE,
    ];

    /**
     * Text fields that need the GLPI 10 / GLPI 11 rich-text translation when they
     * travel back into add().
     */
    private const RICH_TEXT = ['content', 'comment_submission', 'comment_validation'];

    /**
     * Before the row is destroyed: refuse the purge, or clear the way for it.
     *
     * The refusal can only happen here -- it is the one moment core still asks -- and
     * the fields are only trustworthy here too, which is why the snapshot is taken
     * now even though it is read later.
     */
    public static function before(CommonDBTM $item, string $itemtype): void
    {
        if (!isset(self::ACTIONS[$itemtype]) || Config::role() === Config::ROLE_NONE) {
            return;
        }

        // A purge this plugin is performing itself, applying what the peer asked for.
        if (Marker::isOwnWrite($item)) {
            return;
        }

        $items_id   = (int) $item->getID();
        $tickets_id = self::ticketOf($item, $itemtype);

        if ($items_id <= 0 || $tickets_id <= 0) {
            return;
        }

        // The ticket itself is being destroyed and is taking its children with it.
        if (self::isTicketBeingPurged($tickets_id)) {
            return;
        }

        $link = self::link($itemtype, $items_id);

        // Never mirrored: a private note, a task written before the mirror existed,
        // anything purely local. Not our business.
        if ($link === null) {
            return;
        }

        if (!self::isOwner($link)) {
            self::refuse($item, $itemtype, $items_id, $tickets_id);

            return;
        }

        // Ours to remove, but nothing is queued yet: the peer is told in after(), once
        // the row is really gone. Queueing here would mean a purge that core goes on
        // to refuse -- pre_deleteItem(), a failing DELETE -- had already destroyed the
        // peer's copy.
        //
        // What has to happen now is the lock. Core recomputes the parent ticket while
        // destroying its children, with its own input and none of our markers, which
        // is the same thing that used to reopen the peer's copy during a ticket purge.
        // It is never released: it also has to cover post_purgeItem(), whose position
        // relative to the item_purge hook is not the same across GLPI versions.
        Guard::lock(Ticket::class, $tickets_id);
    }

    /**
     * After the row is gone: tell the peer and close the link, or put the item back.
     */
    public static function after(CommonDBTM $item, string $itemtype): void
    {
        if (!isset(self::ACTIONS[$itemtype]) || Config::role() === Config::ROLE_NONE) {
            return;
        }

        $items_id   = (int) $item->getID();
        $tickets_id = self::ticketOf($item, $itemtype);
        $key        = self::key($itemtype, $items_id);

        // The ticket is being destroyed and is taking this item with it. Checked
        // before anything else because a snapshot left over from a refusal earlier in
        // the same request is now meaningless: putting the item back would hang a
        // timeline entry off a ticket that no longer exists.
        if ($tickets_id > 0 && self::isTicketBeingPurged($tickets_id)) {
            unset(self::$snapshots[$key]);

            return;
        }

        // We asked for this purge to be cancelled and it went through anyway, so this
        // GLPI does not honour the veto. The item is put back, because this end was
        // never entitled to destroy it and the peer will not send it again.
        if (isset(self::$snapshots[$key])) {
            $fields = self::$snapshots[$key];
            unset(self::$snapshots[$key]);

            self::restore($itemtype, $items_id, $fields);

            return;
        }

        // Our own purge, applying the peer's request; the sync that asked for it
        // closes the link itself.
        if (Marker::isOwnWrite($item)) {
            return;
        }

        $link = self::link($itemtype, $items_id);

        if ($link === null) {
            return;
        }

        // The row is really gone, so the peer can be told to drop its copy. Wrapped
        // on its own because the link still has to be closed below even if queueing
        // the event fails.
        if ($tickets_id > 0 && self::isOwner($link)) {
            try {
                self::propagate($itemtype, $items_id, $tickets_id, $link);
            } catch (Throwable $e) {
                Log::write('removal NOT queued for the peer: ' . $e->getMessage(), [
                    'itemtype' => $itemtype,
                    'items_id' => $items_id,
                ]);
            }
        }

        // The local id is dead. Its link row must not outlive it: a row pointing at
        // an id that no longer exists makes every later event the peer sends about
        // that item unresolvable, which the outbox retries and finally buries as
        // dead -- silently, and for good.
        self::dropLink($link);
    }

    // --------------------------------------------------------------- refusal

    /**
     * Cancels the purge of a mirror copy.
     *
     * `$item->input = false` is the documented way for a plugin to abort a write:
     * CommonDBTM::delete() checks `is_array($this->input)` right after the
     * pre_item_purge hook and returns false when it no longer is. It is assigned
     * first, before anything that could throw, so the refusal cannot be lost to a
     * failure while building the message.
     */
    private static function refuse(CommonDBTM $item, string $itemtype, int $items_id, int $tickets_id): void
    {
        $item->input = false;

        // Only ever read if the veto above turns out not to be honoured.
        self::$snapshots[self::key($itemtype, $items_id)] = $item->fields;

        Log::write('purge refused: this item is a mirror of what the peer wrote', [
            'itemtype'   => $itemtype,
            'items_id'   => $items_id,
            'tickets_id' => $tickets_id,
            'role'       => Config::role(),
        ]);

        self::warn(
            __('Este item veio da outra instância e não pode ser excluído aqui: exclua-o na origem e a exclusão chega sozinha.', 'pellissarisync')
        );
    }

    /**
     * Tells the user why the deletion did not happen.
     *
     * Best effort by design: the refusal must not depend on a message getting
     * through, and the signature of addMessageAfterRedirect() is not identical
     * across the supported GLPI versions.
     */
    private static function warn(string $message): void
    {
        if (!method_exists(Session::class, 'addMessageAfterRedirect')) {
            return;
        }

        try {
            Session::addMessageAfterRedirect($message, false, defined('ERROR') ? ERROR : 0);
        } catch (Throwable $e) {
            try {
                Session::addMessageAfterRedirect($message);
            } catch (Throwable $ignored) {
                Log::write('purge refusal message not shown: ' . $e->getMessage());
            }
        }
    }

    // ---------------------------------------------------------------- outbound

    /**
     * Queues the removal of an item this end wrote, so the peer drops its copy.
     *
     * Resolves the peer through the link row rather than through Hook::context():
     * that helper refuses a locked ticket, and by the second item of a massive
     * action the ticket is locked by our own doing.
     */
    private static function propagate(string $itemtype, int $items_id, int $tickets_id, CommonDBTM $link): void
    {
        $mirror = self::mirrorOf($link);

        if ($mirror === null || $mirror->isPurged()) {
            return;
        }

        // Same rule as every other change: a ticket in the bin propagates nothing.
        $ticket = new Ticket();

        if (!$ticket->getFromDB($tickets_id) || (int) $ticket->fields['is_deleted'] === 1) {
            return;
        }

        $agent = $mirror->getAgent();

        if ($agent === null) {
            return;
        }

        $action = self::ACTIONS[$itemtype];

        // Followups keep their own payload slot, as they do on create and update.
        $slot = $itemtype === ITILFollowup::class ? 'followup' : 'item';

        $payload = [
            'ticket' => ['remote_id' => $tickets_id],
            $slot    => ['remote_id' => $items_id],
        ];

        Outbox::push(
            $agent->getID(),
            $action,
            $payload,
            // The ids alone identify the event: unlike an edit, a removal has no
            // content that could make two of them differ.
            Envelope::idempotencyKey($action, Config::uuid(), (string) $items_id)
        );

        Log::write('item purged locally; removal queued for the peer', [
            'itemtype'   => $itemtype,
            'items_id'   => $items_id,
            'tickets_id' => $tickets_id,
        ]);
    }

    // ---------------------------------------------------------------- inbound

    /**
     * The other half: destroys the local copy of an item the peer purged, and closes
     * the link with it.
     *
     * Uniform across the itemtypes on purpose. Unlike a create or an edit, a removal
     * carries no fields to map, so there is nothing per-type left for the Sync
     * classes to do -- which is why this one lives here and they have no delete().
     *
     * Marked and guarded so our own hooks recognise the write. Without the marker,
     * before() would take it for a local decision and REFUSE it: this end is by
     * definition not the owner of an item the peer wrote.
     */
    public static function applyRemoval(CommonDBTM $link, string $itemtype): bool
    {
        if (!isset(self::ACTIONS[$itemtype])) {
            return false;
        }

        $items_id = (int) ($link->fields[self::column($itemtype)] ?? 0);

        if ($items_id <= 0) {
            return false;
        }

        $item = new $itemtype();

        // Already gone: the removal has nothing left to do. Reporting failure would
        // only have the peer retry the event until the outbox buries it as dead.
        if ($item->getFromDB($items_id)) {
            $removed = Guard::run(
                $itemtype,
                $items_id,
                static fn(): bool => (bool) $item->delete(
                    array_merge(Marker::timelineFlags(), ['id' => $items_id]),
                    1
                )
            );

            if (!$removed) {
                return false;
            }
        }

        self::dropLink($link);

        return true;
    }

    // ----------------------------------------------------------------- restore

    /**
     * Puts a mirror copy back after a veto that was not honoured.
     *
     * A safety net, not a normal path: on every GLPI this plugin supports the
     * refusal in before() stops the purge and this never runs.
     *
     * The item comes back with a NEW local id, so the link row is repointed at it.
     * An approval cannot be restored in full -- core forces a fresh approval to
     * WAITING and stamps its own submission date -- so the answer, if there was one,
     * is stated as lost in the log rather than quietly invented.
     *
     * @param array<string, mixed> $fields
     */
    private static function restore(string $itemtype, int $items_id, array $fields): void
    {
        $link = self::link($itemtype, $items_id);

        $input = $fields;
        unset($input['id'], $input['date_mod']);

        // Straight out of the database, so already stored-encoded on GLPI 10: read it
        // back to what the user typed before handing it to add(), which encodes again.
        foreach (self::RICH_TEXT as $field) {
            if (isset($input[$field]) && is_string($input[$field])) {
                $input[$field] = Compat::readRichText($input[$field]);
            }
        }

        $input = Compat::writeRichText(array_merge($input, Marker::timelineFlags()), self::RICH_TEXT);

        if ($itemtype === ITILSolution::class) {
            // Same reasons as SolutionSync::create(): do not solve the ticket again
            // and do not spread onto linked tickets.
            $input['_linked_ticket']       = true;
            $input['_disable_auto_assign'] = true;
        }

        $item  = new $itemtype();
        $newId = (int) $item->add($input);

        if ($newId <= 0) {
            Log::write('MIRROR COPY LOST: the purge veto was ignored and the item could not be put back', [
                'itemtype' => $itemtype,
                'items_id' => $items_id,
            ]);

            // Nothing was restored, so the link must not survive either -- it would
            // point at a dead id and bury every later event about it.
            if ($link !== null) {
                self::dropLink($link);
            }

            return;
        }

        if ($link !== null) {
            self::repoint($link, $itemtype, $newId);
        }

        Log::write('purge veto ignored by this GLPI; the mirror copy was recreated', [
            'itemtype' => $itemtype,
            'was'      => $items_id,
            'now'      => $newId,
            'partial'  => $itemtype === TicketValidation::class ? 'the approval answer could not be restored' : '',
        ]);
    }

    // ----------------------------------------------------------------- helpers

    /**
     * The link row of a local item, whichever end wrote it.
     */
    private static function link(string $itemtype, int $items_id): ?CommonDBTM
    {
        if ($itemtype === ITILFollowup::class) {
            return MirrorFollowup::forLocalFollowup($items_id);
        }

        return MirrorItem::forItem($itemtype, $items_id);
    }

    private static function isOwner(CommonDBTM $link): bool
    {
        return ($link->fields['origin'] ?? '') === Config::role();
    }

    private static function mirrorOf(CommonDBTM $link): ?Mirror
    {
        $mirror = new Mirror();
        $id     = (int) ($link->fields['plugin_pellissarisync_mirrors_id'] ?? 0);

        return $mirror->getFromDB($id) ? $mirror : null;
    }

    private static function dropLink(CommonDBTM $link): void
    {
        $link->delete(['id' => $link->getID(), '_no_history' => true, '_no_message' => true], 1);
    }

    /**
     * The column holding the LOCAL id, which differs between the two link tables:
     * MirrorFollowup records a translation and names its column after the followup,
     * MirrorItem keeps the itemtype beside a generic items_id.
     */
    private static function column(string $itemtype): string
    {
        return $itemtype === ITILFollowup::class ? 'itilfollowups_id' : 'items_id';
    }

    private static function repoint(CommonDBTM $link, string $itemtype, int $newId): void
    {
        $link->update([
            'id'                        => $link->getID(),
            self::column($itemtype)     => $newId,
            '_no_history' => true,
            '_no_message' => true,
        ]);
    }

    /**
     * The ticket a timeline item belongs to, or 0 when it hangs off something else
     * -- a followup on a Change or a Problem is not this plugin's business.
     */
    private static function ticketOf(CommonDBTM $item, string $itemtype): int
    {
        return match ($itemtype) {
            ITILFollowup::class, ITILSolution::class => (string) ($item->fields['itemtype'] ?? '') === Ticket::class
                ? (int) ($item->fields['items_id'] ?? 0)
                : 0,
            TicketTask::class, TicketCost::class, TicketValidation::class => (int) ($item->fields['tickets_id'] ?? 0),
            default => 0,
        };
    }

    /**
     * Declares that a ticket is being destroyed, so the purge of every child it
     * cascades over is left alone: nothing travels, nothing is refused, nothing is
     * put back, and Mirror::purge() closes all the link rows in one go.
     */
    public static function ticketPurgeStarted(int $tickets_id): void
    {
        if ($tickets_id > 0) {
            self::$purging[$tickets_id] = true;
        }
    }

    private static function isTicketBeingPurged(int $tickets_id): bool
    {
        return isset(self::$purging[$tickets_id]);
    }

    private static function key(string $itemtype, int $items_id): string
    {
        return $itemtype . ':' . $items_id;
    }
}
