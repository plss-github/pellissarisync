<?php

namespace GlpiPlugin\Pellissarisync\Sync;

use CommonITILValidation;
use Glpi\RichText\RichText;
use GlpiPlugin\Pellissarisync\Clock;
use GlpiPlugin\Pellissarisync\Compat;
use GlpiPlugin\Pellissarisync\Config;

/**
 * Renders the mirrored side of a ticket or followup.
 *
 * Two rules drive everything here:
 *  - the header is rebuilt from the payload on every update, so re-rendering is
 *    idempotent and headers never stack up;
 *  - the author line is escaped, because `<email@host>` would otherwise be parsed
 *    as an HTML tag and vanish on display, while the peer's body is passed through
 *    RichText::getSafeHtml() since GLPI 11 does not sanitize on write.
 */
final class Renderer
{
    private const MAX_TITLE = 255;

    public static function title(string $originalName, string $clientName): string
    {
        $prefix = self::prefix($clientName);

        $title = trim($prefix . ' ' . trim($originalName));

        // glpi_tickets.name is varchar(255); core truncates silently, we do it
        // explicitly to keep the prefix intact.
        if (mb_strlen($title) > self::MAX_TITLE) {
            $title = mb_substr($title, 0, self::MAX_TITLE);
        }

        return $title;
    }

    private static function prefix(string $clientName): string
    {
        if (trim($clientName) === '') {
            return '';
        }

        $template = (string) Config::get('title_prefix_template', '[{client}]');
        if (trim($template) === '') {
            return '';
        }

        return str_replace('{client}', $clientName, $template);
    }

    /**
     * Header block plus the original body.
     *
     * @param array $payload the `author`/`date_creation`/`date_mod`/`content` keys
     */
    public static function content(array $payload): string
    {
        $body = RichText::getSafeHtml((string) ($payload['content'] ?? ''));

        // A solution arrives as a followup, flagged so the customer can tell which
        // message was the resolution rather than one more update. Only reachable
        // for a peer that predates real solution mirroring.
        if (!empty($payload['is_solution'])) {
            $body = '<p><strong>' . Compat::escape(__('Solução', 'pellissarisync')) . '</strong></p>' . $body;
        }

        return self::withHeader($payload, $body);
    }

    /**
     * A real solution needs no "Solução" label inside it: GLPI already presents it
     * as the resolution.
     */
    public static function solution(array $payload): string
    {
        return self::withHeader($payload, RichText::getSafeHtml((string) ($payload['content'] ?? '')));
    }

    /**
     * The approval request, as the approver will read it.
     */
    public static function validationRequest(array $payload): string
    {
        return self::withHeader(
            $payload,
            RichText::getSafeHtml((string) ($payload['comment_submission'] ?? ''))
        );
    }

    /**
     * The answer. Its author is the approver, not whoever asked, so the header is
     * rebuilt around them.
     */
    public static function validationAnswer(array $payload): string
    {
        $answer = array_merge($payload, [
            'author'        => (array) ($payload['approver'] ?? []),
            'date_creation' => $payload['validation_date'] ?? null,
            'date_mod'      => $payload['validation_date'] ?? null,
        ]);

        return self::withHeader(
            $answer,
            RichText::getSafeHtml((string) ($payload['comment_validation'] ?? ''))
        );
    }

    /**
     * Fallback rendering when the approval cannot exist locally (no user owns the
     * approver's address): the whole exchange becomes one timeline entry.
     */
    public static function validationAsText(array $payload): string
    {
        $approver = (array) ($payload['approver'] ?? []);

        $parts = [
            '<p><strong>' . Compat::escape(__('Aprovação', 'pellissarisync')) . '</strong></p>',
            '<p>' . Compat::escape(sprintf(
                '%s %s <%s>',
                __('Aprovador:', 'pellissarisync'),
                (string) ($approver['name'] ?? ''),
                (string) ($approver['identifier'] ?? '')
            )) . '</p>',
            '<p>' . Compat::escape(
                __('Situação:', 'pellissarisync') . ' ' . self::validationStatusLabel($payload['status'] ?? null)
            ) . '</p>',
            RichText::getSafeHtml((string) ($payload['comment_submission'] ?? '')),
        ];

        $answer = trim((string) ($payload['comment_validation'] ?? ''));
        if ($answer !== '') {
            $parts[] = '<hr />' . RichText::getSafeHtml($answer);
        }

        return implode('', $parts);
    }

    private static function validationStatusLabel(mixed $status): string
    {
        return match ((int) $status) {
            CommonITILValidation::ACCEPTED => __('aprovado', 'pellissarisync'),
            CommonITILValidation::REFUSED  => __('recusado', 'pellissarisync'),
            default                        => __('aguardando', 'pellissarisync'),
        };
    }

    /**
     * Prepends the origin header block to an already-safe body.
     */
    private static function withHeader(array $payload, string $body): string
    {
        $author     = (array) ($payload['author'] ?? []);
        $name       = (string) ($author['name'] ?? '');
        $identifier = (string) ($author['identifier'] ?? '');

        $created  = Clock::forHumans($payload['date_creation'] ?? null);
        $modified = Clock::forHumans($payload['date_mod'] ?? null);

        $lines = [];

        // These three labels are the agreed data format shown to the customer, not
        // UI chrome, so the source strings are the Portuguese ones the contract
        // specifies. They stay translatable, but the default output is correct
        // whatever language the instance runs in.
        $authorLine = $name !== '' || $identifier !== ''
            ? sprintf('%s %s <%s>', __('Autor:', 'pellissarisync'), $name, $identifier)
            : '';

        if ($authorLine !== '') {
            $lines[] = Compat::escape($authorLine);
        }

        if ($created !== '') {
            $lines[] = Compat::escape(__('Data de criação:', 'pellissarisync') . ' ' . $created);
        }

        // Only shown when the content was actually touched after creation.
        if ($modified !== '' && $modified !== $created) {
            $lines[] = Compat::escape(__('Data de modificação:', 'pellissarisync') . ' ' . $modified);
        }

        if ($lines === []) {
            return $body;
        }

        return '<div class="psync-origin">' . implode('<br />', $lines) . '</div><hr />' . $body;
    }
}
