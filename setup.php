<?php

/**
 * Pellissari Sync - mirrors tickets between a customer GLPI (agent) and the
 * Pellissari support desk GLPI (master).
 *
 * A single codebase runs on both ends; the `role` configuration value decides
 * which screens and which triggers are active.
 */

use Glpi\Http\Firewall;
use Glpi\Http\SessionManager;
use Glpi\Plugin\Hooks;
use GlpiPlugin\Pellissarisync\Agent;
use GlpiPlugin\Pellissarisync\Config;
use GlpiPlugin\Pellissarisync\Hook;
use GlpiPlugin\Pellissarisync\Mirror;

define('PLUGIN_PELLISSARISYNC_VERSION', '1.4.1');

// Customers still run GLPI 10.0.x while the support desk runs 11, so both are
// supported and may be mirrored against each other. `max` is exclusive.
define('PLUGIN_PELLISSARISYNC_MIN_GLPI', '10.0');
define('PLUGIN_PELLISSARISYNC_MAX_GLPI', '12.0');

/**
 * URI pattern of the machine-to-machine endpoint, relative to the plugin root.
 */
define('PLUGIN_PELLISSARISYNC_API_PATTERN', '#^/front/api\.php#');

/**
 * GLPI 11 only -- the hook does not exist in GLPI 10, where front/api.php
 * declares itself session-less through $SECURITY_STRATEGY instead.
 *
 * Runs at kernel priority 140, i.e. BEFORE SessionStart (130). This is the only
 * place where a plugin can declare an endpoint as stateless: `plugin_init_*` runs
 * at 110, long after the session has started. Being stateless disables the
 * session and cookies, the firewall and the CSRF check for that path -- exactly
 * what a token-authenticated peer-to-peer endpoint needs.
 */
function plugin_pellissarisync_boot(): void
{
    if (!class_exists(SessionManager::class)) {
        return;
    }

    SessionManager::registerPluginStatelessPath('pellissarisync', PLUGIN_PELLISSARISYNC_API_PATTERN);

    // Without this, the fallback strategy for a plugin front script is
    // STRATEGY_AUTHENTICATED and the peer would get a redirect to the login page.
    Firewall::addPluginStrategyForLegacyScripts(
        'pellissarisync',
        PLUGIN_PELLISSARISYNC_API_PATTERN,
        Firewall::STRATEGY_NO_CHECK
    );
}

function plugin_version_pellissarisync(): array
{
    return [
        'name'         => 'Pellissari Sync',
        'version'      => PLUGIN_PELLISSARISYNC_VERSION,
        'author'       => 'Ampris',
        'license'      => 'GPLv3+',
        'homepage'     => '',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_PELLISSARISYNC_MIN_GLPI,
                'max' => PLUGIN_PELLISSARISYNC_MAX_GLPI,
            ],
            'php' => [
                'min'  => '8.2',
                'exts' => [
                    'sodium' => ['required' => true],
                    'curl'   => ['required' => true],
                ],
            ],
        ],
    ];
}

function plugin_pellissarisync_check_prerequisites(): bool
{
    return true;
}

/**
 * Keeps the plugin in the "to be configured" state until an administrator picks
 * a role, because nothing meaningful can happen before that choice.
 *
 * Uses core APIs only, on purpose: this runs from the CheckPluginsStates listener
 * at priority 150, i.e. BEFORE BootPlugins (140) registers the plugin's PSR-4
 * autoloader. Referencing a GlpiPlugin\Pellissarisync\* class here would fail to
 * load.
 */
function plugin_pellissarisync_check_config($verbose = false): bool
{
    $values = \Config::getConfigurationValues('plugin:pellissarisync', ['role']);
    $role   = (string) ($values['role'] ?? '');

    if (in_array($role, ['agent', 'master'], true)) {
        return true;
    }

    if ($verbose) {
        echo __('Choose whether this instance acts as an agent or as the master.', 'pellissarisync');
    }

    return false;
}

