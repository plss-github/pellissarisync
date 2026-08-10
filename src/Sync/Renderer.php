<?php

namespace GlpiPlugin\Pellissarisync\Sync;

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

        $body = RichText::getSafeHtml((string) ($payload['content'] ?? ''));

        // A solution arrives as a followup, flagged so the customer can tell which
        // message was the resolution rather than one more update.
        if (!empty($payload['is_solution'])) {
            $body = '<p><strong>' . Compat::escape(__('Solução', 'pellissarisync')) . '</strong></p>' . $body;
        }

        if ($lines === []) {
            return $body;
        }

        return '<div class="psync-origin">' . implode('<br />', $lines) . '</div><hr />' . $body;
    }
}
