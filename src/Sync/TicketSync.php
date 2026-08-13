<?php

namespace GlpiPlugin\Pellissarisync\Sync;

use GlpiPlugin\Pellissarisync\Agent;
use GlpiPlugin\Pellissarisync\Clock;
use GlpiPlugin\Pellissarisync\Compat;
use GlpiPlugin\Pellissarisync\Config;
use GlpiPlugin\Pellissarisync\Guard;
use GlpiPlugin\Pellissarisync\Hook;
use GlpiPlugin\Pellissarisync\Log;
use GlpiPlugin\Pellissarisync\Marker;
use GlpiPlugin\Pellissarisync\Mirror;
use RuntimeException;
use Ticket;

/**
 * Applies inbound ticket events.
 *
 * Callers are expected to wrap these in Session::callAsSystem(), since the
 * machine endpoint has no session and therefore no rights.
 */
final class TicketSync
{
    /**
     * Creates the local mirror of a remote ticket.
     *
     * @return array{tickets_id: int, mirrors_id: int}
     */
    public static function create(Agent $agent, array $payload): array
    {
        $remoteId = (int) ($payload['remote_id'] ?? 0);
        if ($remoteId <= 0) {
            throw new RuntimeException('missing remote ticket id');
        }

        // A replay that slipped past the idempotency ledger still must not
        // duplicate the mirror. A tombstone counts: the local ticket was purged, and
        // a redelivered creation must not resurrect it as a second ticket.
        $existing = Mirror::forRemoteTicket($agent->getID(), $remoteId);
        if ($existing !== null) {
            if ($existing->isPurged()) {
                Log::write('creation ignored: this mirror was purged locally', [
                    'remote_id' => $remoteId,
                    'agent'     => $agent->getID(),
                ]);
            }

            return [
                'tickets_id' => (int) $existing->fields['tickets_id'],
                'mirrors_id' => $existing->getID(),
            ];
        }

        $clientName  = self::clientNameFor($agent, $payload);
        $entities_id = (int) ($agent->fields['entities_id'] ?? 0);
        $category    = Config::syncCategoryId();

        $input = array_merge(Marker::ticketFlags(Config::runBusinessRules()), [
            'name'                => Renderer::title((string) ($payload['name'] ?? ''), $clientName),
            'content'             => Renderer::content($payload),
            'entities_id'         => $entities_id,
            'itilcategories_id'   => $category,
            'type'                => self::sanitizeType($payload['type'] ?? null),
            'urgency'             => self::sanitizeScale($payload['urgency'] ?? null),
            'impact'              => self::sanitizeScale($payload['impact'] ?? null),
            'status'              => self::sanitizeStatus($payload['status'] ?? null),
            // Preserve the peer's timeline instead of stamping "now".
            'date'                => Clock::normalize($payload['date'] ?? null) ?? Clock::now(),
            'date_creation'       => Clock::normalize($payload['date_creation'] ?? null) ?? Clock::now(),
        ]);

        // GLPI 10 expects rich text to arrive already encoded, exactly as core
        // does before creating a ticket from an incoming e-mail.
        $input = Compat::writeRichText($input, ['name', 'content']);

        $ticket     = new Ticket();
        $tickets_id = (int) $ticket->add($input);

        if ($tickets_id <= 0) {
            throw new RuntimeException('local ticket creation failed');
        }

        $mirror     = new Mirror();
        $mirrors_id = (int) $mirror->add([
            'tickets_id'                      => $tickets_id,
            'remote_tickets_id'               => $remoteId,
            'plugin_pellissarisync_agents_id' => $agent->getID(),
            // The peer created it, so the peer owns its title and description.
            'origin'                          => self::remoteRole(),
            'client_name'                     => $clientName,
            'last_received_status'             => (int) $input['status'],
            'sync_state'                      => Mirror::STATE_OK,
        ]);

        $mirror->getFromDB($mirrors_id);

        self::protectEntity($tickets_id, $entities_id, $agent);

        // Actors come after the ticket exists: an e-mail actor is a row in
        // glpi_tickets_users, not a field of the ticket.
        ActorSync::apply($mirror, (array) ($payload['actors'] ?? []));

        // And then this side's own assignees: the group and the technician in charge
        // of this customer. Last, so they are added on top of whatever the peer sent
        // and whatever the rules decided.
        ActorSync::applyAssignees($mirror, $agent);

        // The peer gets the resulting actor set back, so the customer sees who is
        // handling their ticket. Nothing is echoed: the actors written just above
        // carry the plugin's own marker, so no hook fired for them.
        //
        // Deferred, not pushed: we are inside the peer's own delivery, and it only
        // learns this ticket's id from the answer we have not returned yet. The cron
        // flush sends it right after.
        Hook::pushActors($tickets_id, now: false);

        Log::write('mirror created', [
            'tickets_id' => $tickets_id,
            'remote_id'  => $remoteId,
            'agent'      => $agent->getID(),
        ]);

        return ['tickets_id' => $tickets_id, 'mirrors_id' => $mirrors_id];
    }

