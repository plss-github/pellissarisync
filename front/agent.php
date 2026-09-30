<?php

/**
 * Master screen: the registered agents, their link state and their volume.
 */

if (!defined('GLPI_ROOT')) {
    include_once __DIR__ . '/../../../inc/includes.php';
}

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Pellissarisync\Agent;
use GlpiPlugin\Pellissarisync\Config;
use GlpiPlugin\Pellissarisync\Schema;

Session::checkRight(Schema::RIGHT_AGENT, READ);

Html::header(
    Agent::getTypeName(Session::getPluralNumber()),
    '',
    'plugins',
    Agent::class
);

if (!Config::isMaster()) {
    echo '<div class="alert alert-warning">'
        . __s('This screen is only meaningful on the master instance.', 'pellissarisync')
        . '</div>';
    Html::footer();
    return;
}

global $DB;

$rows = [];
foreach ($DB->request(['FROM' => Agent::getTable(), 'WHERE' => ['is_master' => 0], 'ORDER' => 'name ASC']) as $row) {
    $agent = new Agent();
    $agent->getFromResultSet($row);

    $rows[] = [
        'fields'       => $row,
        'entity_name'  => $agent->getEntityName(),
        'status_label' => $agent->getStatusLabel(),
        'tickets'      => $agent->countTickets(),
        'usable'       => $agent->isUsable(),
        // Changes waiting for this agent's next poll.
        'queued'       => $agent->countQueued(),
    ];
}

TemplateRenderer::getInstance()->display('@pellissarisync/agents.html.twig', [
    'agents'   => $rows,
    'can_edit' => Session::haveRight(Schema::RIGHT_AGENT, UPDATE),
]);

Html::footer();
