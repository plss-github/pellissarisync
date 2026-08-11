<?php

namespace GlpiPlugin\Pellissarisync\Protocol;

use CommonITILActor;
use Entity;
use GlpiPlugin\Pellissarisync\Clock;
use GlpiPlugin\Pellissarisync\Compat;
use ITILFollowup;
use ITILSolution;
use TaskCategory;
use Ticket;
use Ticket_User;
use TicketCost;
use TicketTask;
use TicketValidation;
use User;
use UserEmail;

/**
 * Builds the payloads that travel between the two ends.
 *
 * Content always travels in full (title + body + author + timestamps), so the
 * receiving end can re-render the mirrored ticket from scratch on every update
 * without keeping a private copy of the original text.
 */
final class Payload
{
    public static function ticket(Ticket $ticket, string $clientName): array
    {
        return [
            'remote_id'     => (int) $ticket->getID(),
            // Read through Compat: on GLPI 10 the stored value is HTML-encoded and
            // would otherwise travel as entities.
            'name'          => Compat::readRichText($ticket->fields['name'] ?? ''),
            'content'       => Compat::readRichText($ticket->fields['content'] ?? ''),
            'status'        => (int) $ticket->fields['status'],
            'urgency'       => (int) $ticket->fields['urgency'],
            'impact'        => (int) $ticket->fields['impact'],
            'type'          => (int) $ticket->fields['type'],
            'date'          => Clock::normalize($ticket->fields['date'] ?? null),
            'date_creation' => Clock::normalize($ticket->fields['date_creation'] ?? null),
            'date_mod'      => Clock::normalize($ticket->fields['date_mod'] ?? null),
            'author'        => self::author((int) ($ticket->fields['users_id_recipient'] ?? 0)),
            'client_name'   => $clientName,
            'actors'        => self::actors($ticket),
        ];
    }

    /**
     * Requesters and assigned technicians, identified by e-mail.
     *
     * The peer's user ids are meaningless on the other end, so the address is the
     * only portable identity: the receiver matches it against its own
     * glpi_useremails and, failing that, keeps the address as an e-mail-only
     * actor. Groups and suppliers are deliberately left out -- they have no
     * reliable counterpart across two independent GLPI installations.
     *
     * @return array{requester: list<array{name: string, email: string}>, assign: list<array{name: string, email: string}>}
     */
    public static function actors(Ticket $ticket): array
    {
        return [
            'requester' => self::actorsOfType($ticket, CommonITILActor::REQUESTER),
            'assign'    => self::actorsOfType($ticket, CommonITILActor::ASSIGN),
        ];
    }

    /**
     * @return list<array{name: string, email: string}>
     */
    private static function actorsOfType(Ticket $ticket, int $type): array
    {
        global $DB;

        $tickets_id = (int) $ticket->getID();
        if ($tickets_id <= 0) {
            return [];
        }

        $actors = [];

        $rows = $DB->request([
            'FROM'  => Ticket_User::getTable(),
            'WHERE' => ['tickets_id' => $tickets_id, 'type' => $type],
        ]);

        foreach ($rows as $row) {
            $users_id = (int) $row['users_id'];

            // An actor with no local user is already just an address: the ticket
            // was opened by e-mail, or a previous mirror could not match it.
            if ($users_id <= 0) {
                $email = trim((string) ($row['alternative_email'] ?? ''));

                if ($email !== '') {
                    $actors[] = ['name' => '', 'email' => $email];
                }

                continue;
            }

            $identity = self::author($users_id);
            $email     = (string) ($identity['identifier'] ?? '');

            // identifier falls back to the login when the user has no address;
            // only a real address is usable as an actor on the other end.
            $actors[] = [
                'name'  => (string) $identity['name'],
                'email' => str_contains($email, '@') ? $email : '',
            ];
        }

        return $actors;
    }

    /**
     * A task keeps its own itemtype on the peer, so its duration and state travel
     * as data rather than being folded into rendered text.
     */
    public static function task(TicketTask $task): array
    {
        return [
            'remote_id'     => (int) $task->getID(),
            'content'       => Compat::readRichText($task->fields['content'] ?? ''),
            'is_private'    => (int) ($task->fields['is_private'] ?? 0),
            'actiontime'    => (int) ($task->fields['actiontime'] ?? 0),
            'state'         => (int) ($task->fields['state'] ?? 0),
            'date'          => Clock::normalize($task->fields['date'] ?? null),
            'date_creation' => Clock::normalize($task->fields['date_creation'] ?? null),
            'date_mod'      => Clock::normalize($task->fields['date_mod'] ?? null),
            'begin'         => Clock::normalize($task->fields['begin'] ?? null),
            'end'           => Clock::normalize($task->fields['end'] ?? null),
            'category'      => self::categoryName((int) ($task->fields['taskcategories_id'] ?? 0)),
            'author'        => self::author((int) ($task->fields['users_id'] ?? 0)),
            // The technician who performed it, so the other end can show who did
            // the work even when that user does not exist locally.
            'technician'    => self::author((int) ($task->fields['users_id_tech'] ?? 0)),
        ];
    }

