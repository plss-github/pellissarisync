<?php

namespace GlpiPlugin\Pellissarisync;

use Session;
use Ticket;

/**
 * Creates a throwaway ticket in the synchronized category, so an administrator can
 * verify the whole chain without waiting for a real customer request.
 *
 * Deliberately a normal ticket creation: it goes through the same hooks as any
 * other ticket, which is the point of the test.
 */
final class TestTicket
{
    /**
     * @return array{ok: bool, message: string, tickets_id: int}
     */
    public static function create(): array
    {
        $category = Config::syncCategoryId();

        if ($category <= 0) {
            return [
                'ok'         => false,
                'message'    => __('Select the synchronized category first.', 'pellissarisync'),
                'tickets_id' => 0,
            ];
        }

        $peer = Config::isMaster() ? null : Agent::master();

        if (Config::isAgent() && ($peer === null || !$peer->isUsable())) {
            return [
                'ok'         => false,
                'message'    => __('Connect to the master before generating a test ticket.', 'pellissarisync'),
                'tickets_id' => 0,
            ];
        }

        $users_id = (int) Session::getLoginUserID();

        $ticket     = new Ticket();
        $tickets_id = (int) $ticket->add([
            'name'                => sprintf(
                __('[TEST] Pellissari Sync connectivity check - %s', 'pellissarisync'),
                Clock::forHumans(Clock::now())
            ),
            'content'             => __('Ticket generated from the Pellissari Sync configuration screen to validate mirroring. It can be closed.', 'pellissarisync'),
            'entities_id'         => (int) ($_SESSION['glpiactive_entity'] ?? 0),
            'itilcategories_id'   => $category,
            'type'                => Ticket::INCIDENT_TYPE,
            'urgency'             => 3,
            'status'              => Ticket::INCOMING,
            '_users_id_requester' => $users_id,
            '_no_message'         => true,
        ]);

        if ($tickets_id <= 0) {
            return [
                'ok'         => false,
                'message'    => __('Could not create the test ticket.', 'pellissarisync'),
                'tickets_id' => 0,
            ];
        }

        $mirror = Mirror::forTicket($tickets_id);

        if ($mirror === null) {
            return [
                'ok'         => false,
                'message'    => sprintf(
                    __('Ticket %d was created but not queued for mirroring - check the category and the peer link.', 'pellissarisync'),
                    $tickets_id
                ),
                'tickets_id' => $tickets_id,
            ];
        }

        return [
            'ok'         => true,
            'message'    => sprintf(
                __('Test ticket %d created and queued for mirroring.', 'pellissarisync'),
                $tickets_id
            ),
            'tickets_id' => $tickets_id,
        ];
    }
}
