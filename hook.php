<?php

/**
 * Install / uninstall entry points.
 *
 * Plugin::install() loads this file (self::load($dir, true)) before looking the
 * functions up, so declaring them here is enough.
 */

use GlpiPlugin\Pellissarisync\Schema;

function plugin_pellissarisync_install(array $params = []): bool
{
    return Schema::install();
}

function plugin_pellissarisync_uninstall(): bool
{
    return Schema::uninstall();
}
