<?php

namespace GlpiPlugin\Pellissarisync\Protocol;

use Entity;
use GlpiPlugin\Pellissarisync\Clock;
use GlpiPlugin\Pellissarisync\Compat;
use ITILFollowup;
use ITILSolution;
use Ticket;
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
        ];
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
