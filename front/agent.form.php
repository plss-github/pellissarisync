<?php

/**
 * Master screen: bind one agent to a customer entity and control its link.
 */

if (!defined('GLPI_ROOT')) {
    include_once __DIR__ . '/../../../inc/includes.php';
}

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Pellissarisync\Agent;
use GlpiPlugin\Pellissarisync\Assignees;
use GlpiPlugin\Pellissarisync\Config;
use GlpiPlugin\Pellissarisync\Ping;
use GlpiPlugin\Pellissarisync\Schema;

Session::checkRight(Schema::RIGHT_AGENT, READ);

$agent = new Agent();

if (isset($_POST['update'])) {
    Session::checkRight(Schema::RIGHT_AGENT, UPDATE);

    $id = (int) ($_POST['id'] ?? 0);

    if ($agent->getFromDB($id)) {
        $entities_id = (int) ($_POST['entities_id'] ?? 0);
        $status      = (string) ($_POST['link_status'] ?? Agent::STATUS_PENDING);

        // No constraint on the entity itself: a flat tree legitimately uses the
        // root entity. The safety property is that a new agent always arrives as
        // "pending" and only a human can move it to "linked".
        $agent->update([
            'id'          => $id,
            'name'        => (string) ($_POST['name'] ?? $agent->fields['name']),
            'entities_id' => $entities_id,
            'url'         => rtrim((string) ($_POST['url'] ?? ''), '/'),
            'client_name' => (string) ($_POST['client_name'] ?? ''),
            'is_active'   => (int) ($_POST['is_active'] ?? 0),
            'link_status' => $status,
            'comment'     => (string) ($_POST['comment'] ?? ''),
            // Empty means "use the global default", not "nobody".
            'assign_users'  => Config::packIdList((array) ($_POST['assign_users'] ?? [])),
            'assign_groups' => Config::packIdList((array) ($_POST['assign_groups'] ?? [])),
        ]);

        Session::addMessageAfterRedirect(__s('Agent updated.', 'pellissarisync'), true, INFO);
    }

    Html::back();
}

if (isset($_POST['ping'])) {
    Session::checkRight(Schema::RIGHT_AGENT, UPDATE);

    if ($agent->getFromDB((int) $_POST['ping'])) {
        $result = Ping::run($agent);

        Session::addMessageAfterRedirect(
            htmlescape($result['message']),
            true,
            $result['ok'] ? INFO : ERROR
        );
    }

    Html::back();
}

if (isset($_POST['revoke'])) {
    Session::checkRight(Schema::RIGHT_AGENT, UPDATE);

    if ($agent->getFromDB((int) $_POST['revoke'])) {
        // Revoking keeps the history but stops both directions immediately.
        $agent->update([
            'id'          => $agent->getID(),
            'link_status' => Agent::STATUS_REVOKED,
            'is_active'   => 0,
        ]);

        Session::addMessageAfterRedirect(__s('Agent revoked.', 'pellissarisync'), true, INFO);
    }

    Html::back();
}

$id = (int) ($_GET['id'] ?? 0);

if (!$agent->getFromDB($id)) {
    Html::displayNotFoundError();
}

Html::header(
    Agent::getTypeName(1),
    '',
    'plugins',
    Agent::class
);

TemplateRenderer::getInstance()->display('@pellissarisync/agent_form.html.twig', [
    'agent'    => $agent->fields,
    'tickets'  => $agent->countTickets(),
    'can_edit' => Session::haveRight(Schema::RIGHT_AGENT, UPDATE),
    // Same pickers as the global screen, see Assignees.
    'assign_users_field'  => Assignees::usersDropdown(
        'assign_users',
        Config::idList((string) ($agent->fields['assign_users'] ?? ''))
    ),
    'assign_groups_field' => Assignees::groupsDropdown(
        'assign_groups',
        Config::idList((string) ($agent->fields['assign_groups'] ?? ''))
    ),
    'uses_global_assignment' => Config::idList((string) ($agent->fields['assign_users'] ?? '')) === []
        && Config::idList((string) ($agent->fields['assign_groups'] ?? '')) === [],
]);

Html::footer();
