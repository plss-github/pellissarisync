<?php

namespace GlpiPlugin\Pellissarisync;

use CommonDBTM;

/**
 * Maps a local document to its copy on the peer.
 *
 * GLPI stores every attachment in the same `glpi_documents` table, so the two
 * instances necessarily use different ids for the same file. This table is what
 * lets one side say "the file you know as 42" and the other side resolve it.
 */
class MirrorDocument extends CommonDBTM
{
    public static $rightname = 'plugin_pellissarisync_mirror';

    public static function getTypeName($nb = 0)
    {
        return _n('Mirrored document', 'Mirrored documents', $nb, 'pellissarisync');
    }

    public static function forDocument(int $mirrors_id, int $documents_id): ?self
    {
        $link = new self();

        $found = $link->getFromDBByCrit([
            'plugin_pellissarisync_mirrors_id' => $mirrors_id,
            'documents_id'                     => $documents_id,
        ]);

        return $found ? $link : null;
    }

    public static function forRemoteDocument(int $mirrors_id, int $remote_documents_id, string $origin): ?self
    {
        $link = new self();

        $found = $link->getFromDBByCrit([
            'plugin_pellissarisync_mirrors_id' => $mirrors_id,
            'remote_documents_id'              => $remote_documents_id,
            'origin'                           => $origin,
        ]);

        return $found ? $link : null;
    }
}
