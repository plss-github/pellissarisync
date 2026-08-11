<?php

namespace GlpiPlugin\Pellissarisync\Sync;

use CommonITILValidation;
use GlpiPlugin\Pellissarisync\Clock;
use GlpiPlugin\Pellissarisync\Compat;
use GlpiPlugin\Pellissarisync\Guard;
use GlpiPlugin\Pellissarisync\Log;
use GlpiPlugin\Pellissarisync\Marker;
use GlpiPlugin\Pellissarisync\Mirror;
use GlpiPlugin\Pellissarisync\MirrorFollowup;
use GlpiPlugin\Pellissarisync\MirrorItem;
use RuntimeException;
use Ticket;
use TicketValidation;
use User;

/**
 * Applies inbound approval (validation) events.
 *
 * An approval only exists in GLPI if it has someone to answer it, and that
 * someone is identified across instances by e-mail. When the address matches a
 * local user, a real TicketValidation is created and the approval shows up in
 * their queue; when it does not, the event is recorded as a followup instead of
 * being dropped, so the timeline still tells the story.
 */
final class ValidationSync
{
    public const ITEMTYPE = TicketValidation::class;

    /**
     * @return array{ticketvalidations_id: int, as_followup: bool}
     */
    public static function create(Mirror $mirror, array $payload): array
    {
        $remoteId = (int) ($payload['remote_id'] ?? 0);
        if ($remoteId <= 0) {
            throw new RuntimeException('missing remote validation id');
        }

        $origin = TicketSync::remoteRole();

        $existing = MirrorItem::forRemoteItem($mirror->getID(), self::ITEMTYPE, $remoteId, $origin);
        if ($existing !== null) {
            return [
                'ticketvalidations_id' => (int) $existing->fields['items_id'],
                'as_followup'          => false,
            ];
        }

        $approver = self::localApprover($payload);

        if ($approver <= 0) {
            return self::asFollowup($mirror, $payload);
        }

        $tickets_id = (int) $mirror->fields['tickets_id'];

        $input = Compat::writeRichText(
            array_merge(
                Marker::timelineFlags(),
                Compat::validationTarget($approver),
                [
                    'tickets_id'         => $tickets_id,
                    'comment_submission' => Renderer::validationRequest($payload),
                    // Core stamps submission_date from the session clock and forces
                    // the status to WAITING on add; the answer arrives as an update.
                    'users_id'           => 0,
                ]
            ),
            ['comment_submission']
        );

        $validation = new TicketValidation();

        $id = Guard::run(Ticket::class, $tickets_id, static fn(): int => (int) $validation->add($input));

        if (!is_int($id) || $id <= 0) {
            Log::write('validation refused locally, recorded as followup', [
                'tickets_id' => $tickets_id,
                'remote_id'  => $remoteId,
            ]);

            return self::asFollowup($mirror, $payload);
        }

        $link = new MirrorItem();
        $link->add([
            'plugin_pellissarisync_mirrors_id' => $mirror->getID(),
            'itemtype'                         => self::ITEMTYPE,
            'items_id'                         => $id,
            'remote_items_id'                  => $remoteId,
            'origin'                           => $origin,
        ]);

        // The answer may already be in the payload when the approval is mirrored
        // late (a queued delivery that only left after it was answered).
        if (self::isAnswered($payload)) {
            self::applyAnswer($id, $payload);
        }

        return ['ticketvalidations_id' => $id, 'as_followup' => false];
    }

    /**
     * The answer: status plus the approver's comment.
     *
     * @param MirrorItem|null $link null when the approval itself fell back to a
     *                              followup here, in which case the answer does too
     *                              -- there is no local approval to answer.
     */
    public static function update(Mirror $mirror, ?MirrorItem $link, array $payload): bool
    {
        if ($link === null) {
            self::asFollowup($mirror, $payload, MirrorFollowup::SOURCE_VALIDATION_ANSWER);

            return true;
        }

        return self::applyAnswer((int) $link->fields['items_id'], $payload);
    }

