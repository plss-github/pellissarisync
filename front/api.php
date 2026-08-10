<?php

/**
 * Machine-to-machine endpoint.
 *
 * Session-less on both supported GLPI versions, by two different mechanisms:
 *  - GLPI 11: plugin_pellissarisync_boot() registers this path as stateless, and
 *    the kernel has already booted by the time this file runs;
 *  - GLPI 10: the script is executed cold, and the $SECURITY_STRATEGY /
 *    $AJAX_INCLUDE globals below are the supported way to skip login and CSRF.
 *
 * The bootstrap has to be inline rather than in a helper class: on GLPI 10 the
 * plugin autoloader does not exist yet at this point.
 *
 * Authentication is entirely ApiServer's job, from the request headers.
 */

if (!defined('GLPI_ROOT')) {
    $AJAX_INCLUDE      = 1;
    $SECURITY_STRATEGY = 'no_check';
    include_once __DIR__ . '/../../../inc/includes.php';
}

use GlpiPlugin\Pellissarisync\ApiServer;
use GlpiPlugin\Pellissarisync\Compat;
use GlpiPlugin\Pellissarisync\Protocol\Envelope;

/**
 * Rebuilds the plugin headers from $_SERVER, avoiding getallheaders() which is
 * not available on every SAPI.
 */
$readHeader = static function (string $name): string {
    $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));

    return isset($_SERVER[$key]) ? trim((string) $_SERVER[$key]) : '';
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    return Compat::respondJson(['error' => 'POST required'], 405);
}

$action  = isset($_GET['action']) ? (string) $_GET['action'] : '';
$rawBody = (string) file_get_contents('php://input');

$headers = [
    Envelope::HEADER_UUID  => $readHeader(Envelope::HEADER_UUID),
    Envelope::HEADER_TOKEN => $readHeader(Envelope::HEADER_TOKEN),
    Envelope::HEADER_SIGN  => $readHeader(Envelope::HEADER_SIGN),
    Envelope::HEADER_IDEM  => $readHeader(Envelope::HEADER_IDEM),
];

$result = ApiServer::handle($action, $rawBody, $headers);

return Compat::respondJson($result['body'], $result['status']);
