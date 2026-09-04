<?php

namespace GlpiPlugin\Pellissarisync;

use Config as GlpiConfig;
use GLPIKey;

/**
 * Thin typed wrapper around the plugin's configuration context.
 *
 * Note on secured values: Config::setConfigurationValues() encrypts the keys
 * declared through Hooks::SECURED_CONFIGS, but Config::getConfigurationValues()
 * returns the raw stored (still encrypted) value. Decryption is therefore done
 * here explicitly -- reading those keys through the core API alone would hand
 * back ciphertext.
 */
final class Config
{
    public const CONTEXT = 'plugin:pellissarisync';

    public const ROLE_NONE   = '';
    public const ROLE_AGENT  = 'agent';
    public const ROLE_MASTER = 'master';

    /** Category that triggers mirroring on the agent side. */
    public const DEFAULT_CATEGORY = 'Suporte Pellissari - Fluídez Digital';

    private static ?array $cache = null;

    /**
     * Keys encrypted at rest and hidden from configuration dumps.
     */
    public static function securedKeys(): array
    {
        return ['enrollment_token'];
    }

    public static function defaults(): array
    {
        return [
            'role'                     => self::ROLE_NONE,
            'instance_uuid'            => '',
            'enrollment_token'         => '',
            // Agent side
            'master_url'               => '',
            // Base URL the master must use to reach this agent; falls back to
            // $CFG_GLPI['url_base'] when left empty.
            'own_url'                  => '',
            'trigger_itilcategories_id' => 0,
            'handshake_status'         => 'none',
            'last_handshake'           => '',
            'last_handshake_error'     => '',
            // Master side
            'mirror_itilcategories_id' => 0,
            'title_prefix_template'    => '[{client}]',
            // Default assignees for an inbound mirrored ticket, as comma-separated
            // ids. Overridden per agent when that agent has its own.
            'default_assign_users'     => '',
            'default_assign_groups'    => '',
            // Business rules on inbound mirrored tickets. On by default on the
            // master: the support desk wants its routing, SLA and assignment rules
            // to see a customer ticket like any other. Off on an agent, where a
            // mirrored ticket is a copy of something the desk already processed.
            'run_business_rules'       => 1,
            // Attachments travel base64-encoded inside the payload, so an upper
            // bound keeps a huge file from exhausting memory on either end.
            'max_document_bytes'       => 10 * 1024 * 1024,
        ];
    }

    public static function all(): array
    {
        if (self::$cache === null) {
            $stored = GlpiConfig::getConfigurationValues(self::CONTEXT);

            $glpikey = new GLPIKey();
            foreach (self::securedKeys() as $name) {
                if (!empty($stored[$name])) {
                    $stored[$name] = (string) $glpikey->decrypt($stored[$name]);
                }
            }

            self::$cache = array_replace(self::defaults(), $stored);
        }

        return self::$cache;
    }

    public static function get(string $name, mixed $default = null): mixed
    {
        return self::all()[$name] ?? $default;
    }

    public static function set(array $values): void
    {
        GlpiConfig::setConfigurationValues(self::CONTEXT, $values);
        self::$cache = null;
    }

    public static function role(): string
    {
        $role = (string) self::get('role', self::ROLE_NONE);

        return in_array($role, [self::ROLE_AGENT, self::ROLE_MASTER], true)
            ? $role
            : self::ROLE_NONE;
    }

    public static function isMaster(): bool
    {
        return self::role() === self::ROLE_MASTER;
    }

    /**
     * The role of the other end. Lives here rather than in the sync layer because
     * the link tables need it too: `origin` is stored as a role, so telling our own
     * rows from the peer's is a plain comparison against this value.
     */
    public static function remoteRole(): string
    {
        return self::isMaster() ? self::ROLE_AGENT : self::ROLE_MASTER;
    }

    public static function isAgent(): bool
    {
        return self::role() === self::ROLE_AGENT;
    }

    /**
     * Stable identifier of this GLPI instance, generated on first use.
     */
    public static function uuid(): string
    {
        $uuid = (string) self::get('instance_uuid', '');

        if ($uuid === '') {
            $uuid = bin2hex(random_bytes(16));
            self::set(['instance_uuid' => $uuid]);
        }

        return $uuid;
    }

    public static function enrollmentToken(): string
    {
        $token = (string) self::get('enrollment_token', '');

        if ($token === '') {
            $token = self::newSecret();
            self::set(['enrollment_token' => $token]);
        }

        return $token;
    }

    public static function regenerateEnrollmentToken(): string
    {
        $token = self::newSecret();
        self::set(['enrollment_token' => $token]);

        return $token;
    }

    public static function newSecret(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * The peer's base URL, without a trailing slash.
     */
    public static function masterUrl(): string
    {
        return rtrim((string) self::get('master_url', ''), '/');
    }

    /**
     * Category whose tickets are mirrored: the trigger on the agent, the
     * destination on the master.
     */
    public static function syncCategoryId(): int
    {
        return (int) (self::isMaster()
            ? self::get('mirror_itilcategories_id', 0)
            : self::get('trigger_itilcategories_id', 0));
    }

    /**
     * Whether the local business rules must run when a mirrored ticket is applied.
     *
     * Only ever true on the master: on an agent the ticket is a copy of something
     * the desk already routed, and letting the customer's rules rewrite it would
     * make the two ends disagree.
     */
    public static function runBusinessRules(): bool
    {
        return self::isMaster() && (int) self::get('run_business_rules', 1) === 1;
    }

    /**
     * Default assignees, as ids.
     *
     * @return array{users: list<int>, groups: list<int>}
     */
    public static function defaultAssignees(): array
    {
        return [
            'users'  => self::idList((string) self::get('default_assign_users', '')),
            'groups' => self::idList((string) self::get('default_assign_groups', '')),
        ];
    }

    /**
     * Parses a stored id list, dropping anything that is not a positive integer.
     *
     * @return list<int>
     */
    public static function idList(string $stored): array
    {
        $ids = [];

        foreach (explode(',', $stored) as $piece) {
            $id = (int) trim($piece);

            if ($id > 0 && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * Serializes an id list for storage.
     *
     * @param array<int|string> $ids
     */
    public static function packIdList(array $ids): string
    {
        $clean = [];

        foreach ($ids as $id) {
            $id = (int) $id;

            if ($id > 0 && !in_array($id, $clean, true)) {
                $clean[] = $id;
            }
        }

        return implode(',', $clean);
    }

    public static function maxDocumentBytes(): int
    {
        $max = (int) self::get('max_document_bytes', 0);

        return $max > 0 ? $max : 10 * 1024 * 1024;
    }

    public static function reset(): void
    {
        self::$cache = null;
    }
}
