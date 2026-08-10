<?php

namespace GlpiPlugin\Pellissarisync;

use CronTask;
use DBConnection;
use ITILCategory;
use Migration;
use ProfileRight;
use Config as GlpiConfig;

/**
 * Install and uninstall.
 *
 * Table creation is raw SQL through $DB->doQuery(): GLPI 11 has no
 * Migration::addTable(), and $DB->query()/queryOrDie() now throw. Migration is
 * still used for the idempotent parts (added columns, rights, config).
 */
final class Schema
{
    public const RIGHT_AGENT  = 'plugin_pellissarisync_agent';
    public const RIGHT_MIRROR = 'plugin_pellissarisync_mirror';

    public static function install(): bool
    {
        global $DB;

        $migration = new Migration(PLUGIN_PELLISSARISYNC_VERSION);

        $charset   = DBConnection::getDefaultCharset();
        $collation = DBConnection::getDefaultCollation();
        $sign      = DBConnection::getDefaultPrimaryKeySignOption();

        $agents = Agent::getTable();
        if (!$DB->tableExists($agents)) {
            $DB->doQuery("
                CREATE TABLE `$agents` (
                    `id`                    int $sign NOT NULL AUTO_INCREMENT,
                    `entities_id`           int $sign NOT NULL DEFAULT '0',
                    `is_recursive`          tinyint NOT NULL DEFAULT '0',
                    `name`                  varchar(255) DEFAULT NULL,
                    `uuid`                  varchar(64) NOT NULL,
                    `client_name`           varchar(255) DEFAULT NULL,
                    `url`                   varchar(255) DEFAULT NULL,
                    `auth_token`            text,
                    `auth_secret`           text,
                    `is_master`             tinyint NOT NULL DEFAULT '0',
                    `is_active`             tinyint NOT NULL DEFAULT '1',
                    `link_status`           varchar(20) NOT NULL DEFAULT 'pending',
                    `last_contact`          timestamp NULL DEFAULT NULL,
                    `last_ping_status`      int NOT NULL DEFAULT '0',
                    `last_error`            text,
                    `remote_glpi_version`   varchar(50) DEFAULT NULL,
                    `remote_plugin_version` varchar(50) DEFAULT NULL,
                    `comment`               text,
                    `date_creation`         timestamp NULL DEFAULT NULL,
                    `date_mod`              timestamp NULL DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uuid` (`uuid`),
                    KEY `name` (`name`),
                    KEY `entities_id` (`entities_id`),
                    KEY `is_master` (`is_master`),
                    KEY `link_status` (`link_status`)
                ) ENGINE=InnoDB DEFAULT CHARSET = $charset COLLATE = $collation ROW_FORMAT=DYNAMIC;
            ");
        }

        $mirrors = Mirror::getTable();
        if (!$DB->tableExists($mirrors)) {
            $DB->doQuery("
                CREATE TABLE `$mirrors` (
                    `id`                              int $sign NOT NULL AUTO_INCREMENT,
                    `tickets_id`                      int $sign NOT NULL DEFAULT '0',
                    `remote_tickets_id`               int $sign NOT NULL DEFAULT '0',
                    `plugin_pellissarisync_agents_id` int $sign NOT NULL DEFAULT '0',
                    `origin`                          varchar(10) NOT NULL DEFAULT '',
                    `client_name`                     varchar(255) DEFAULT NULL,
                    `last_pushed_date_mod`            timestamp NULL DEFAULT NULL,
                    `last_received_status`            int NOT NULL DEFAULT '0',
                    `sync_state`                      varchar(20) NOT NULL DEFAULT '',
                    `date_creation`                   timestamp NULL DEFAULT NULL,
                    `date_mod`                        timestamp NULL DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `tickets_id` (`tickets_id`),
                    KEY `remote` (`plugin_pellissarisync_agents_id`,`remote_tickets_id`),
                    KEY `origin` (`origin`)
                ) ENGINE=InnoDB DEFAULT CHARSET = $charset COLLATE = $collation ROW_FORMAT=DYNAMIC;
            ");
        }

        $followups = MirrorFollowup::getTable();
        if (!$DB->tableExists($followups)) {
            // `remote` is intentionally NOT unique: rows created on the origin side
            // carry remote_followups_id = 0 until the peer acknowledges, and two
            // local followups on the same ticket would collide under a unique key.
            // Duplicate protection comes from the Inbox ledger plus the existence
            // check in FollowupSync::create().
            $DB->doQuery("
                CREATE TABLE `$followups` (
                    `id`                               int $sign NOT NULL AUTO_INCREMENT,
                    `itilfollowups_id`                 int $sign NOT NULL DEFAULT '0',
                    `remote_followups_id`              int $sign NOT NULL DEFAULT '0',
                    `plugin_pellissarisync_mirrors_id` int $sign NOT NULL DEFAULT '0',
                    `origin`                           varchar(10) NOT NULL DEFAULT '',
                    `source_itemtype`                  varchar(100) NOT NULL DEFAULT 'ITILFollowup',
                    `date_creation`                    timestamp NULL DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `local` (`source_itemtype`,`itilfollowups_id`),
                    KEY `remote` (`plugin_pellissarisync_mirrors_id`,`remote_followups_id`,`origin`)
                ) ENGINE=InnoDB DEFAULT CHARSET = $charset COLLATE = $collation ROW_FORMAT=DYNAMIC;
            ");
        }

        $documents = MirrorDocument::getTable();
        if (!$DB->tableExists($documents)) {
            $DB->doQuery("
                CREATE TABLE `$documents` (
                    `id`                               int $sign NOT NULL AUTO_INCREMENT,
                    `plugin_pellissarisync_mirrors_id` int $sign NOT NULL DEFAULT '0',
                    `documents_id`                     int $sign NOT NULL DEFAULT '0',
                    `remote_documents_id`              int $sign NOT NULL DEFAULT '0',
                    `origin`                           varchar(10) NOT NULL DEFAULT '',
                    `date_creation`                    timestamp NULL DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `local` (`plugin_pellissarisync_mirrors_id`,`documents_id`),
                    KEY `remote` (`plugin_pellissarisync_mirrors_id`,`remote_documents_id`,`origin`)
                ) ENGINE=InnoDB DEFAULT CHARSET = $charset COLLATE = $collation ROW_FORMAT=DYNAMIC;
            ");
        }

        // Upgrade path for installs created before solutions were mirrored: the
        // column tells a solution apart from a followup carrying the same id.
        $migration->addField($followups, 'source_itemtype', 'string', [
            'value' => MirrorFollowup::SOURCE_FOLLOWUP,
            'after' => 'origin',
        ]);

        if (!$DB->tableExists(Outbox::TABLE)) {
            $table = Outbox::TABLE;
            $DB->doQuery("
                CREATE TABLE `$table` (
                    `id`                              int $sign NOT NULL AUTO_INCREMENT,
                    `plugin_pellissarisync_agents_id` int $sign NOT NULL DEFAULT '0',
                    `action`                          varchar(64) NOT NULL DEFAULT '',
                    `payload`                         longtext,
                    `idempotency_key`                 varchar(64) NOT NULL,
                    `state`                           varchar(20) NOT NULL DEFAULT 'pending',
                    `tries`                           int NOT NULL DEFAULT '0',
                    `next_try_date`                   timestamp NULL DEFAULT NULL,
                    `last_status_code`                int NOT NULL DEFAULT '0',
                    `last_error`                      text,
                    `create_time`                     timestamp NULL DEFAULT NULL,
                    `sent_time`                       timestamp NULL DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `idempotency_key` (`idempotency_key`),
                    KEY `due` (`state`,`next_try_date`),
                    KEY `agent` (`plugin_pellissarisync_agents_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET = $charset COLLATE = $collation ROW_FORMAT=DYNAMIC;
            ");
        }

        if (!$DB->tableExists(Inbox::TABLE)) {
            $table = Inbox::TABLE;
            $DB->doQuery("
                CREATE TABLE `$table` (
                    `id`                              int $sign NOT NULL AUTO_INCREMENT,
                    `plugin_pellissarisync_agents_id` int $sign NOT NULL DEFAULT '0',
                    `idempotency_key`                 varchar(64) NOT NULL,
                    `action`                          varchar(64) NOT NULL DEFAULT '',
                    `received_date`                   timestamp NULL DEFAULT NULL,
                    `result`                          longtext,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `peer_key` (`plugin_pellissarisync_agents_id`,`idempotency_key`),
                    KEY `received_date` (`received_date`)
                ) ENGINE=InnoDB DEFAULT CHARSET = $charset COLLATE = $collation ROW_FORMAT=DYNAMIC;
            ");
        }

        $migration->addRight(self::RIGHT_AGENT, ALLSTANDARDRIGHT);
        $migration->addRight(self::RIGHT_MIRROR, READ | UPDATE);

        $migration->executeMigration();

        // Runs after executeMigration() so the new column already exists.
        // On installs created before solutions were mirrored the unique key was
        // on itilfollowups_id alone, which makes solution #N collide with
        // followup #N -- adding the column is not enough, the key must move too.
        self::reconcileFollowupKey($followups);

        self::seedConfig();
        self::registerCrons();

        return true;
    }

    public static function uninstall(): bool
    {
        global $DB;

        $tables = [
            Inbox::TABLE,
            Outbox::TABLE,
            MirrorDocument::getTable(),
            MirrorFollowup::getTable(),
            Mirror::getTable(),
            Agent::getTable(),
        ];

        foreach ($tables as $table) {
            if ($DB->tableExists($table)) {
                $DB->doQuery("DROP TABLE `$table`");
            }
        }

        ProfileRight::deleteProfileRights([self::RIGHT_AGENT, self::RIGHT_MIRROR]);

        GlpiConfig::deleteConfigurationValues(
            Config::CONTEXT,
            array_keys(Config::defaults())
        );

        // CronTask::Unregister() is called automatically by Plugin::uninstall().

        return true;
    }

    /**
     * Moves the followup uniqueness from `itilfollowups_id` to
     * (`source_itemtype`, `itilfollowups_id`).
     */
    private static function reconcileFollowupKey(string $table): void
    {
        global $DB;

        if (!$DB->tableExists($table)) {
            return;
        }

        if (self::hasIndex($table, 'itilfollowups_id')) {
            $DB->doQuery("ALTER TABLE `$table` DROP INDEX `itilfollowups_id`");
        }

        if (!self::hasIndex($table, 'local')) {
            $DB->doQuery("ALTER TABLE `$table` ADD UNIQUE KEY `local` (`source_itemtype`,`itilfollowups_id`)");
        }
    }

    private static function hasIndex(string $table, string $index): bool
    {
        global $DB;

        $result = $DB->doQuery(
            sprintf("SHOW INDEX FROM `%s` WHERE Key_name = %s", $table, $DB->quote($index))
        );

        return $result !== false && $result->num_rows > 0;
    }

    /**
     * Creates the configuration rows and the trigger category.
     */
    private static function seedConfig(): void
    {
        $current = GlpiConfig::getConfigurationValues(Config::CONTEXT);

        $values = [];
        foreach (Config::defaults() as $name => $default) {
            if (!array_key_exists($name, $current)) {
                $values[$name] = $default;
            }
        }

        if ($values !== []) {
            Config::set($values);
        }

        // Generated once and shown on the master's configuration screen; the
        // administrator copies it into each agent.
        Config::uuid();
        Config::enrollmentToken();

        $categories_id = self::ensureCategory();

        if ($categories_id > 0) {
            $stored = GlpiConfig::getConfigurationValues(Config::CONTEXT, [
                'trigger_itilcategories_id',
                'mirror_itilcategories_id',
            ]);

            $updates = [];
            if ((int) ($stored['trigger_itilcategories_id'] ?? 0) <= 0) {
                $updates['trigger_itilcategories_id'] = $categories_id;
            }
            if ((int) ($stored['mirror_itilcategories_id'] ?? 0) <= 0) {
                $updates['mirror_itilcategories_id'] = $categories_id;
            }

            if ($updates !== []) {
                Config::set($updates);
            }
        }
    }

    /**
     * Finds or creates `Suporte Pellissari - Fluídez Digital`.
     *
     * CommonTreeDropdown::import() walks and creates each level of the
     * completename, and returns the leaf id (-1 when nothing could be resolved).
     */
    public static function ensureCategory(): int
    {
        $category = new ITILCategory();

        $id = $category->import([
            'completename' => Config::DEFAULT_CATEGORY,
            'entities_id'  => 0,
            'is_recursive' => 1,
        ]);

        return $id > 0 ? (int) $id : 0;
    }

    private static function registerCrons(): void
    {
        CronTask::register(Cron::class, 'outbox', MINUTE_TIMESTAMP, [
            'state' => CronTask::STATE_WAITING,
            'mode'  => CronTask::MODE_EXTERNAL,
            'param' => 50,
        ]);

        CronTask::register(Cron::class, 'cleanup', DAY_TIMESTAMP, [
            'state' => CronTask::STATE_WAITING,
            'mode'  => CronTask::MODE_EXTERNAL,
            'param' => 30,
        ]);
    }
}
