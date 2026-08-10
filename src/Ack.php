<?php

namespace GlpiPlugin\Pellissarisync;

use GlpiPlugin\Pellissarisync\Protocol\Envelope;
use GlpiPlugin\Pellissarisync\Sync\FollowupSync;

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
            default                        => null,
        };
    }

    private static function ticketCreated(array $payload, array $body): void
    {
        $localId  = (int) ($payload['ticket']['remote_id'] ?? 0);
        $remoteId = (int) ($body['tickets_id'] ?? 0);

        if ($localId <= 0 || $remoteId <= 0) {
            return;
        }

        $mirror = Mirror::forTicket($localId);
        if ($mirror === null) {
            return;
        }

        $mirror->update([
            'id'                => $mirror->getID(),
            'remote_tickets_id' => $remoteId,
            'sync_state'        => 'ok',
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
