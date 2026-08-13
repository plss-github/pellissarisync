<?php

namespace GlpiPlugin\Pellissarisync;

use GlpiPlugin\Pellissarisync\Protocol\Envelope;
use GlpiPlugin\Pellissarisync\Sync\CostSync;
use GlpiPlugin\Pellissarisync\Sync\FollowupSync;
use GlpiPlugin\Pellissarisync\Sync\SolutionSync;
use GlpiPlugin\Pellissarisync\Sync\TaskSync;
use GlpiPlugin\Pellissarisync\Sync\ValidationSync;

/**
 * Records the ids the peer assigned to what we just pushed.
 *
 * Creation is asynchronous: the local link row is written before the event is
 * delivered, so the remote id is only known once the peer answers. Until then the
 * link carries 0 and later events for that item cannot be addressed -- which is
 * why this runs as soon as a delivery succeeds.
 */
final class Ack
{
    public static function handle(string $action, array $payload, array $body): void
    {
        match ($action) {
            Envelope::ACTION_TICKET_CREATE => self::ticketCreated($payload, $body),
            Envelope::ACTION_FUP_CREATE    => self::followupCreated($payload, $body),
            Envelope::ACTION_DOC_CREATE    => self::documentCreated($payload, $body),
            Envelope::ACTION_SOL_CREATE    => self::itemCreated(SolutionSync::ITEMTYPE, 'itilsolutions_id', $payload, $body),
            Envelope::ACTION_TASK_CREATE   => self::itemCreated(TaskSync::ITEMTYPE, 'tickettasks_id', $payload, $body),
            Envelope::ACTION_COST_CREATE   => self::itemCreated(CostSync::ITEMTYPE, 'ticketcosts_id', $payload, $body),
            Envelope::ACTION_VAL_CREATE    => self::itemCreated(ValidationSync::ITEMTYPE, 'ticketvalidations_id', $payload, $body),
            default                        => null,
        };
    }

    /**
     * Tasks, costs, approvals and solutions all link the same way; only the key the
     * peer answers with differs.
     *
     * An approval the peer could not create as an approval answers with
     * ticketvalidations_id = 0 (it became a followup there). The link stays at 0,
     * which is correct: there is no remote approval to address later.
     */
    private static function itemCreated(string $itemtype, string $responseKey, array $payload, array $body): void
    {
        $localId  = (int) ($payload['item']['remote_id'] ?? 0);
        $remoteId = (int) ($body[$responseKey] ?? 0);

        if ($localId <= 0 || $remoteId <= 0) {
            return;
        }

        $link = MirrorItem::forItem($itemtype, $localId);
        if ($link === null) {
            return;
        }

        $link->update([
            'id'              => $link->getID(),
            'remote_items_id' => $remoteId,
            '_no_history'     => true,
            '_no_message'     => true,
        ]);
    }

    private static function ticketCreated(array $payload, array $body): void
    {
        $localId  = (int) ($payload['ticket']['remote_id'] ?? 0);
        $remoteId = (int) ($body['tickets_id'] ?? 0);

        if ($localId <= 0 || $remoteId <= 0) {
            return;
        }

        $mirror = Mirror::forTicket($localId);

        // A tombstone stays a tombstone: the local ticket was purged, so there is no
        // mirror left to record an id for.
        if ($mirror === null || $mirror->isPurged()) {
            return;
        }

        $mirror->update([
            'id'                => $mirror->getID(),
            'remote_tickets_id' => $remoteId,
            'sync_state'        => Mirror::STATE_OK,
            '_no_history'       => true,
            '_no_message'       => true,
        ]);
    }

    private static function followupCreated(array $payload, array $body): void
    {
        $localId  = (int) ($payload['followup']['remote_id'] ?? 0);
        $remoteId = (int) ($body['itilfollowups_id'] ?? 0);

        if ($localId <= 0 || $remoteId <= 0) {
            return;
        }

        // The source matters: a solution and a followup can share the same local
        // id, since they live in different tables.
        $link = MirrorFollowup::forFollowup($localId, FollowupSync::sourceOf($payload['followup'] ?? []));
        if ($link === null) {
            return;
        }

        $link->update([
            'id'                  => $link->getID(),
            'remote_followups_id' => $remoteId,
            '_no_history'         => true,
            '_no_message'         => true,
        ]);
    }

    private static function documentCreated(array $payload, array $body): void
    {
        $localId  = (int) ($payload['document']['remote_id'] ?? 0);
        $remoteId = (int) ($body['documents_id'] ?? 0);
        $ticketId = (int) ($payload['ticket']['remote_id'] ?? 0);

        if ($localId <= 0 || $remoteId <= 0 || $ticketId <= 0) {
            return;
        }

        $mirror = Mirror::forTicket($ticketId);
        if ($mirror === null) {
            return;
        }

        $link = MirrorDocument::forDocument($mirror->getID(), $localId);
        if ($link === null) {
            return;
        }

        $link->update([
            'id'                  => $link->getID(),
            'remote_documents_id' => $remoteId,
            '_no_history'         => true,
            '_no_message'         => true,
        ]);
    }
}
