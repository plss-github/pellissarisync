<?php

namespace GlpiPlugin\Pellissarisync;

use Document;
use Document_Item;
use GlpiPlugin\Pellissarisync\Protocol\Envelope;
use GlpiPlugin\Pellissarisync\Protocol\Payload;
use GlpiPlugin\Pellissarisync\Sync\CostSync;
use GlpiPlugin\Pellissarisync\Sync\DocumentSync;
use GlpiPlugin\Pellissarisync\Sync\SolutionSync;
use GlpiPlugin\Pellissarisync\Sync\TaskSync;
use GlpiPlugin\Pellissarisync\Sync\ValidationSync;
use ITILFollowup;
use ITILSolution;
use Throwable;
use Ticket;
use Ticket_User;
use TicketCost;
use TicketTask;
use TicketValidation;

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

            $updates = $ticket->updates;

            if (Mirror::forTicket($tickets_id) === null) {
                // The case this covers is a ticket MOVED into the synchronized
                // category after creation, so that is exactly what is required
                // here. Reacting to any update of any unmirrored ticket in the
                // category also caught the updates core itself performs -- the
                // status recompute inside a purge cascade, cronCloseTicket() on
                // every solved ticket -- and each of those emitted a creation for a
                // ticket nobody had asked to mirror, some of them already in the bin.
                if (in_array('itilcategories_id', $updates, true)) {
                    self::createAndPushMirror($ticket);
                }

                return;
            }

            $context = self::context($tickets_id);
            if ($context === null) {
                return;
            }

            [$mirror, $agent] = $context;

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

            $context = self::context($tickets_id);
            if ($context === null) {
                return;
            }

            [$mirror, $agent] = $context;

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
     * A solution travels as a solution: the peer creates a real ITILSolution, so it
     * lands in the Solution tab instead of reading as one more update in the
     * timeline.
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

            self::pushItemCreate(
                SolutionSync::ITEMTYPE,
                (int) $solution->getID(),
                (int) $solution->fields['items_id'],
                Envelope::ACTION_SOL_CREATE,
                Payload::solution($solution)
            );
        });
    }

    /**
     * Tasks carry the time spent, which is what makes the work visible on the other
     * end. Private tasks never travel, same rule as private notes.
     */
    public static function onTaskAdd(TicketTask $task): void
    {
        self::safely(static function () use ($task): void {
            if (Marker::isOwnWrite($task) || Config::role() === Config::ROLE_NONE) {
                return;
            }

            if ((int) ($task->fields['is_private'] ?? 0) === 1) {
                return;
            }

            self::pushItemCreate(
                TaskSync::ITEMTYPE,
                (int) $task->getID(),
                (int) $task->fields['tickets_id'],
                Envelope::ACTION_TASK_CREATE,
                Payload::task($task)
            );
        });
    }

    public static function onTaskUpdate(TicketTask $task): void
    {
        self::safely(static function () use ($task): void {
            if (Marker::isOwnWrite($task) || Config::role() === Config::ROLE_NONE) {
                return;
            }

            // A task made private after the fact must stop being mirrored; the copy
            // already on the peer is emptied rather than left behind as a leak.
            if ((int) ($task->fields['is_private'] ?? 0) === 1) {
                self::pushItemUpdate(
                    TaskSync::ITEMTYPE,
                    (int) $task->getID(),
                    Envelope::ACTION_TASK_UPDATE,
                    array_merge(Payload::task($task), ['content' => '', 'actiontime' => 0])
                );

                return;
            }

            self::pushItemUpdate(
                TaskSync::ITEMTYPE,
                (int) $task->getID(),
                Envelope::ACTION_TASK_UPDATE,
                Payload::task($task)
            );
        });
    }

    public static function onCostAdd(TicketCost $cost): void
    {
        self::safely(static function () use ($cost): void {
            if (Marker::isOwnWrite($cost) || Config::role() === Config::ROLE_NONE) {
                return;
            }

            self::pushItemCreate(
                CostSync::ITEMTYPE,
                (int) $cost->getID(),
                (int) $cost->fields['tickets_id'],
                Envelope::ACTION_COST_CREATE,
                Payload::cost($cost)
            );
        });
    }

    public static function onCostUpdate(TicketCost $cost): void
    {
        self::safely(static function () use ($cost): void {
            if (Marker::isOwnWrite($cost) || Config::role() === Config::ROLE_NONE) {
                return;
            }

            self::pushItemUpdate(
                CostSync::ITEMTYPE,
                (int) $cost->getID(),
                Envelope::ACTION_COST_UPDATE,
                Payload::cost($cost)
            );
        });
    }

    public static function onValidationAdd(TicketValidation $validation): void
    {
        self::safely(static function () use ($validation): void {
            if (Marker::isOwnWrite($validation) || Config::role() === Config::ROLE_NONE) {
                return;
            }

            self::pushItemCreate(
                ValidationSync::ITEMTYPE,
                (int) $validation->getID(),
                (int) $validation->fields['tickets_id'],
                Envelope::ACTION_VAL_CREATE,
                Payload::validation($validation)
            );
        });
    }

    /**
     * The answer to an approval. Unlike other items this one travels from the end
     * that ANSWERED, which is not necessarily the end that asked -- so ownership is
     * not checked here.
     */
    public static function onValidationUpdate(TicketValidation $validation): void
    {
        self::safely(static function () use ($validation): void {
            if (Marker::isOwnWrite($validation) || Config::role() === Config::ROLE_NONE) {
                return;
            }

            if (!in_array('status', $validation->updates, true)
                && !in_array('comment_validation', $validation->updates, true)
            ) {
                return;
            }

            self::pushItemUpdate(
                ValidationSync::ITEMTYPE,
                (int) $validation->getID(),
                Envelope::ACTION_VAL_UPDATE,
                Payload::validation($validation),
                requireOwnership: false
            );
        });
    }

    /**
     * A ticket sent to the bin on one end goes to the bin on the other.
     */
    public static function onTicketDelete(Ticket $ticket): void
    {
        self::safely(static function () use ($ticket): void {
            self::pushTicketLifecycle($ticket, Envelope::ACTION_TICKET_DELETE);
        });
    }

    public static function onTicketRestore(Ticket $ticket): void
    {
        self::safely(static function () use ($ticket): void {
            self::pushTicketLifecycle($ticket, Envelope::ACTION_TICKET_RESTORE);
        });
    }

    /**
     * Makes the whole purge cascade inert.
     *
     * Purge is not mirrored -- destroying data on the peer is not something a mirror
     * may do -- but "not mirrored" has to mean handled, and it did not. Core runs
     * cleanDBonPurge() while the ticket row still exists, and the children it
     * destroys recompute the parent: CommonITILActor::post_deleteFromDB() sets the
     * ticket back to INCOMING once the last assignee is gone, with core's own input
     * and outside any of our writes. Our update hook saw a genuine status change and
     * propagated it, which reopened the peer's copy as "new"; and when the ticket had
     * no mirror row, the same update fell through to createAndPushMirror() and pushed
     * a CREATION for a ticket in the middle of being destroyed.
     *
     * The lock is taken here and never released: it has to cover the cascade and the
     * item_purge that follows, and the request ends right after.
     */
    public static function onTicketPrePurge(Ticket $ticket): void
    {
        self::safely(static function () use ($ticket): void {
            Guard::lock(Ticket::class, (int) $ticket->getID());
        });
    }

    /**
     * Closes the local link once the ticket is gone.
     *
     * The peer keeps its copy and goes on addressing this ticket, so the mirror row
     * becomes a tombstone instead of disappearing -- see Mirror::purge() and the
     * no-op answer in ApiServer.
     */
    public static function onTicketPurge(Ticket $ticket): void
    {
        self::safely(static function () use ($ticket): void {
            $tickets_id = (int) $ticket->getID();

            $mirror = Mirror::forTicket($tickets_id);
            if ($mirror === null || $mirror->isPurged()) {
                return;
            }

            $mirror->purge();

            Log::write('mirrored ticket purged locally; link closed, nothing propagated', [
                'tickets_id' => $tickets_id,
                'remote_id'  => (int) $mirror->fields['remote_tickets_id'],
                'agent'      => (int) $mirror->fields['plugin_pellissarisync_agents_id'],
            ]);
        });
    }

    /**
     * Requesters and technicians, pushed as a snapshot whenever one is added.
     *
     * A snapshot rather than a single actor because the receiving end applies the
     * set additively: it is cheap, it is idempotent, and it repairs an actor that a
     * failed earlier delivery never carried.
     */
    public static function onActorAdd(Ticket_User $link): void
    {
        self::safely(static function () use ($link): void {
            if (Marker::isOwnWrite($link) || Config::role() === Config::ROLE_NONE) {
                return;
            }

            self::pushActors((int) ($link->fields['tickets_id'] ?? 0));
        });
    }

    /**
     * Sends the current actor set of a mirrored ticket to the peer.
     *
     * Public because the inbound side calls it too: when the master applies a
     * customer ticket, its own rules and its configured assignees add the technician
     * in charge, and the customer is entitled to see who is handling their ticket.
     * Only users travel -- a group has no counterpart on the other instance.
     *
     * @param bool $now false while applying an inbound change, where the peer does
     *                  not know our ticket id yet -- see Outbox::defer().
     */
    public static function pushActors(int $tickets_id, bool $now = true): void
    {
        $context = self::context($tickets_id);
        if ($context === null) {
            return;
        }

        [, $agent] = $context;

        $ticket = new Ticket();
        if (!$ticket->getFromDB($tickets_id)) {
            return;
        }

        $payload = [
            'ticket' => [
                'remote_id' => $tickets_id,
                'actors'    => Payload::actors($ticket),
            ],
        ];

        $key = self::key(Envelope::ACTION_TICKET_ACTORS, $tickets_id, $payload);

        $now
            ? Outbox::push($agent->getID(), Envelope::ACTION_TICKET_ACTORS, $payload, $key)
            : Outbox::defer($agent->getID(), Envelope::ACTION_TICKET_ACTORS, $payload, $key);
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
            // isOwnWrite() cannot see that we are the ones writing. Without the
            // reentrancy guard context() checks, an inbound attachment is pushed
            // straight back and the two instances copy the file to each other forever.
            $context = self::context($tickets_id);
            if ($context === null) {
                return;
            }

            [$mirror, $agent] = $context;

            self::pushDocument($agent, $mirror, (int) $link->fields['documents_id'], $tickets_id);
        });
    }

    /**
     * Reads a local document and queues it with its bytes.
     */
    private static function pushDocument(Agent $agent, Mirror $mirror, int $documents_id, int $tickets_id): void
    {
        if ($documents_id <= 0) {
            return;
        }

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
            // The ticket belongs in the key: GLPI deduplicates documents by sha1, so
            // the same file attached to two mirrored tickets is ONE glpi_documents
            // row shared by both. Keyed on the document alone, the second ticket's
            // push collapsed into the first one's key and the attachment silently
            // never travelled.
            self::key(Envelope::ACTION_DOC_CREATE, $documents_id, [
                'ticket' => $tickets_id,
                'sha1'   => $packed['sha1sum'],
            ])
        );
    }

    /**
     * Attachments that were already linked when the mirror was created.
     *
     * A file attached while opening the ticket is stored by Ticket::post_addItem(),
     * which core runs BEFORE the item_add hook -- so onDocumentItemAdd() fired at a
     * moment when no mirror existed yet and skipped the file. Nothing later comes
     * back for it, which is why the ticket arrived at the peer without its
     * attachment while every attachment added afterwards worked. Sweeping here, once
     * the mirror exists, is what closes that window.
     */
    private static function pushExistingDocuments(Agent $agent, Mirror $mirror, int $tickets_id): void
    {
        global $DB;

        $rows = $DB->request([
            'SELECT' => ['documents_id'],
            'FROM'   => Document_Item::getTable(),
            'WHERE'  => ['itemtype' => Ticket::class, 'items_id' => $tickets_id],
        ]);

        foreach ($rows as $row) {
            self::pushDocument($agent, $mirror, (int) $row['documents_id'], $tickets_id);
        }
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

            $context = self::context((int) $mirror->fields['tickets_id']);
            if ($context === null) {
                return;
            }

            [, $agent] = $context;

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

    // ---------------------------------------------------------------- helpers

    /**
     * The mirror and the peer for a ticket, or null when nothing must travel: no
     * mirror, no peer, a ticket in the bin, or a change this plugin is itself
     * applying.
     *
     * A ticket in the bin is silent on purpose. Deletion and restore travel through
     * their own hooks, so nothing legitimate needs to be pushed while a ticket sits
     * there -- and everything that reached this point from a deleted ticket came from
     * core rearranging it (a purge cascade, an automatic action), never from someone
     * deciding to change it.
     *
     * The peer only has to EXIST here; whether it is usable is Outbox::deliver()'s
     * call. A peer still pending its customer entity is the documented state of a
     * fresh install, and dropping the event at this point lost it for good.
     *
     * @param bool $evenInBin true only for the bin and restore events themselves:
     *                        core writes is_deleted BEFORE firing item_delete, so by
     *                        the time we are told about it the ticket is already
     *                        there, and refusing it would be refusing the very event
     *                        that has to travel.
     *
     * @return array{0: Mirror, 1: Agent}|null
     */
    private static function context(int $tickets_id, bool $evenInBin = false): ?array
    {
        if ($tickets_id <= 0 || Guard::isLocked(Ticket::class, $tickets_id)) {
            return null;
        }

        $mirror = Mirror::forTicket($tickets_id);
        if ($mirror === null || $mirror->isPurged()) {
            return null;
        }

        if (!$evenInBin && self::isInBin($tickets_id)) {
            return null;
        }

        $agent = $mirror->getAgent();
        if ($agent === null) {
            return null;
        }

        return [$mirror, $agent];
    }

    private static function isInBin(int $tickets_id): bool
    {
        $ticket = new Ticket();

        return $ticket->getFromDB($tickets_id) && (int) $ticket->fields['is_deleted'] === 1;
    }

    /**
     * Queues the creation of a timeline item that keeps its itemtype on the peer.
     */
    private static function pushItemCreate(
        string $itemtype,
        int $localId,
        int $tickets_id,
        string $action,
        array $item
    ): void {
        $context = self::context($tickets_id);
        if ($context === null || $localId <= 0) {
            return;
        }

        [$mirror, $agent] = $context;

        // Written before the delivery: the peer's id is only known once it answers,
        // and Ack fills it in then.
        MirrorItem::track($mirror->getID(), $itemtype, $localId);

        $payload = [
            'ticket' => ['remote_id' => $tickets_id],
            'item'   => $item,
        ];

        Outbox::push($agent->getID(), $action, $payload, self::key($action, $localId, $payload));
    }

    /**
     * Queues an edit of an item this end created.
     *
     * @param bool $requireOwnership false only for approval answers, which travel
     *                               from whoever answered rather than from the end
     *                               that created the approval.
     */
    private static function pushItemUpdate(
        string $itemtype,
        int $localId,
        string $action,
        array $item,
        bool $requireOwnership = true
    ): void {
        $link = MirrorItem::forItem($itemtype, $localId);

        if ($link === null || ($requireOwnership && !$link->isContentOwner())) {
            return;
        }

        if (Guard::isLocked($itemtype, $localId)) {
            return;
        }

        $mirror = $link->getMirror();
        if ($mirror === null) {
            return;
        }

        $context = self::context((int) $mirror->fields['tickets_id']);
        if ($context === null) {
            return;
        }

        [, $agent] = $context;

        $payload = [
            'ticket' => ['remote_id' => (int) $mirror->fields['tickets_id']],
            'item'   => $item,
        ];

        Outbox::push($agent->getID(), $action, $payload, self::key($action, $localId, $payload));
    }

    /**
     * Bin and restore, which carry no data beyond the ticket identity.
     */
    private static function pushTicketLifecycle(Ticket $ticket, string $action): void
    {
        if (Marker::isOwnWrite($ticket) || Config::role() === Config::ROLE_NONE) {
            return;
        }

        $tickets_id = (int) $ticket->getID();

        $context = self::context($tickets_id, evenInBin: true);
        if ($context === null) {
            return;
        }

        [, $agent] = $context;

        $payload = ['ticket' => ['remote_id' => $tickets_id]];

        Outbox::push($agent->getID(), $action, $payload, self::key($action, $tickets_id, $payload));
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

        // A ticket in the bin must not become a live ticket on the peer. Nothing
        // carries is_deleted in the creation payload, so the copy would arrive open,
        // and the update that got us here was core's own doing -- a purge cascade or
        // an automatic action -- not a decision to start mirroring this ticket.
        if ((int) ($ticket->fields['is_deleted'] ?? 0) === 1) {
            Log::write('mirror not created: the ticket is in the bin', [
                'tickets_id' => $tickets_id,
                'role'       => Config::role(),
            ]);

            return;
        }

        $entities_id = (int) $ticket->fields['entities_id'];

        $agent = Config::isMaster()
            ? Agent::findByEntity($entities_id)
            : Agent::master();

        // Not being usable YET is not a reason to lose the ticket: on the master an
        // agent stays pending until an administrator binds it to a customer entity,
        // and that is precisely when its first tickets are opened. The event is
        // queued and Outbox::deliver() holds it back until the peer is linked.
        if ($agent === null) {
            Log::write('no peer for ticket', [
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

        $mirror     = new Mirror();
        $mirrors_id = (int) $mirror->add([
            'tickets_id'                      => $tickets_id,
            'remote_tickets_id'               => 0,
            'plugin_pellissarisync_agents_id' => $agent->getID(),
            'origin'                          => Config::role(),
            'client_name'                     => $clientName,
            'last_received_status'             => 0,
            'sync_state'                      => Mirror::STATE_QUEUED,
        ]);

        // Without the link row the creation is undeliverable in practice: Ack cannot
        // record the id the peer assigns, and the event can never be queued a second
        // time either, because its idempotency key is fixed per ticket and
        // Outbox::isQueued() collapses it forever. Better to fail loudly here.
        if ($mirrors_id <= 0) {
            Log::write('mirror NOT created: the link row could not be written, creation not pushed', [
                'tickets_id' => $tickets_id,
                'agent'      => $agent->getID(),
            ]);

            return;
        }

        $payload = ['ticket' => Payload::ticket($ticket, $clientName)];

        Outbox::push(
            $agent->getID(),
            Envelope::ACTION_TICKET_CREATE,
            $payload,
            self::key(Envelope::ACTION_TICKET_CREATE, $tickets_id, [])
        );

        // Queued after the creation on purpose: the peer needs the mirror before it
        // can accept anything attached to it.
        $mirror->getFromDB($mirror->getID());
        self::pushExistingDocuments($agent, $mirror, $tickets_id);
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
