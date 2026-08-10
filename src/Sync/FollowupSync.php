<?php

namespace GlpiPlugin\Pellissarisync\Sync;

use GlpiPlugin\Pellissarisync\Clock;
use GlpiPlugin\Pellissarisync\Compat;
use GlpiPlugin\Pellissarisync\Guard;
use GlpiPlugin\Pellissarisync\Marker;
use GlpiPlugin\Pellissarisync\Mirror;
use GlpiPlugin\Pellissarisync\MirrorFollowup;
use ITILFollowup;
use RuntimeException;
use Ticket;

/**
 * Applies inbound followup events.
 */
final class FollowupSync
{
    /**
     * @return array{itilfollowups_id: int}
     */
    public static function create(Mirror $mirror, array $payload): array
    {
        $remoteId = (int) ($payload['remote_id'] ?? 0);
        if ($remoteId <= 0) {
            throw new RuntimeException('missing remote followup id');
        }

        $origin = TicketSync::remoteRole();
        $source = self::sourceOf($payload);

        $existing = MirrorFollowup::forRemoteFollowup($mirror->getID(), $remoteId, $origin, $source);
        if ($existing !== null) {
            return ['itilfollowups_id' => (int) $existing->fields['itilfollowups_id']];
        }

        $tickets_id = (int) $mirror->fields['tickets_id'];

        $input = array_merge(Marker::followupFlags(), [
            'itemtype'      => Ticket::class,
            'items_id'      => $tickets_id,
            'content'       => Renderer::content($payload),
            // Peer users do not exist locally, so no author is attributed; the
            // real author is stated in the rendered header instead.
            'users_id'      => 0,
            'is_private'    => 0,
            'date'          => Clock::normalize($payload['date'] ?? null) ?? Clock::now(),
            'date_creation' => Clock::normalize($payload['date_creation'] ?? null) ?? Clock::now(),
        ]);

        $input = Compat::writeRichText($input, ['content']);

        $followup = new ITILFollowup();

        $id = Guard::run(Ticket::class, $tickets_id, static fn(): int => (int) $followup->add($input));

        if (!is_int($id) || $id <= 0) {
            throw new RuntimeException('local followup creation failed');
        }

        $link = new MirrorFollowup();
        $link->add([
            'itilfollowups_id'                 => $id,
            'remote_followups_id'              => $remoteId,
            'plugin_pellissarisync_mirrors_id' => $mirror->getID(),
            'origin'                           => $origin,
            // Locally this is always a followup; the source records what it was
            // on the originating side.
            'source_itemtype'                  => $source,
        ]);

        return ['itilfollowups_id' => $id];
    }

    /**
     * A solution is mirrored as a followup flagged as such, so the customer can
     * tell the resolution apart from an ordinary update.
     */
    public static function sourceOf(array $payload): string
    {
        return !empty($payload['is_solution'])
            ? MirrorFollowup::SOURCE_SOLUTION
            : MirrorFollowup::SOURCE_FOLLOWUP;
    }

    public static function updateContent(MirrorFollowup $link, array $payload): bool
    {
        $followups_id = (int) $link->fields['itilfollowups_id'];

        $followup = new ITILFollowup();
        if (!$followup->getFromDB($followups_id)) {
            return false;
        }

        $input = Compat::writeRichText(
            array_merge(Marker::followupFlags(), [
                'id'      => $followups_id,
                'content' => Renderer::content($payload),
            ]),
            ['content']
        );

        $result = Guard::run(
            ITILFollowup::class,
            $followups_id,
            static fn(): bool => (bool) $followup->update($input)
        );

        return (bool) $result;
    }
}
