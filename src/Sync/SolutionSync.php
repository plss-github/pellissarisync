<?php

namespace GlpiPlugin\Pellissarisync\Sync;

use GlpiPlugin\Pellissarisync\Clock;
use GlpiPlugin\Pellissarisync\Compat;
use GlpiPlugin\Pellissarisync\Guard;
use GlpiPlugin\Pellissarisync\Marker;
use GlpiPlugin\Pellissarisync\Mirror;
use GlpiPlugin\Pellissarisync\MirrorItem;
use ITILSolution;
use RuntimeException;
use SolutionType;
use Ticket;

/**
 * Applies inbound solution events.
 *
 * A solution now arrives as a real ITILSolution, so the customer sees it in the
 * Solution tab where GLPI expects it -- earlier versions of the plugin delivered
 * it as a followup flagged "Solução", and inbound events from a peer that still
 * does that keep working through FollowupSync.
 */
final class SolutionSync
{
    public const ITEMTYPE = ITILSolution::class;

    /**
     * @return array{itilsolutions_id: int}
     */
    public static function create(Mirror $mirror, array $payload): array
    {
        $remoteId = (int) ($payload['remote_id'] ?? 0);
        if ($remoteId <= 0) {
            throw new RuntimeException('missing remote solution id');
        }

        $origin = TicketSync::remoteRole();

        $existing = MirrorItem::forRemoteItem($mirror->getID(), self::ITEMTYPE, $remoteId, $origin);
        if ($existing !== null) {
            return ['itilsolutions_id' => (int) $existing->fields['items_id']];
        }

        $tickets_id = (int) $mirror->fields['tickets_id'];

        $input = Compat::writeRichText(
            array_merge(Marker::timelineFlags(), [
                'itemtype'         => Ticket::class,
                'items_id'         => $tickets_id,
                'content'          => Renderer::solution($payload),
                'solutiontypes_id' => self::localType((string) ($payload['type'] ?? '')),
                'users_id'         => 0,
                'date_creation'    => Clock::normalize($payload['date_creation'] ?? null) ?? Clock::now(),

                // ITILSolution::post_addItem() otherwise solves the ticket itself
                // *and* pushes the solution onto every linked duplicate. Neither
                // may be a side effect of mirroring: the status is propagated
                // explicitly below, and the customer's other tickets are none of
                // this mirror's business.
                '_linked_ticket'       => true,
                // Without this, core assigns the solving technician -- user 0 here.
                '_disable_auto_assign' => true,
            ]),
            ['content']
        );

        $solution = new ITILSolution();

        $id = Guard::run(Ticket::class, $tickets_id, static fn(): int => (int) $solution->add($input));

        if (!is_int($id) || $id <= 0) {
            throw new RuntimeException('local solution creation failed');
        }

        $link = new MirrorItem();
        $link->add([
            'plugin_pellissarisync_mirrors_id' => $mirror->getID(),
            'itemtype'                         => self::ITEMTYPE,
            'items_id'                         => $id,
            'remote_items_id'                  => $remoteId,
            'origin'                           => $origin,
        ]);

        // A solution means solved. Going through updateStatus also records the
        // value as received, so our own ticket hook does not push it straight back.
        TicketSync::updateStatus($mirror, Ticket::SOLVED);

        return ['itilsolutions_id' => $id];
    }

    /**
     * Solution types are resolved by name and never created, same rule as task
     * categories: the mirror does not invent entries in the peer's dropdowns.
     */
    private static function localType(string $name): int
    {
        if (trim($name) === '') {
            return 0;
        }

        $type = new SolutionType();

        return $type->getFromDBByCrit(['name' => $name]) ? (int) $type->getID() : 0;
    }
}