    /**
     * Puts the mirror back in the customer's entity if a business rule moved it.
     *
     * The entity is not an ordinary field here: it is the binding between this
     * mirror and the customer it belongs to, chosen by whoever linked the agent. A
     * rule with "assign entity" among its actions -- common enough for routing --
     * would drop customer A's ticket into customer B's entity, which is a data leak
     * dressed up as automation. Everything else the rules decide is kept.
     */
    private static function protectEntity(int $tickets_id, int $entities_id, Agent $agent): void
    {
        $ticket = new Ticket();
        if (!$ticket->getFromDB($tickets_id)) {
            return;
        }

        if ((int) $ticket->fields['entities_id'] === $entities_id) {
            return;
        }

        Log::write('a business rule moved a mirrored ticket out of its customer entity; restoring', [
            'tickets_id' => $tickets_id,
            'agent'      => $agent->getID(),
            'rule_set'   => (int) $ticket->fields['entities_id'],
            'restored'   => $entities_id,
        ]);

        Guard::run(Ticket::class, $tickets_id, static fn(): bool => (bool) $ticket->update(
            array_merge(Marker::ticketFlags(), [
                'id'          => $tickets_id,
                'entities_id' => $entities_id,
            ])
        ));
    }

    /**
     * The customer name that composes the `[Customer]` prefix.
     *
     * It must be the name of the peer as registered *here* -- on the support desk
     * that is the customer's name, curated by whoever linked the agent. The entity
     * name that travels in the payload is whatever the customer happened to call
     * their own root entity, which is why it is only the last resort.
     */
    private static function clientNameFor(Agent $agent, array $payload): string
    {
        $candidates = [
            (string) ($agent->fields['name'] ?? ''),
            (string) ($agent->fields['client_name'] ?? ''),
            (string) ($payload['client_name'] ?? ''),
        ];

        foreach ($candidates as $candidate) {
            if (trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return '';
    }

    /**
     * Entity of a local ticket, needed by the items that carry their own
     * entities_id (costs, documents).
     */
    public static function entityOfTicket(int $tickets_id): int
    {
        $ticket = new Ticket();

        return $ticket->getFromDB($tickets_id) ? (int) $ticket->fields['entities_id'] : 0;
    }

    /**
     * Moves the mirror to the bin, because the peer's ticket went there.
     *
     * A soft delete on purpose: the mirror stops appearing in the customer's or the
     * desk's lists, but nothing is destroyed and a restore on the other end brings
     * it back.
     */
    public static function delete(Mirror $mirror): bool
    {
        $tickets_id = (int) $mirror->fields['tickets_id'];

        $ticket = new Ticket();
        if (!$ticket->getFromDB($tickets_id)) {
            return false;
        }

        if ((int) $ticket->fields['is_deleted'] === 1) {
            return true;
        }

        $result = Guard::run(Ticket::class, $tickets_id, static fn(): bool => (bool) $ticket->delete(
            array_merge(Marker::ticketFlags(), ['id' => $tickets_id])
        ));

        return (bool) $result;
    }

    public static function restore(Mirror $mirror): bool
    {
        $tickets_id = (int) $mirror->fields['tickets_id'];

        $ticket = new Ticket();
        if (!$ticket->getFromDB($tickets_id)) {
            return false;
        }

        if ((int) $ticket->fields['is_deleted'] === 0) {
            return true;
        }

        $result = Guard::run(Ticket::class, $tickets_id, static fn(): bool => (bool) $ticket->restore(
            array_merge(Marker::ticketFlags(), ['id' => $tickets_id])
        ));

        return (bool) $result;
    }

    /**
     * Rewrites title and description of a mirror. Only ever called for a ticket
     * whose origin is the peer.
     */
    public static function updateContent(Mirror $mirror, array $payload): bool
    {
        $tickets_id = (int) $mirror->fields['tickets_id'];

        $ticket = new Ticket();
        if (!$ticket->getFromDB($tickets_id)) {
            return false;
        }

        $clientName = (string) ($mirror->fields['client_name'] ?? '');

        $input = Compat::writeRichText(
            array_merge(Marker::ticketFlags(), [
                'id'      => $tickets_id,
                'name'    => Renderer::title((string) ($payload['name'] ?? ''), $clientName),
                'content' => Renderer::content($payload),
            ]),
            ['name', 'content']
        );

        $result = Guard::run(
            Ticket::class,
            $tickets_id,
            static fn(): bool => (bool) $ticket->update($input)
        );

        return (bool) $result;
    }

    /**
     * Status is the one field that travels in both directions.
     */
    public static function updateStatus(Mirror $mirror, mixed $rawStatus): bool
    {
        $status     = self::sanitizeStatus($rawStatus);
        $tickets_id = (int) $mirror->fields['tickets_id'];

        $ticket = new Ticket();
        if (!$ticket->getFromDB($tickets_id)) {
            return false;
        }

        // Remember what the peer told us, so the echo of this very change is not
        // pushed back when our own item_update hook fires.
        $mirror->update([
            'id'                   => $mirror->getID(),
            'last_received_status' => $status,
            '_no_history'          => true,
            '_no_message'          => true,
        ]);

        if ((int) $ticket->fields['status'] === $status) {
            return true;
        }

        $result = Guard::run(Ticket::class, $tickets_id, static fn(): bool => (bool) $ticket->update(
            array_merge(Marker::ticketFlags(), [
                'id'     => $tickets_id,
                'status' => $status,
            ])
        ));

        return (bool) $result;
    }

    // ------------------------------------------------------------- sanitizing

    /**
     * The role of the other end.
     */
    public static function remoteRole(): string
    {
        return Config::isMaster() ? Config::ROLE_AGENT : Config::ROLE_MASTER;
    }

    public static function sanitizeStatus(mixed $value): int
    {
        $status = (int) $value;

        // ACCEPTED and OBSERVED exist on CommonITILObject but are not valid
        // ticket statuses, so the allowed set comes from Ticket itself.
        return array_key_exists($status, Ticket::getAllStatusArray())
            ? $status
            : Ticket::INCOMING;
    }

    private static function sanitizeScale(mixed $value): int
    {
        $scale = (int) $value;

        return ($scale >= 1 && $scale <= 5) ? $scale : 3;
    }

    private static function sanitizeType(mixed $value): int
    {
        $type = (int) $value;

        return in_array($type, [Ticket::INCIDENT_TYPE, Ticket::DEMAND_TYPE], true)
            ? $type
            : Ticket::INCIDENT_TYPE;
    }
}