function plugin_init_pellissarisync(): void
{
    global $PLUGIN_HOOKS;

    // A throw in this function silently auto-deactivates the plugin, so every
    // database-dependent decision below is guarded.
    $PLUGIN_HOOKS[Hooks::SECURED_CONFIGS]['pellissarisync'] = Config::securedKeys();
    $PLUGIN_HOOKS[Hooks::CONFIG_PAGE]['pellissarisync']     = 'front/config.form.php';

    // Required by GLPI 10 for the plugin's own POST forms to be accepted;
    // ignored by GLPI 11, where CSRF is unconditional.
    $PLUGIN_HOOKS['csrf_compliant']['pellissarisync'] = true;

    Plugin::registerClass(Agent::class);
    Plugin::registerClass(Mirror::class, ['addtabon' => ['Ticket']]);

    try {
        $role = Config::role();
    } catch (Throwable $e) {
        // Database not ready (install/update in progress): stay inert.
        return;
    }

    if ($role === Config::ROLE_NONE) {
        return;
    }

    if ($role === Config::ROLE_MASTER) {
        // Under Administration rather than the generic "Plugins" section: these are
        // administration screens, and the sidebar entry reads as such.
        $PLUGIN_HOOKS[Hooks::MENU_TOADD]['pellissarisync'] = [
            'admin' => [Agent::class, Mirror::class],
        ];
    }

    // Plugin::doHook() keys on the exact class name and does not walk the class
    // hierarchy, so every itemtype must be registered separately -- TicketTask
    // rather than ITILTask, Ticket_User rather than CommonITILActor.
    $PLUGIN_HOOKS[Hooks::ITEM_ADD]['pellissarisync'] = [
        'Ticket'           => [Hook::class, 'onTicketAdd'],
        'ITILFollowup'     => [Hook::class, 'onFollowupAdd'],
        'ITILSolution'     => [Hook::class, 'onSolutionAdd'],
        'Document_Item'    => [Hook::class, 'onDocumentItemAdd'],
        'TicketTask'       => [Hook::class, 'onTaskAdd'],
        'TicketCost'       => [Hook::class, 'onCostAdd'],
        'TicketValidation' => [Hook::class, 'onValidationAdd'],
        'Ticket_User'      => [Hook::class, 'onActorAdd'],
    ];

    $PLUGIN_HOOKS[Hooks::ITEM_UPDATE]['pellissarisync'] = [
        'Ticket'           => [Hook::class, 'onTicketUpdate'],
        'ITILFollowup'     => [Hook::class, 'onFollowupUpdate'],
        'TicketTask'       => [Hook::class, 'onTaskUpdate'],
        'TicketCost'       => [Hook::class, 'onCostUpdate'],
        'TicketValidation' => [Hook::class, 'onValidationUpdate'],
    ];

    // The bin, both ways: a ticket deleted on one end must not stay open on the
    // other. Purge is deliberately not mirrored -- destroying data on the peer is
    // not something a mirror should be able to do.
    $PLUGIN_HOOKS[Hooks::ITEM_DELETE]['pellissarisync'] = [
        'Ticket' => [Hook::class, 'onTicketDelete'],
    ];

    $PLUGIN_HOOKS[Hooks::ITEM_RESTORE]['pellissarisync'] = [
        'Ticket' => [Hook::class, 'onTicketRestore'],
    ];

    // Purge of a TICKET is not propagated, but it has to be HANDLED. Core recomputes
    // the ticket while destroying its children -- removing the last assignee sends the
    // status back to "new" -- and those writes carry none of our markers, so they used
    // to leave as genuine changes and reopen the peer's copy. PRE_ITEM_PURGE locks the
    // ticket for the whole cascade; ITEM_PURGE closes the local link.
    //
    // Purge of a TIMELINE ITEM is a different matter and does travel, because these
    // have no bin: glpi_itilfollowups and the rest carry no is_deleted column, so the
    // delete button in the timeline purges outright. Ownership decides, as everywhere
    // else -- see Purge, which also refuses the purge of a copy the peer owns.
    $PLUGIN_HOOKS[Hooks::PRE_ITEM_PURGE]['pellissarisync'] = [
        'Ticket'           => [Hook::class, 'onTicketPrePurge'],
        'ITILFollowup'     => [Hook::class, 'onTimelinePrePurge'],
        'ITILSolution'     => [Hook::class, 'onTimelinePrePurge'],
        'TicketTask'       => [Hook::class, 'onTimelinePrePurge'],
        'TicketCost'       => [Hook::class, 'onTimelinePrePurge'],
        'TicketValidation' => [Hook::class, 'onTimelinePrePurge'],
    ];

    $PLUGIN_HOOKS[Hooks::ITEM_PURGE]['pellissarisync'] = [
        'Ticket'           => [Hook::class, 'onTicketPurge'],
        'ITILFollowup'     => [Hook::class, 'onTimelinePurge'],
        'ITILSolution'     => [Hook::class, 'onTimelinePurge'],
        'TicketTask'       => [Hook::class, 'onTimelinePurge'],
        'TicketCost'       => [Hook::class, 'onTimelinePurge'],
        'TicketValidation' => [Hook::class, 'onTimelinePurge'],
    ];
}
