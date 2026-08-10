<?php

namespace GlpiPlugin\Pellissarisync\Sync;

use GlpiPlugin\Pellissarisync\Agent;
use GlpiPlugin\Pellissarisync\Clock;
use GlpiPlugin\Pellissarisync\Compat;
use GlpiPlugin\Pellissarisync\Config;
use GlpiPlugin\Pellissarisync\Guard;
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
        // duplicate the mirror.
        $existing = Mirror::forRemoteTicket($agent->getID(), $remoteId);
        if ($existing !== null) {
            return [
                'tickets_id' => (int) $existing->fields['tickets_id'],
                'mirrors_id' => $existing->getID(),
            ];
        }

        $clientName  = (string) ($payload['client_name'] ?? $agent->fields['client_name'] ?? '');
        $entities_id = (int) ($agent->fields['entities_id'] ?? 0);
        $category    = Config::syncCategoryId();

        $input = array_merge(Marker::ticketFlags(), [
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
            'sync_state'                      => 'ok',
        ]);

        Log::write('mirror created', [
            'tickets_id' => $tickets_id,
            'remote_id'  => $remoteId,
            'agent'      => $agent->getID(),
        ]);

        return ['tickets_id' => $tickets_id, 'mirrors_id' => $mirrors_id];
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
