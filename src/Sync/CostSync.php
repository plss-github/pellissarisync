<?php

namespace GlpiPlugin\Pellissarisync\Sync;

use GlpiPlugin\Pellissarisync\Clock;
use GlpiPlugin\Pellissarisync\Guard;
use GlpiPlugin\Pellissarisync\Marker;
use GlpiPlugin\Pellissarisync\Mirror;
use GlpiPlugin\Pellissarisync\MirrorItem;
use RuntimeException;
use Ticket;
use TicketCost;

/**
 * Applies inbound cost events -- the entries of the ticket's Costs tab.
 *
 * Costs carry no rich text and no author, so unlike a task or a followup they are
 * mirrored as plain data: only `name` and `comment` are user-visible strings, and
 * the amounts travel as strings to survive the JSON round-trip without a float
 * rounding them.
 */
final class CostSync
{
    public const ITEMTYPE = TicketCost::class;

    /**
     * @return array{ticketcosts_id: int}
     */
    public static function create(Mirror $mirror, array $payload): array
    {
        $remoteId = (int) ($payload['remote_id'] ?? 0);
        if ($remoteId <= 0) {
            throw new RuntimeException('missing remote cost id');
        }

        $origin = TicketSync::remoteRole();

        $existing = MirrorItem::forRemoteItem($mirror->getID(), self::ITEMTYPE, $remoteId, $origin);
        if ($existing !== null) {
            return ['ticketcosts_id' => (int) $existing->fields['items_id']];
        }

        $tickets_id = (int) $mirror->fields['tickets_id'];

        $input = array_merge(Marker::timelineFlags(), self::fields($payload), [
            'tickets_id'  => $tickets_id,
            'entities_id' => TicketSync::entityOfTicket($tickets_id),
        ]);

        $cost = new TicketCost();

        $id = Guard::run(Ticket::class, $tickets_id, static fn(): int => (int) $cost->add($input));

        if (!is_int($id) || $id <= 0) {
            throw new RuntimeException('local cost creation failed');
        }

        $link = new MirrorItem();
        $link->add([
            'plugin_pellissarisync_mirrors_id' => $mirror->getID(),
            'itemtype'                         => self::ITEMTYPE,
            'items_id'                         => $id,
            'remote_items_id'                  => $remoteId,
            'origin'                           => $origin,
        ]);

        return ['ticketcosts_id' => $id];
    }

    public static function update(MirrorItem $link, array $payload): bool
    {
        $costs_id = (int) $link->fields['items_id'];

        $cost = new TicketCost();
        if (!$cost->getFromDB($costs_id)) {
            return false;
        }

        $input = array_merge(Marker::timelineFlags(), self::fields($payload), ['id' => $costs_id]);

        $result = Guard::run(
            self::ITEMTYPE,
            $costs_id,
            static fn(): bool => (bool) $cost->update($input)
        );

        return (bool) $result;
    }

    private static function fields(array $payload): array
    {
        return [
            'name'          => self::text($payload['name'] ?? '', 255),
            'comment'       => self::text($payload['comment'] ?? '', 65535),
            // TicketCost::prepareInputForAdd() requires a begin date; core defaults
            // it to today when the form omits it, so we do the same.
            'begin_date'    => Clock::normalize($payload['begin_date'] ?? null) ?? date('Y-m-d'),
            'end_date'      => Clock::normalize($payload['end_date'] ?? null),
            'actiontime'    => max(0, (int) ($payload['actiontime'] ?? 0)),
            'cost_time'     => self::amount($payload['cost_time'] ?? null),
            'cost_fixed'    => self::amount($payload['cost_fixed'] ?? null),
            'cost_material' => self::amount($payload['cost_material'] ?? null),
        ];
    }

    private static function text(mixed $value, int $max): string
    {
        $text = trim((string) $value);

        return mb_strlen($text) > $max ? mb_substr($text, 0, $max) : $text;
    }

    /**
     * Amounts are decimal(20,4) in GLPI. A negative value is refused rather than
     * mirrored: it would show up as a credit nobody entered.
     */
    private static function amount(mixed $value): string
    {
        $amount = (float) str_replace(',', '.', (string) $value);

        return number_format(max(0.0, $amount), 4, '.', '');
    }
}
