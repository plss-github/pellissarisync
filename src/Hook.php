<?php

namespace GlpiPlugin\Pellissarisync;

use Document;
use Document_Item;
use GlpiPlugin\Pellissarisync\Protocol\Envelope;
use GlpiPlugin\Pellissarisync\Protocol\Payload;
use GlpiPlugin\Pellissarisync\Sync\DocumentSync;
use ITILFollowup;
use ITILSolution;
use Throwable;
use Ticket;

/**
 * Outbound side of the mirror: turns local changes into queued events.
 *
 * The propagation rules live here:
 *  - title/description travel only from the end that created the ticket
 *    (Mirror::isContentOwner());
 *  - a followup travels only from the end that wrote it;
 *  - status travels both ways, guarded against echoing back what was just
 *    received;
 *  - private followups never travel, so internal notes cannot leak to the
 *    customer instance.
 *
 * Every callback is wrapped: a hook that throws would surface as a failure of the
 * user's own ticket save, which must never happen because of the mirror.
 */
final class Hook
{
    public static function onTicketAdd(Ticket $ticket): void
    {
        self::safely(static function () use ($ticket): void {
            if (Marker::isOwnWrite($ticket) || Config::role() === Config::ROLE_NONE) {
                return;
            }

            self::createAndPushMirror($ticket);
        });
    }

    public static function onTicketUpdate(Ticket $ticket): void
    {
        self::safely(static function () use ($ticket): void {
            if (Marker::isOwnWrite($ticket) || Config::role() === Config::ROLE_NONE) {
                return;
            }

            $tickets_id = (int) $ticket->getID();

            if (Guard::isLocked(Ticket::class, $tickets_id)) {
                return;
            }

            $mirror = Mirror::forTicket($tickets_id);

            if ($mirror === null) {
                // Covers the case of a ticket moved into the synchronized
                // category after creation.
                self::createAndPushMirror($ticket);
                return;
            }

            $agent = $mirror->getAgent();
            if ($agent === null || !$agent->isUsable()) {
                return;
            }

            $updates = $ticket->updates;

            $contentChanged = in_array('name', $updates, true) || in_array('content', $updates, true);

            if ($contentChanged) {
                if ($mirror->isContentOwner()) {
                    self::pushTicketContent($agent, $ticket, $mirror);
                } else {
                    // Data-protection rule: the other end owns this text, so the
                    // local edit stays local.
                    Log::write('content edit not propagated (not the owner)', [
                        'tickets_id' => $tickets_id,
                        'origin'     => $mirror->fields['origin'],
                        'local_role' => Config::role(),
                    ]);
                }
            }

            if (in_array('status', $updates, true)) {
                self::pushTicketStatus($agent, $ticket, $mirror);
            }
        });
    }

    public static function onFollowupAdd(ITILFollowup $followup): void
    {
        self::safely(static function () use ($followup): void {
            if (Marker::isOwnWrite($followup) || Config::role() === Config::ROLE_NONE) {
                return;
            }

            if ((string) ($followup->fields['itemtype'] ?? '') !== Ticket::class) {
                return;
            }

            // Internal notes must not reach the customer instance.
            if ((int) ($followup->fields['is_private'] ?? 0) === 1) {
                return;
            }

            $tickets_id = (int) $followup->fields['items_id'];

            if (Guard::isLocked(Ticket::class, $tickets_id)) {
                return;
            }

            $mirror = Mirror::forTicket($tickets_id);
            if ($mirror === null) {
                return;
            }

            $agent = $mirror->getAgent();
            if ($agent === null || !$agent->isUsable()) {
                return;
            }

            $followups_id = (int) $followup->getID();

            $link = new MirrorFollowup();
            $link->add([
                'itilfollowups_id'                 => $followups_id,
                'remote_followups_id'              => 0,
                'plugin_pellissarisync_mirrors_id' => $mirror->getID(),
                'origin'                           => Config::role(),
                'source_itemtype'                  => MirrorFollowup::SOURCE_FOLLOWUP,
            ]);

            // `remote_id` is always the SENDER's local id; the receiver resolves it
            // through its own remote_tickets_id / remote_followups_id columns.
            $payload = [
                'ticket'   => ['remote_id' => $tickets_id],
                'followup' => Payload::followup($followup),
            ];

            Outbox::push(
                $agent->getID(),
                Envelope::ACTION_FUP_CREATE,
                $payload,
                self::key(Envelope::ACTION_FUP_CREATE, $followups_id, $payload)
            );
        });
    }

