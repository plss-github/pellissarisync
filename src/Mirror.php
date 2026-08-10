<?php

namespace GlpiPlugin\Pellissarisync;

use CommonDBTM;
use CommonGLPI;
use Glpi\Application\View\TemplateRenderer;
use Session;
use Ticket;

/**
 * Link between a local ticket and its counterpart on the peer instance.
 *
 * `origin` is the heart of the data-protection rule: it records which end created
 * the ticket, and therefore which end owns -- and may propagate -- its title and
 * description. Only the status is allowed to travel both ways.
 */
class Mirror extends CommonDBTM
{
    public static $rightname = 'plugin_pellissarisync_mirror';

    public static function getTypeName($nb = 0)
    {
        return _n('Mirrored ticket', 'Mirrored tickets', $nb, 'pellissarisync');
    }

    public static function getIcon()
    {
        return 'ti ti-arrows-left-right';
    }

    public static function getForbiddenActionsForMenu()
    {
        return ['add'];
    }

    // ---------------------------------------------------------------- lookups

    public static function forTicket(int $tickets_id): ?self
    {
        $mirror = new self();

        return $mirror->getFromDBByCrit(['tickets_id' => $tickets_id]) ? $mirror : null;
    }

    public static function forRemoteTicket(int $agents_id, int $remote_tickets_id): ?self
    {
        $mirror = new self();

        $found = $mirror->getFromDBByCrit([
            'plugin_pellissarisync_agents_id' => $agents_id,
            'remote_tickets_id'               => $remote_tickets_id,
        ]);

        return $found ? $mirror : null;
    }

    public function getAgent(): ?Agent
    {
        $agent = new Agent();
        $id    = (int) ($this->fields['plugin_pellissarisync_agents_id'] ?? 0);

        return $agent->getFromDB($id) ? $agent : null;
    }

    /**
     * True when the local instance owns the ticket's title and description.
     */
    public function isContentOwner(): bool
    {
        return ($this->fields['origin'] ?? '') === Config::role();
    }

    // -------------------------------------------------------------------- tab

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!$item instanceof Ticket || !Session::haveRight(self::$rightname, READ)) {
            return '';
        }

        if (self::forTicket($item->getID()) === null) {
            return '';
        }

        return self::createTabEntry(self::getTypeName(1), 0, Ticket::class, self::getIcon());
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if (!$item instanceof Ticket) {
            return false;
        }

        $mirror = self::forTicket($item->getID());
        if ($mirror === null) {
            return false;
        }

        $agent = $mirror->getAgent();

        TemplateRenderer::getInstance()->display('@pellissarisync/ticket_tab.html.twig', [
            'mirror'       => $mirror->fields,
            'agent'        => $agent?->fields,
            'agent_name'   => $agent?->getName() ?? '',
            'is_owner'     => $mirror->isContentOwner(),
            'local_role'   => Config::role(),
            'origin_label' => ($mirror->fields['origin'] ?? '') === Config::ROLE_AGENT
                ? __('Customer instance (agent)', 'pellissarisync')
                : __('Support desk (master)', 'pellissarisync'),
        ]);

        return true;
    }
}