    /**
     * Task category by name: ids do not match across two installations, so the
     * receiver resolves (or creates) the category from the name.
     */
    private static function categoryName(int $taskcategories_id): string
    {
        if ($taskcategories_id <= 0) {
            return '';
        }

        $category = new TaskCategory();

        return $category->getFromDB($taskcategories_id)
            ? (string) $category->fields['completename']
            : '';
    }

    public static function cost(TicketCost $cost): array
    {
        return [
            'remote_id'     => (int) $cost->getID(),
            'name'          => (string) ($cost->fields['name'] ?? ''),
            'comment'       => (string) ($cost->fields['comment'] ?? ''),
            'begin_date'    => Clock::normalize($cost->fields['begin_date'] ?? null),
            'end_date'      => Clock::normalize($cost->fields['end_date'] ?? null),
            'actiontime'    => (int) ($cost->fields['actiontime'] ?? 0),
            // Money is decimal in GLPI; it travels as a string so no precision is
            // lost to a float round-trip through JSON.
            'cost_time'     => (string) ($cost->fields['cost_time'] ?? '0'),
            'cost_fixed'    => (string) ($cost->fields['cost_fixed'] ?? '0'),
            'cost_material' => (string) ($cost->fields['cost_material'] ?? '0'),
        ];
    }

    /**
     * An approval travels as the request plus, on later updates, the answer.
     */
    public static function validation(TicketValidation $validation): array
    {
        return [
            'remote_id'          => (int) $validation->getID(),
            'status'             => (int) ($validation->fields['status'] ?? 0),
            'comment_submission' => Compat::readRichText($validation->fields['comment_submission'] ?? ''),
            'comment_validation' => Compat::readRichText($validation->fields['comment_validation'] ?? ''),
            'submission_date'    => Clock::normalize($validation->fields['submission_date'] ?? null),
            'validation_date'    => Clock::normalize($validation->fields['validation_date'] ?? null),
            // Who asked, and who must answer -- both by e-mail, since ids do not
            // cross instances.
            'author'             => self::author((int) ($validation->fields['users_id'] ?? 0)),
            'approver'           => self::approver($validation),
        ];
    }

    /**
     * The approval target. GLPI 11 models it as itemtype_target/items_id_target
     * and only keeps users_id_validate for reading; GLPI 10 has the column alone.
     */
    private static function approver(TicketValidation $validation): array
    {
        $users_id = (int) ($validation->fields['users_id_validate'] ?? 0);

        if ($users_id <= 0
            && (string) ($validation->fields['itemtype_target'] ?? '') === User::class
        ) {
            $users_id = (int) ($validation->fields['items_id_target'] ?? 0);
        }

        return self::author($users_id);
    }

    public static function followup(ITILFollowup $followup): array
    {
        return [
            'remote_id'     => (int) $followup->getID(),
            'content'       => Compat::readRichText($followup->fields['content'] ?? ''),
            'is_private'    => (int) ($followup->fields['is_private'] ?? 0),
            'date'          => Clock::normalize($followup->fields['date'] ?? null),
            'date_creation' => Clock::normalize($followup->fields['date_creation'] ?? null),
            'date_mod'      => Clock::normalize($followup->fields['date_mod'] ?? null),
            'author'        => self::author((int) ($followup->fields['users_id'] ?? 0)),
        ];
    }

    /**
     * A solution, shaped like a followup so both ends handle it with the same
     * code path, but flagged so the mirror can mark it as the resolution.
     */
    public static function solution(ITILSolution $solution): array
    {
        return [
            'remote_id'     => (int) $solution->getID(),
            'content'       => Compat::readRichText($solution->fields['content'] ?? ''),
            'is_private'    => 0,
            'is_solution'   => true,
            'date'          => Clock::normalize($solution->fields['date_creation'] ?? null),
            'date_creation' => Clock::normalize($solution->fields['date_creation'] ?? null),
            'date_mod'      => Clock::normalize($solution->fields['date_mod'] ?? null),
            'author'        => self::author((int) ($solution->fields['users_id'] ?? 0)),
        ];
    }

    /**
     * Author identity as it must appear in the mirrored content:
     * display name plus e-mail, falling back to the login when the user has no
     * e-mail address (glpi_users has no email column; addresses live in
     * glpi_useremails).
     */
    public static function author(int $users_id): array
    {
        $user = new User();

        if ($users_id <= 0 || !$user->getFromDB($users_id)) {
            return ['name' => '', 'identifier' => ''];
        }

        // getFriendlyName() honours the configured name order and never appends
        // the numeric id, unlike getName()/getUserName().
        $display = (string) $user->getFriendlyName();

        $identifier = (string) UserEmail::getDefaultForUser($users_id);
        if ($identifier === '') {
            $identifier = (string) $user->fields['name'];
        }

        return ['name' => $display, 'identifier' => $identifier];
    }

    /**
     * The customer name is the entity the ticket lives in on the originating
     * side; it is what composes the `[Customer]` title prefix on the mirror.
     */
    public static function clientNameForEntity(int $entities_id): string
    {
        $entity = new Entity();

        if ($entity->getFromDB($entities_id)) {
            return (string) $entity->fields['name'];
        }

        return '';
    }
}
