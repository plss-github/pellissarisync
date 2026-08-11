<?php

namespace GlpiPlugin\Pellissarisync\Sync;

use CommonITILActor;
use GlpiPlugin\Pellissarisync\Agent;
use GlpiPlugin\Pellissarisync\Guard;
use GlpiPlugin\Pellissarisync\Log;
use GlpiPlugin\Pellissarisync\Marker;
use GlpiPlugin\Pellissarisync\Mirror;
use Group;
use Group_Ticket;
use Ticket;
use Ticket_User;
use User;

/**
 * Applies requesters and assigned technicians received from the peer.
 *
 * The e-mail address is the only identity that crosses two independent GLPI
 * installations, so every actor is matched against the local glpi_useremails.
 * When nobody matches, the address itself becomes the actor -- GLPI models that
 * natively as an "e-mail actor" (Ticket_User with users_id = 0 and
 * alternative_email set), which is how a ticket opened by e-mail stores its
 * requester.
 *
 * Deliberately additive: actors are added, never removed. Both ends have their
 * own local actors -- the customer's requester on one side, the support desk's
 * technician on the other -- and applying the peer's list as a replacement would
 * delete them. Removing an actor upstream therefore does not propagate.
 */
final class ActorSync
{
    /**
     * Notifications are off for mirrored actors: the originating instance already
     * notifies its own people, and a second GLPI writing to the same addresses
     * would duplicate every message.
     */
    private const USE_NOTIFICATION = 0;

    private const TYPES = [
        'requester' => CommonITILActor::REQUESTER,
        'assign'    => CommonITILActor::ASSIGN,
    ];

    /**
     * @param array{requester?: array, assign?: array} $actors
     */
    public static function apply(Mirror $mirror, array $actors): int
    {
        $tickets_id = (int) $mirror->fields['tickets_id'];
        if ($tickets_id <= 0) {
            return 0;
        }

        $added = 0;

        foreach (self::TYPES as $key => $type) {
            foreach ((array) ($actors[$key] ?? []) as $actor) {
                $email = trim((string) ($actor['email'] ?? ''));
                if ($email === '') {
                    continue;
                }

                if (self::add($tickets_id, $type, $email)) {
                    $added++;
                }
            }
        }

        return $added;
    }

    /**
     * Applies the configured assignees for this customer: the group and the
     * technician in charge of them.
     *
     * Runs after the mirrored ticket exists and after the peer's own actors, so it
     * adds to them rather than competing with them. Local ids, not e-mails: these are
     * this instance's own people, configured here.
     *
     * @return array{users: int, groups: int} how many were actually added
     */
    public static function applyAssignees(Mirror $mirror, Agent $agent): array
    {
        $tickets_id = (int) $mirror->fields['tickets_id'];
        if ($tickets_id <= 0) {
            return ['users' => 0, 'groups' => 0];
        }

        $assignees = $agent->assignees();
        $added     = ['users' => 0, 'groups' => 0];

        foreach ($assignees['users'] as $users_id) {
            if (self::addUser($tickets_id, CommonITILActor::ASSIGN, $users_id, '')) {
                $added['users']++;
            }
        }

        foreach ($assignees['groups'] as $groups_id) {
            if (self::addGroup($tickets_id, $groups_id)) {
                $added['groups']++;
            }
        }

        if ($added['users'] > 0 || $added['groups'] > 0) {
            Log::write('default assignees applied', [
                'tickets_id' => $tickets_id,
                'agent'      => $agent->getID(),
                'users'      => $added['users'],
                'groups'     => $added['groups'],
            ]);
        }

        return $added;
    }

    private static function addGroup(int $tickets_id, int $groups_id): bool
    {
        if ($groups_id <= 0) {
            return false;
        }

        $group = new Group();
        if (!$group->getFromDB($groups_id)) {
            return false;
        }

        if (countElementsInTable(Group_Ticket::getTable(), [
            'tickets_id' => $tickets_id,
            'groups_id'  => $groups_id,
            'type'       => CommonITILActor::ASSIGN,
        ]) > 0) {
            return false;
        }

        $link = new Group_Ticket();

        $id = Guard::run(Ticket::class, $tickets_id, static fn(): int => (int) $link->add(
            array_merge(Marker::actorFlags(), [
                'tickets_id' => $tickets_id,
                'groups_id'  => $groups_id,
                'type'       => CommonITILActor::ASSIGN,
            ])
        ));

        return is_int($id) && $id > 0;
    }

    private static function add(int $tickets_id, int $type, string $email): bool
    {
        return self::addUser($tickets_id, $type, self::localUser($email), $email);
    }

    /**
     * @param string $email the address the actor came from; empty for a local
     *                      assignee configured here, which needs no trace
     */
    private static function addUser(int $tickets_id, int $type, int $users_id, string $email): bool
    {
        if ($users_id <= 0 && $email === '') {
            return false;
        }

        if (self::alreadyThere($tickets_id, $type, $users_id, $email)) {
            return false;
        }

        $input = array_merge(Marker::actorFlags(), [
            'tickets_id'        => $tickets_id,
            'users_id'          => $users_id,
            'type'              => $type,
            'use_notification'  => self::USE_NOTIFICATION,
            // Kept even when the user matched locally: it is the trace of which
            // address the actor came from.
            'alternative_email' => $email,
        ]);

        $link = new Ticket_User();

        // Adding an actor makes core recompute the ticket (an assignment can move
        // it to "assigned"), which would re-enter our own ticket hook.
        $id = Guard::run(Ticket::class, $tickets_id, static fn(): int => (int) $link->add($input));

        if (!is_int($id) || $id <= 0) {
            Log::write('actor not applied', [
                'tickets_id' => $tickets_id,
                'type'       => $type,
                'email'      => $email,
            ]);

            return false;
        }

        return true;
    }

    /**
     * The local user owning this address, or 0 when there is none.
     */
    private static function localUser(string $email): int
    {
        $user = new User();

        // Matches glpi_useremails, including secondary addresses.
        if (!$user->getFromDBbyEmail($email)) {
            return 0;
        }

        // A deleted or disabled account must not be attributed a ticket; the
        // address still travels as an e-mail actor.
        if ((int) $user->fields['is_deleted'] === 1 || (int) $user->fields['is_active'] !== 1) {
            return 0;
        }

        return (int) $user->getID();
    }

    /**
     * Core refuses a duplicate actor when users_id > 0, but e-mail actors
     * (users_id = 0) are not deduplicated there, so the check has to happen here
     * or every re-delivery would stack another copy of the same address.
     */
    private static function alreadyThere(int $tickets_id, int $type, int $users_id, string $email): bool
    {
        $criteria = ['tickets_id' => $tickets_id, 'type' => $type];

        $criteria += $users_id > 0
            ? ['users_id' => $users_id]
            : ['users_id' => 0, 'alternative_email' => $email];

        return countElementsInTable(Ticket_User::getTable(), $criteria) > 0;
    }
}
