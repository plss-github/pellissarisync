<?php

namespace GlpiPlugin\Pellissarisync;

use Toolbox;

/**
 * Plugin log, written to GLPI_LOG_DIR/pellissarisync.log.
 *
 * `$output = false` matters: the machine endpoint returns a Symfony Response and
 * any extra output would both corrupt the JSON and raise "Unexpected output
 * detected" from LegacyFileLoadController.
 */
final class Log
{
    public static function write(string $message, array $context = []): void
    {
        $line = $message;

        if ($context !== []) {
            $line .= ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        Toolbox::logInFile('pellissarisync', $line . "\n", false, false);
    }
}
