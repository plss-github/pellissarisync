<?php

/**
 * Role-aware configuration screen.
 *
 * CSRF is validated by the kernel for every POST on this (non-stateless) path, so
 * Session::checkCSRF() must NOT be called here -- it would consume the token a
 * second time and fail.
 */

if (!defined('GLPI_ROOT')) {
    // GLPI 10 runs plugin front scripts cold; GLPI 11 has already booted.
    include_once __DIR__ . '/../../../inc/includes.php';
}

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Pellissarisync\Agent;
use GlpiPlugin\Pellissarisync\ApiServer;
use GlpiPlugin\Pellissarisync\Assignees;
use GlpiPlugin\Pellissarisync\Config;
use GlpiPlugin\Pellissarisync\Handshake;
use GlpiPlugin\Pellissarisync\Outbox;
use GlpiPlugin\Pellissarisync\Ping;
use GlpiPlugin\Pellissarisync\Schema;
use GlpiPlugin\Pellissarisync\TestTicket;

Session::checkRight('config', UPDATE);

if (isset($_POST['update'])) {
    $values = [
        'role'                  => (string) ($_POST['role'] ?? Config::ROLE_NONE),
        'title_prefix_template' => (string) ($_POST['title_prefix_template'] ?? '[{client}]'),
    ];

    if ($values['role'] === Config::ROLE_AGENT) {
        $values['master_url']                = (string) ($_POST['master_url'] ?? '');
        $values['own_url']                   = (string) ($_POST['own_url'] ?? '');
        $values['enrollment_token']          = (string) ($_POST['enrollment_token'] ?? '');
        $values['trigger_itilcategories_id'] = (int) ($_POST['trigger_itilcategories_id'] ?? 0);
    } else {
        $values['mirror_itilcategories_id'] = (int) ($_POST['mirror_itilcategories_id'] ?? 0);
        $values['run_business_rules']       = (int) ($_POST['run_business_rules'] ?? 0);

        // The dropdowns post arrays; ids are stored comma-separated, as core does for
        // the multi-value fields of a notification target.
        $values['default_assign_users']  = Config::packIdList((array) ($_POST['default_assign_users'] ?? []));
        $values['default_assign_groups'] = Config::packIdList((array) ($_POST['default_assign_groups'] ?? []));
    }

    Config::set($values);

    Session::addMessageAfterRedirect(__s('Configuration saved.', 'pellissarisync'), true, INFO);
    Html::back();
}

if (isset($_POST['connect'])) {
    $result = Handshake::connect();

    Session::addMessageAfterRedirect(
        htmlescape($result['message']),
        true,
        $result['ok'] ? INFO : ERROR
    );
    Html::back();
}

if (isset($_POST['ping_master'])) {
    $master = Agent::master();

    if ($master === null) {
        Session::addMessageAfterRedirect(
            __s('Connect to the master first.', 'pellissarisync'),
            true,
            ERROR
        );
    } else {
        $result = Ping::run($master);

        Session::addMessageAfterRedirect(
            htmlescape($result['message']),
            true,
            $result['ok'] ? INFO : ERROR
        );
    }

    Html::back();
}

if (isset($_POST['regen_token'])) {
    Config::regenerateEnrollmentToken();

    Session::addMessageAfterRedirect(
        __s('A new enrollment token was generated. Existing agents keep working; new ones must use it.', 'pellissarisync'),
        true,
        INFO
    );
    Html::back();
}

if (isset($_POST['ensure_category'])) {
    $id = Schema::ensureCategory();

    if ($id > 0) {
        Config::set(Config::isMaster()
            ? ['mirror_itilcategories_id' => $id]
            : ['trigger_itilcategories_id' => $id]);

        Session::addMessageAfterRedirect(__s('Category created and selected.', 'pellissarisync'), true, INFO);
    } else {
        Session::addMessageAfterRedirect(__s('Could not create the category.', 'pellissarisync'), true, ERROR);
    }
    Html::back();
}

if (isset($_POST['test_ticket'])) {
    $result = TestTicket::create();

    Session::addMessageAfterRedirect(
        htmlescape($result['message']),
        true,
        $result['ok'] ? INFO : ERROR
    );
    Html::back();
}

Html::header(
    __('Pellissari Sync', 'pellissarisync'),
    '',
    'config',
    'plugins'
);

$master = Config::isAgent() ? Agent::master() : null;

TemplateRenderer::getInstance()->display('@pellissarisync/config.html.twig', [
    // The assignee pickers are built here rather than through the Twig field macros:
    // they need `multiple`, the `own_ticket` right filter and the is_assign
    // condition, which the generic macros do not forward, and the same call has to
    // work on GLPI 10 and 11.
    'assign_users_field'  => Assignees::usersDropdown(
        'default_assign_users',
        Config::idList((string) Config::get('default_assign_users', ''))
    ),
    'assign_groups_field' => Assignees::groupsDropdown(
        'default_assign_groups',
        Config::idList((string) Config::get('default_assign_groups', ''))
    ),
    'config'           => Config::all(),
    'role'             => Config::role(),
    'is_master'        => Config::isMaster(),
    'is_agent'         => Config::isAgent(),
    'local_uuid'       => Config::uuid(),
    'enrollment_token' => Config::enrollmentToken(),
    'master_agent'     => $master?->fields,
    'pending_count'    => Outbox::countPending(),
    'status'           => ApiServer::pong(),
    'agents_count'     => Config::isMaster() ? countElementsInTable(Agent::getTable(), ['is_master' => 0]) : 0,
]);

Html::footer();
