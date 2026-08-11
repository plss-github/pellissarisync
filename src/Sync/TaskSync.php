<?php

namespace GlpiPlugin\Pellissarisync\Sync;

use GlpiPlugin\Pellissarisync\Clock;
use GlpiPlugin\Pellissarisync\Compat;
use GlpiPlugin\Pellissarisync\Guard;
use GlpiPlugin\Pellissarisync\Log;
use GlpiPlugin\Pellissarisync\Marker;
use GlpiPlugin\Pellissarisync\Mirror;
use GlpiPlugin\Pellissarisync\MirrorItem;
use RuntimeException;
use TaskCategory;
use Ticket;
use TicketTask;

/**
 * Applies inbound task events.
 *
 * A task arrives as a real TicketTask -- not folded into a followup -- so it shows
 * up in the Tasks tab with its duration, which is what makes the time spent
 * visible to the customer. Private tasks never travel, same rule as private notes.
 */
final class TaskSync
{
    public const ITEMTYPE = TicketTask::class;

    /**
     * @return array{tickettasks_id: int}
     */
    public static function create(Mirror $mirror, array $payload): array
    {
        $remoteId = (int) ($payload['remote_id'] ?? 0);
        if ($remoteId <= 0) {
            throw new RuntimeException('missing remote task id');
        }

        $origin = TicketSync::remoteRole();

        $existing = MirrorItem::forRemoteItem($mirror->getID(), self::ITEMTYPE, $remoteId, $origin);
        if ($existing !== null) {
            return ['tickettasks_id' => (int) $existing->fields['items_id']];
        }

        $tickets_id = (int) $mirror->fields['tickets_id'];

        $input = Compat::writeRichText(
            array_merge(Marker::timelineFlags(), self::fields($payload), [
                'tickets_id'    => $tickets_id,
                'is_private'    => 0,
                // Peer users do not exist locally, so no author is attributed; the
                // real author is stated in the rendered header instead.
                'users_id'      => 0,
                'date'          => Clock::normalize($payload['date'] ?? null) ?? Clock::now(),
                'date_creation' => Clock::normalize($payload['date_creation'] ?? null) ?? Clock::now(),
            ]),
            ['content']
        );

        $task = new TicketTask();

        $id = Guard::run(Ticket::class, $tickets_id, static fn(): int => (int) $task->add($input));

        if (!is_int($id) || $id <= 0) {
            throw new RuntimeException('local task creation failed');
        }

        $link = new MirrorItem();
        $link->add([
            'plugin_pellissarisync_mirrors_id' => $mirror->getID(),
            'itemtype'                         => self::ITEMTYPE,
            'items_id'                         => $id,
            'remote_items_id'                  => $remoteId,
            'origin'                           => $origin,
        ]);

        return ['tickettasks_id' => $id];
    }

    public static function update(MirrorItem $link, array $payload): bool
    {
        $tasks_id = (int) $link->fields['items_id'];

        $task = new TicketTask();
        if (!$task->getFromDB($tasks_id)) {
            return false;
        }

        $input = Compat::writeRichText(
            array_merge(Marker::timelineFlags(), self::fields($payload), ['id' => $tasks_id]),
            ['content']
        );

        $result = Guard::run(
            self::ITEMTYPE,
            $tasks_id,
            static fn(): bool => (bool) $task->update($input)
        );

        return (bool) $result;
    }

    /**
     * The fields shared by create and update.
     */
    private static function fields(array $payload): array
    {
        return [
            'content'           => Renderer::content($payload),
            // The duration is the point of mirroring a task at all.
            'actiontime'        => max(0, (int) ($payload['actiontime'] ?? 0)),
            'state'            => self::sanitizeState($payload['state'] ?? null),
            'begin'             => Clock::normalize($payload['begin'] ?? null),
            'end'               => Clock::normalize($payload['end'] ?? null),
            'taskcategories_id' => self::localCategory((string) ($payload['category'] ?? '')),
        ];
    }

    /**
     * Planning states are the same integers in GLPI 10 and 11: 0 none,
     * 1 information, 2 to do, 3 done.
     */
    private static function sanitizeState(mixed $value): int
    {
        $state = (int) $value;

        return ($state >= 0 && $state <= 3) ? $state : 0;
    }

    /**
     * Task categories are resolved by name and never created: inventing entries in
     * the other instance's dropdowns would be a side effect of mirroring that
     * nobody asked for. An unknown category leaves the task uncategorised.
     */
    private static function localCategory(string $completename): int
    {
        if (trim($completename) === '') {
            return 0;
        }

        $category = new TaskCategory();

        if ($category->getFromDBByCrit(['completename' => $completename])) {
            return (int) $category->getID();
        }

        Log::write('task category not found locally', ['category' => $completename]);

        return 0;
    }
}
