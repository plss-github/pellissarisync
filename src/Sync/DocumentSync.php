<?php

namespace GlpiPlugin\Pellissarisync\Sync;

use Document;
use GlpiPlugin\Pellissarisync\Clock;
use GlpiPlugin\Pellissarisync\Config;
use GlpiPlugin\Pellissarisync\Guard;
use GlpiPlugin\Pellissarisync\Log;
use GlpiPlugin\Pellissarisync\Marker;
use GlpiPlugin\Pellissarisync\Mirror;
use GlpiPlugin\Pellissarisync\MirrorDocument;
use RuntimeException;
use Ticket;

/**
 * Attachment mirroring.
 *
 * The bytes travel base64-encoded inside the payload rather than as a second HTTP
 * round-trip, which keeps delivery atomic with the outbox retry: either the whole
 * attachment arrives or the entry stays pending.
 */
final class DocumentSync
{
    /**
     * Reads a local document so it can be pushed.
     *
     * @return array|null null when the file is unreadable or above the size limit
     */
    public static function pack(Document $document): ?array
    {
        $path = self::absolutePath((string) $document->fields['filepath']);

        if ($path === null || !is_readable($path)) {
            Log::write('attachment skipped: file not readable', [
                'documents_id' => $document->getID(),
                'filepath'     => $document->fields['filepath'] ?? '',
            ]);

            return null;
        }

        $size = (int) filesize($path);
        $max  = Config::maxDocumentBytes();

        if ($size > $max) {
            Log::write('attachment skipped: above size limit', [
                'documents_id' => $document->getID(),
                'size'         => $size,
                'max'          => $max,
            ]);

            return null;
        }

        $bytes = file_get_contents($path);
        if ($bytes === false) {
            return null;
        }

        return [
            'remote_id' => (int) $document->getID(),
            'filename'  => (string) ($document->fields['filename'] ?: basename($path)),
            'mime'      => (string) ($document->fields['mime'] ?? ''),
            'sha1sum'   => (string) ($document->fields['sha1sum'] ?? sha1($bytes)),
            'size'      => $size,
            'content'   => base64_encode($bytes),
        ];
    }

    /**
     * Materializes a received document and attaches it to the mirrored ticket.
     *
     * @return array{documents_id: int}
     */
    public static function create(Mirror $mirror, array $payload): array
    {
        $remoteId = (int) ($payload['remote_id'] ?? 0);
        if ($remoteId <= 0) {
            throw new RuntimeException('missing remote document id');
        }

        $origin = TicketSync::remoteRole();

        $existing = MirrorDocument::forRemoteDocument($mirror->getID(), $remoteId, $origin);
        if ($existing !== null) {
            return ['documents_id' => (int) $existing->fields['documents_id']];
        }

        $bytes = base64_decode((string) ($payload['content'] ?? ''), true);
        if ($bytes === false || $bytes === '') {
            throw new RuntimeException('empty or malformed attachment payload');
        }

        $filename = self::safeFilename((string) ($payload['filename'] ?? 'anexo'));

        // Document::moveDocument() reads from GLPI_TMP_DIR and refuses anything
        // containing a directory separator, so the temporary name is a plain
        // prefixed basename.
        $prefix = 'psync' . bin2hex(random_bytes(6)) . '_';
        $tmpDir = self::tmpDir();

        if (!is_dir($tmpDir) && !@mkdir($tmpDir, 0o770, true) && !is_dir($tmpDir)) {
            throw new RuntimeException('temporary directory is not available');
        }

        $tmpName = $prefix . $filename;
        $tmpPath = $tmpDir . '/' . $tmpName;

        if (file_put_contents($tmpPath, $bytes) === false) {
            throw new RuntimeException('could not write the attachment to the temporary directory');
        }

        // The extension must be allowed by the local glpi_documenttypes, otherwise
        // Document::add() silently refuses the upload.
        if (Document::isValidDoc($filename) === '') {
            @unlink($tmpPath);

            throw new RuntimeException(sprintf('file type not allowed locally: %s', $filename));
        }

        $tickets_id = (int) $mirror->fields['tickets_id'];

        $input = array_merge(Marker::documentFlags(), [
            'name'             => $filename,
            'entities_id'      => self::entityOf($tickets_id),
            'is_recursive'     => 0,
            '_filename'        => [$tmpName],
            '_prefix_filename' => [$prefix],
            'itemtype'         => Ticket::class,
            'items_id'         => $tickets_id,
            'date_creation'    => Clock::normalize($payload['date_creation'] ?? null) ?? Clock::now(),
        ]);

        $document = new Document();

        // Document::post_addItem() creates the Document_Item link itself and can
        // touch the parent status, hence the guard and the flags above.
        $documents_id = Guard::run(
            Ticket::class,
            $tickets_id,
            static fn(): int => (int) $document->add($input)
        );

        if (!is_int($documents_id) || $documents_id <= 0) {
            @unlink($tmpPath);

            throw new RuntimeException('local document creation failed');
        }

        $link = new MirrorDocument();
        $link->add([
            'plugin_pellissarisync_mirrors_id' => $mirror->getID(),
            'documents_id'                     => $documents_id,
            'remote_documents_id'              => $remoteId,
            'origin'                           => $origin,
        ]);

        Log::write('attachment mirrored', [
            'documents_id' => $documents_id,
            'remote_id'    => $remoteId,
            'tickets_id'   => $tickets_id,
        ]);

        return ['documents_id' => $documents_id];
    }

    private static function absolutePath(string $filepath): ?string
    {
        if ($filepath === '' || str_contains($filepath, '..')) {
            return null;
        }

        return GLPI_DOC_DIR . '/' . $filepath;
    }

    private static function tmpDir(): string
    {
        return defined('GLPI_TMP_DIR') ? GLPI_TMP_DIR : sys_get_temp_dir();
    }

    /**
     * Keeps the extension but strips anything that could escape the directory.
     */
    private static function safeFilename(string $filename): string
    {
        $filename = basename($filename);
        $filename = preg_replace('/[^A-Za-z0-9._-]+/', '_', $filename) ?? 'anexo';
        $filename = trim($filename, '._-');

        return $filename === '' ? 'anexo' : mb_substr($filename, 0, 180);
    }

    private static function entityOf(int $tickets_id): int
    {
        $ticket = new Ticket();

        return $ticket->getFromDB($tickets_id) ? (int) $ticket->fields['entities_id'] : 0;
    }
}
