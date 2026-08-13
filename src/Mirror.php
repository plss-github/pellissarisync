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

    public const STATE_QUEUED = 'queued';
    public const STATE_OK     = 'ok';

    /**
     * The local ticket was destroyed, so this row is a tombstone: it no longer
     * describes a mirror, only the fact that there used to be one. Kept instead of
     * deleted because the peer still has its copy and keeps addressing this ticket;
     * the row is what lets us recognise those events and answer them as a no-op.
     */
    public const STATE_PURGED = 'purged';

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

    public function isPurged(): bool
    {
        return ($this->fields['sync_state'] ?? '') === self::STATE_PURGED;
    }

    /**
     * Turns this row into a tombstone, because the local ticket was purged.
     *
     * The child links go for good: their local ids belonged to followups, tasks and
     * documents that core destroyed along with the ticket, so keeping them would
     * only let a later delivery be resolved to something that no longer exists.
     * `remote_tickets_id` stays, because it is the peer's side of the identity and
     * the only way to recognise what the peer sends from now on.
     */
    public function purge(): void
    {
        global $DB;

        $mirrors_id = $this->getID();

        foreach ([MirrorItem::getTable(), MirrorFollowup::getTable(), MirrorDocument::getTable()] as $table) {
            $DB->delete($table, ['plugin_pellissarisync_mirrors_id' => $mirrors_id]);
        }

        $this->update([
            'id'          => $mirrors_id,
            'sync_state'  => self::STATE_PURGED,
            '_no_history' => true,
            '_no_message' => true,
        ]);
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