    /**
     * A solution travels as a followup flagged `is_solution`, so the customer sees
     * which message was the resolution without the plugin having to create a real
     * solution on the peer (which would fight the explicit status propagation).
     */
    public static function onSolutionAdd(ITILSolution $solution): void
    {
        self::safely(static function () use ($solution): void {
            if (Marker::isOwnWrite($solution) || Config::role() === Config::ROLE_NONE) {
                return;
            }

            if ((string) ($solution->fields['itemtype'] ?? '') !== Ticket::class) {
                return;
            }

            $tickets_id = (int) $solution->fields['items_id'];

            if (Guard::isLocked(Ticket::class, $tickets_id)) {
                return;
            }

            $mirror = Mirror::forTicket($tickets_id);
            if ($mirror === null) {
                return;
            }

            $agent = $mirror->getAgent();
            if ($agent === null || !$agent->isUsable()) {
                return;
            }

            $solutions_id = (int) $solution->getID();

            $link = new MirrorFollowup();
            $link->add([
                'itilfollowups_id'                 => $solutions_id,
                'remote_followups_id'              => 0,
                'plugin_pellissarisync_mirrors_id' => $mirror->getID(),
                'origin'                           => Config::role(),
                'source_itemtype'                  => MirrorFollowup::SOURCE_SOLUTION,
            ]);

            $payload = [
                'ticket'   => ['remote_id' => $tickets_id],
                'followup' => Payload::solution($solution),
            ];

            Outbox::push(
                $agent->getID(),
                Envelope::ACTION_FUP_CREATE,
                $payload,
                self::key(Envelope::ACTION_FUP_CREATE, $solutions_id, $payload)
            );
        });
    }

    /**
     * Attachments must stay in sync, so every document linked to a mirrored
     * ticket is pushed with its bytes.
     */
    public static function onDocumentItemAdd(Document_Item $link): void
    {
        self::safely(static function () use ($link): void {
            if (Marker::isOwnWrite($link) || Config::role() === Config::ROLE_NONE) {
                return;
            }

            $tickets_id = self::ticketOfDocumentItem($link);
            if ($tickets_id <= 0) {
                return;
            }

            // Decisive for attachments: Document::post_addItem() builds the
            // Document_Item with a fresh input array, so our marker is lost and
            // isOwnWrite() cannot see that we are the ones writing. Without this
            // guard an inbound attachment is pushed straight back and the two
            // instances copy the file to each other forever.
            if (Guard::isLocked(Ticket::class, $tickets_id)) {
                return;
            }

            $mirror = Mirror::forTicket($tickets_id);
            if ($mirror === null) {
                return;
            }

            $agent = $mirror->getAgent();
            if ($agent === null || !$agent->isUsable()) {
                return;
            }

            $documents_id = (int) $link->fields['documents_id'];

            // Already known: this is the copy we just received from the peer.
            if (MirrorDocument::forDocument($mirror->getID(), $documents_id) !== null) {
                return;
            }

            $document = new Document();
            if (!$document->getFromDB($documents_id)) {
                return;
            }

            $packed = DocumentSync::pack($document);
            if ($packed === null) {
                return; // unreadable or too large; already logged
            }

            $record = new MirrorDocument();
            $record->add([
                'plugin_pellissarisync_mirrors_id' => $mirror->getID(),
                'documents_id'                     => $documents_id,
                'remote_documents_id'              => 0,
                'origin'                           => Config::role(),
            ]);

            $payload = [
                'ticket'   => ['remote_id' => $tickets_id],
                'document' => $packed,
            ];

            Outbox::push(
                $agent->getID(),
                Envelope::ACTION_DOC_CREATE,
                $payload,
                self::key(Envelope::ACTION_DOC_CREATE, $documents_id, ['sha1' => $packed['sha1sum']])
            );
        });
    }

    /**
     * A document can be linked to the ticket directly or through a timeline item.
     */
    private static function ticketOfDocumentItem(Document_Item $link): int
    {
        $itemtype = (string) ($link->fields['itemtype'] ?? '');
        $items_id = (int) ($link->fields['items_id'] ?? 0);

        if ($items_id <= 0) {
            return 0;
        }

        if ($itemtype === Ticket::class) {
            return $items_id;
        }

        if ($itemtype === ITILFollowup::class) {
            $followup = new ITILFollowup();

            if ($followup->getFromDB($items_id)
                && (string) $followup->fields['itemtype'] === Ticket::class
            ) {
                return (int) $followup->fields['items_id'];
            }
        }

        if ($itemtype === ITILSolution::class) {
            $solution = new ITILSolution();

            if ($solution->getFromDB($items_id)
                && (string) $solution->fields['itemtype'] === Ticket::class
            ) {
                return (int) $solution->fields['items_id'];
            }
        }

        return 0;
    }

