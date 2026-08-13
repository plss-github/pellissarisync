<?php

/**
 * List of mirrored tickets, with their peer and their synchronization state.
 */

if (!defined('GLPI_ROOT')) {
    include_once __DIR__ . '/../../../inc/includes.php';
}

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Pellissarisync\Agent;
use GlpiPlugin\Pellissarisync\Config;
use GlpiPlugin\Pellissarisync\Mirror;
use GlpiPlugin\Pellissarisync\Outbox;
use GlpiPlugin\Pellissarisync\Schema;

Session::checkRight(Schema::RIGHT_MIRROR, READ);

Html::header(
    Mirror::getTypeName(Session::getPluralNumber()),
    '',
    'plugins',
    Mirror::class
);

global $DB;

$mirrors    = Mirror::getTable();
$agents     = Agent::getTable();
$ticketsTbl = Ticket::getTable();

$rows = [];

$iterator = $DB->request([
    'SELECT' => [
        "$mirrors.id",
        "$mirrors.tickets_id",
        "$mirrors.remote_tickets_id",
        "$mirrors.origin",
        "$mirrors.client_name",
        "$mirrors.sync_state",
        "$mirrors.date_creation",
        "$ticketsTbl.name AS ticket_name",
        "$ticketsTbl.status AS ticket_status",
        "$agents.name AS agent_name",
        "$agents.id AS agent_id",
    ],
    'FROM'      => $mirrors,
    'LEFT JOIN' => [
        $ticketsTbl => [
            'ON' => [$mirrors => 'tickets_id', $ticketsTbl => 'id'],
        ],
        $agents => [
            'ON' => [$mirrors => 'plugin_pellissarisync_agents_id', $agents => 'id'],
        ],
    ],
    'ORDER' => "$mirrors.id DESC",
    'LIMIT' => 200,
]);

$statuses = Ticket::getAllStatusArray();

foreach ($iterator as $row) {
    // A purged mirror is a tombstone: the local ticket no longer exists, so there is
    // no status to show and nothing to link to.
    $row['is_purged']    = $row['sync_state'] === Mirror::STATE_PURGED;
    $row['status_label'] = $statuses[(int) $row['ticket_status']] ?? '';
    $row['origin_label'] = $row['origin'] === Config::ROLE_AGENT
        ? __('Customer (agent)', 'pellissarisync')
        : __('Support desk (master)', 'pellissarisync');

    $rows[] = $row;
}

TemplateRenderer::getInstance()->display('@pellissarisync/mirrors.html.twig', [
    'mirrors'       => $rows,
    'pending_count' => Outbox::countPending(),
    'is_master'     => Config::isMaster(),
]);

Html::footer();
