<?php

namespace GlpiPlugin\Pellissarisync;

use CommonDBTM;
use Entity;
use GLPIKey;
use Session;

/**
 * A peer GLPI instance.
 *
 * On the master there is one row per customer agent; on an agent there is a
 * single row describing the master. Keeping both ends in the same table means
 * one credential path and one transport path instead of two.
 *
 * Deliberately NOT reusing core's Agent/glpi_agents, which models the inventory
 * agent (FusionInventory) and is unrelated.
 */
class Agent extends CommonDBTM
{
    public static $rightname = 'plugin_pellissarisync_agent';

    public $dohistory = true;

    public const STATUS_PENDING = 'pending';
    public const STATUS_LINKED  = 'linked';
    public const STATUS_REVOKED = 'revoked';

    public static function getTypeName($nb = 0)
    {
        return _n('Synchronized instance', 'Synchronized instances', $nb, 'pellissarisync');
    }

    public static function getIcon()
    {
        return 'ti ti-plug-connected';
    }

    /**
     * Rows are created by the handshake, never by hand.
     */
    public static function getForbiddenActionsForMenu()
    {
        return ['add'];
    }

    public function getName($options = [])
    {
        return $this->fields['name'] ?: ($this->fields['uuid'] ?? '');
    }

    // ---------------------------------------------------------------- lookups

    public static function findByUuid(string $uuid): ?self
    {
        if ($uuid === '') {
            return null;
        }

        $agent = new self();

        return $agent->getFromDBByCrit(['uuid' => $uuid]) ? $agent : null;
    }

    /**
     * The single row describing the master, used on the agent side.
     */
    public static function master(): ?self
    {
        $agent = new self();

        return $agent->getFromDBByCrit(['is_master' => 1]) ? $agent : null;
    }

    /**
     * The agent bound to an entity, used when the master originates a ticket.
     */
    public static function findByEntity(int $entities_id): ?self
    {
        $agent = new self();

        $found = $agent->getFromDBByCrit([
            'entities_id' => $entities_id,
            'is_active'   => 1,
            'link_status' => self::STATUS_LINKED,
        ]);

        return $found ? $agent : null;
    }

    // ------------------------------------------------------------ credentials

    /**
     * Secrets are encrypted at rest, mirroring what core does for
     * glpi_apiclients.app_token.
     */
    public static function encryptSecret(string $plain): string
    {
        return $plain === '' ? '' : (new GLPIKey())->encrypt($plain);
    }

    public function getAuthToken(): string
    {
        return (string) (new GLPIKey())->decrypt((string) ($this->fields['auth_token'] ?? ''));
    }

    public function getAuthSecret(): string
    {
        return (string) (new GLPIKey())->decrypt((string) ($this->fields['auth_secret'] ?? ''));
    }

    public function issueCredentials(): array
    {
        $token  = Config::newSecret();
        $secret = Config::newSecret();

        $this->update([
            'id'          => $this->getID(),
            'auth_token'  => self::encryptSecret($token),
            'auth_secret' => self::encryptSecret($secret),
        ]);

        return ['token' => $token, 'secret' => $secret];
    }

    public function isUsable(): bool
    {
        return (int) ($this->fields['is_active'] ?? 0) === 1
            && ($this->fields['link_status'] ?? '') === self::STATUS_LINKED;
    }

    public function getUrl(): string
    {
        return rtrim((string) ($this->fields['url'] ?? ''), '/');
    }

    // ----------------------------------------------------------------- status

    public function markContact(?int $http_code = null, ?string $error = null): void
    {
        $this->update([
            'id'               => $this->getID(),
            'last_contact'     => Clock::now(),
            'last_ping_status' => $http_code ?? 0,
            'last_error'       => $error ?? '',
            '_no_history'      => true,
            '_no_message'      => true,
        ]);
    }

    /**
     * Number of tickets mirrored through this agent.
     */
    public function countTickets(): int
    {
        return countElementsInTable(
            Mirror::getTable(),
            ['plugin_pellissarisync_agents_id' => $this->getID()]
        );
    }

    public function getEntityName(): string
    {
        $entities_id = (int) ($this->fields['entities_id'] ?? 0);

        $entity = new Entity();
        if ($entity->getFromDB($entities_id)) {
            return (string) $entity->fields['completename'];
        }

        return '';
    }

    /**
     * Human readable link state, for the listing screens.
     */
    public function getStatusLabel(): string
    {
        return match ($this->fields['link_status'] ?? '') {
            self::STATUS_LINKED  => __('Linked', 'pellissarisync'),
            self::STATUS_PENDING => __('Awaiting customer association', 'pellissarisync'),
            self::STATUS_REVOKED => __('Revoked', 'pellissarisync'),
            default              => __('Unknown', 'pellissarisync'),
        };
    }

    public function canViewItem(): bool
    {
        return Session::haveRight(self::$rightname, READ);
    }
}