    public static function onFollowupUpdate(ITILFollowup $followup): void
    {
        self::safely(static function () use ($followup): void {
            if (Marker::isOwnWrite($followup) || Config::role() === Config::ROLE_NONE) {
                return;
            }

            if (!in_array('content', $followup->updates, true)) {
                return;
            }

            $followups_id = (int) $followup->getID();

            if (Guard::isLocked(ITILFollowup::class, $followups_id)) {
                return;
            }

            $link = MirrorFollowup::forFollowup($followups_id);
            if ($link === null || !$link->isContentOwner()) {
                return;
            }

            $mirror = new Mirror();
            if (!$mirror->getFromDB((int) $link->fields['plugin_pellissarisync_mirrors_id'])) {
                return;
            }

            $agent = $mirror->getAgent();
            if ($agent === null || !$agent->isUsable()) {
                return;
            }

            $payload = [
                'ticket'   => ['remote_id' => (int) $mirror->fields['tickets_id']],
                'followup' => Payload::followup($followup),
            ];

            Outbox::push(
                $agent->getID(),
                Envelope::ACTION_FUP_UPDATE,
                $payload,
                self::key(Envelope::ACTION_FUP_UPDATE, $followups_id, $payload)
            );
        });
    }

    // --------------------------------------------------------------- outbound

    /**
     * Creates the local link row and queues the creation event, when the ticket
     * is eligible and a peer can be resolved.
     */
    private static function createAndPushMirror(Ticket $ticket): void
    {
        $tickets_id = (int) $ticket->getID();
        $category   = Config::syncCategoryId();

        if ($category <= 0 || (int) $ticket->fields['itilcategories_id'] !== $category) {
            return;
        }

        if (Mirror::forTicket($tickets_id) !== null) {
            return;
        }

        $entities_id = (int) $ticket->fields['entities_id'];

        $agent = Config::isMaster()
            ? Agent::findByEntity($entities_id)
            : Agent::master();

        if ($agent === null || !$agent->isUsable()) {
            Log::write('no usable peer for ticket', [
                'tickets_id'  => $tickets_id,
                'entities_id' => $entities_id,
                'role'        => Config::role(),
            ]);
            return;
        }

        // The customer name is the name of the entity the ticket was opened in
        // on this side; on the master it is the entity bound to the agent.
        $clientName = Config::isMaster()
            ? ($agent->fields['client_name'] ?: Payload::clientNameForEntity((int) $agent->fields['entities_id']))
            : Payload::clientNameForEntity($entities_id);

        $mirror = new Mirror();
        $mirror->add([
            'tickets_id'                      => $tickets_id,
            'remote_tickets_id'               => 0,
            'plugin_pellissarisync_agents_id' => $agent->getID(),
            'origin'                          => Config::role(),
            'client_name'                     => $clientName,
            'last_received_status'             => 0,
            'sync_state'                      => 'queued',
        ]);

        $payload = ['ticket' => Payload::ticket($ticket, $clientName)];

        Outbox::push(
            $agent->getID(),
            Envelope::ACTION_TICKET_CREATE,
            $payload,
            self::key(Envelope::ACTION_TICKET_CREATE, $tickets_id, [])
        );
    }

    private static function pushTicketContent(Agent $agent, Ticket $ticket, Mirror $mirror): void
    {
        $payload = [
            'ticket' => Payload::ticket($ticket, (string) $mirror->fields['client_name']),
        ];

        Outbox::push(
            $agent->getID(),
            Envelope::ACTION_TICKET_CONTENT,
            $payload,
            self::key(Envelope::ACTION_TICKET_CONTENT, (int) $ticket->getID(), $payload)
        );
    }

    private static function pushTicketStatus(Agent $agent, Ticket $ticket, Mirror $mirror): void
    {
        $status = (int) $ticket->fields['status'];

        // Do not bounce back a status the peer has just pushed to us.
        if ((int) $mirror->fields['last_received_status'] === $status) {
            return;
        }

        $payload = [
            'ticket' => [
                'remote_id' => (int) $ticket->getID(),
                'status'    => $status,
            ],
        ];

        Outbox::push(
            $agent->getID(),
            Envelope::ACTION_TICKET_STATUS,
            $payload,
            self::key(Envelope::ACTION_TICKET_STATUS, (int) $ticket->getID(), $payload)
        );
    }

    /**
     * Idempotency key. Including a digest of the payload means a genuine retry of
     * the same change collapses, while two distinct changes never collide -- which
     * a timestamp alone could not guarantee within the same second.
     */
    private static function key(string $action, int $localId, array $payload): string
    {
        return Envelope::idempotencyKey(
            $action,
            Config::uuid(),
            (string) $localId,
            $payload === [] ? '' : md5(Envelope::encode($payload))
        );
    }

    private static function safely(callable $fn): void
    {
        try {
            $fn();
        } catch (Throwable $e) {
            Log::write('hook error: ' . $e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
        }
    }
}