    private static function applyAnswer(int $validations_id, array $payload): bool
    {
        $validation = new TicketValidation();
        if (!$validation->getFromDB($validations_id)) {
            return false;
        }

        $status = self::sanitizeStatus($payload['status'] ?? null);

        if ((int) $validation->fields['status'] === $status) {
            return true;
        }

        $input = Compat::writeRichText(
            array_merge(Marker::timelineFlags(), [
                'id'                 => $validations_id,
                'status'             => $status,
                'comment_validation' => self::answerComment($payload, $status),
                'validation_date'    => Clock::normalize($payload['validation_date'] ?? null) ?? Clock::now(),
            ]),
            ['comment_validation']
        );

        // As the approver: core drops the answer fields for anyone else.
        $result = Compat::asUser(
            self::approverOf($validation),
            static fn(): mixed => Guard::run(
                self::ITEMTYPE,
                $validations_id,
                static fn(): bool => (bool) $validation->update($input)
            )
        );

        return (bool) $result;
    }

    /**
     * The local user this approval is addressed to.
     */
    private static function approverOf(TicketValidation $validation): int
    {
        $users_id = (int) ($validation->fields['users_id_validate'] ?? 0);

        if ($users_id <= 0
            && (string) ($validation->fields['itemtype_target'] ?? '') === User::class
        ) {
            $users_id = (int) ($validation->fields['items_id_target'] ?? 0);
        }

        return $users_id;
    }

    /**
     * A refusal must carry a reason: core refuses the update without one
     * ("If approval is denied, specify a reason"). When the peer refused without
     * comment, the mirror states that instead of losing the answer.
     */
    private static function answerComment(array $payload, int $status): string
    {
        $comment = Renderer::validationAnswer($payload);

        if (trim(strip_tags($comment)) === '' && $status === CommonITILValidation::REFUSED) {
            return '<p>' . __('Recusado na outra ponta, sem justificativa informada.', 'pellissarisync') . '</p>';
        }

        return $comment;
    }

    /**
     * Fallback when no local user can answer the approval: the request and the
     * answer are still visible, just as timeline text.
     */
    private static function asFollowup(
        Mirror $mirror,
        array $payload,
        string $source = MirrorFollowup::SOURCE_VALIDATION
    ): array {
        $rendered = array_merge($payload, [
            'content'   => Renderer::validationAsText($payload),
            'remote_id' => (int) $payload['remote_id'],
            // Keeps the link out of the followup id space: the peer's approval #5 and
            // its followup #5 are different objects.
            '_source'   => $source,
        ]);

        Log::write('validation mirrored as followup (no local approver)', [
            'mirrors_id' => $mirror->getID(),
            'approver'   => (string) ($payload['approver']['identifier'] ?? ''),
        ]);

        $created = FollowupSync::create($mirror, $rendered);

        return [
            'ticketvalidations_id' => 0,
            'as_followup'          => true,
        ] + $created;
    }

    private static function isAnswered(array $payload): bool
    {
        return self::sanitizeStatus($payload['status'] ?? null) !== CommonITILValidation::WAITING;
    }

    /**
     * The user who must answer, resolved by e-mail. NONE/WAITING/ACCEPTED/REFUSED
     * are the same integers in GLPI 10 and 11.
     */
    private static function localApprover(array $payload): int
    {
        $email = trim((string) ($payload['approver']['identifier'] ?? ''));

        if ($email === '' || !str_contains($email, '@')) {
            return 0;
        }

        $user = new User();

        if (!$user->getFromDBbyEmail($email)) {
            return 0;
        }

        if ((int) $user->fields['is_deleted'] === 1 || (int) $user->fields['is_active'] !== 1) {
            return 0;
        }

        return (int) $user->getID();
    }

    private static function sanitizeStatus(mixed $value): int
    {
        $status = (int) $value;

        return in_array($status, [
            CommonITILValidation::NONE,
            CommonITILValidation::WAITING,
            CommonITILValidation::ACCEPTED,
            CommonITILValidation::REFUSED,
        ], true) ? $status : CommonITILValidation::WAITING;
    }
}
