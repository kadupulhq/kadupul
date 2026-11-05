<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Kadupul\Tests\Symfony;

use PDO;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * #927: user_log.user_id, user_auth_row_cache.user_id and
 * settings_user_group.group_id must hold every id of the table they refer to,
 * in a new install and after the 1.2.38 upgrade. The database tests need
 * KADUPUL_TEST_MYSQL_DSN.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class AccountIdColumnWidthUpgradeTest extends TestCase
{
    /** @var array<string, string> the column of each table, as 1.2.37 created it */
    private const array OLD = [
        'user_log' => "CREATE TABLE user_log (
  `username` varchar(50) NOT NULL default '0',
  `user_id` mediumint(8) NOT NULL default '0',
  `time` timestamp NOT NULL default '0000-00-00 00:00:00',
  `result` tinyint(3) unsigned NOT NULL default '0',
  `ip` varchar(40) NOT NULL default '',
  PRIMARY KEY (`username`,`user_id`,`time`),
  KEY user_id (`user_id`)
) ENGINE=InnoDB ROW_FORMAT=Dynamic",
        'user_auth_row_cache' => "CREATE TABLE user_auth_row_cache (
  `user_id` mediumint(8) NOT NULL default '0',
  `class` varchar(20) NOT NULL default '',
  `hash` varchar(32) NOT NULL default '0',
  `total_rows` int(10) unsigned NOT NULL default '0',
  `time` timestamp NOT NULL default CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`,`class`,`hash`),
  KEY `class_time` (`class`,`time`)
) ENGINE=InnoDB ROW_FORMAT=Dynamic",
        'settings_user_group' => "CREATE TABLE settings_user_group (
  group_id smallint(8) unsigned NOT NULL DEFAULT '0',
  name varchar(50) NOT NULL DEFAULT '',
  value varchar(2048) NOT NULL DEFAULT '',
  PRIMARY KEY (group_id, name)
) ENGINE=InnoDB ROW_FORMAT=Dynamic COMMENT='Stores the Default User Group Graph Settings'",
    ];

    private const array COLUMNS = ['user_log' => 'user_id', 'user_auth_row_cache' => 'user_id', 'settings_user_group' => 'group_id'];

    /** Tables upgrade_to_1_2_38() creates for Boost, removed after each run. */
    private const array BOOST = ['poller_output_boost_processes', 'kadupul_messenger_messages'];

    private ?PDO $db = null;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 2);
        require_once $root . '/include/global_constants.php';
        require_once __DIR__ . '/Fixtures/forward_schema_repair_bridge.php';
        require_once $root . '/install/upgrades/1_2_38.php';
        $dsn = getenv('KADUPUL_TEST_MYSQL_DSN');
        if ($dsn === false || $dsn === '') {
            return;
        }
        $this->db = new PDO($dsn, getenv('KADUPUL_TEST_MYSQL_USER') ?: 'root', getenv('KADUPUL_TEST_MYSQL_PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT]);
        // The upgrade session relaxes sql_mode, so a value the column cannot hold is clamped.
        $this->db->exec("SET SESSION sql_mode = ''");
        $GLOBALS['forward_schema_pdo'] = $this->db;
        $GLOBALS['forward_schema_status'] = [];
        $this->drop();
    }

    protected function tearDown(): void
    {
        if ($this->db !== null) {
            $this->drop();
        }
    }

    public function testFreshInstallGivesEachColumnTheTypeOfItsId(): void
    {
        $sql = (string) file_get_contents(dirname(__DIR__, 2) . '/cacti.sql');
        $user = self::integerType(self::column($sql, 'user_auth', 'id'));
        $group = self::integerType(self::column($sql, 'user_auth_group', 'id'));

        self::assertSame('mediumint(8) unsigned', $user);
        self::assertSame('int(10) unsigned', $group);
        self::assertSame($user, self::integerType(self::column($sql, 'user_log', 'user_id')));
        self::assertSame($user, self::integerType(self::column($sql, 'user_auth_row_cache', 'user_id')));
        self::assertSame($group, self::integerType(self::column($sql, 'settings_user_group', 'group_id')));

        $audit = (string) file_get_contents(dirname(__DIR__, 2) . '/docs/audit_schema.sql');
        self::assertStringContainsString("('user_log',2,'user_id','mediumint(8) unsigned','NO','PRI','0','',NULL);", $audit);
        self::assertStringContainsString("('user_auth_row_cache',1,'user_id','mediumint(8) unsigned','NO','PRI','0','',NULL);", $audit);
        self::assertStringContainsString("('settings_user_group',1,'group_id','int(10) unsigned','NO','PRI','0','',NULL);", $audit);
    }

    public function testUpgradeWidensTheColumnsAndKeepsEveryRow(): void
    {
        $db = $this->database();
        $this->createOld();
        $before = $this->rows();

        upgrade_to_1_2_38();

        self::assertNotContains(DB_STATUS_ERROR, $GLOBALS['forward_schema_status']);
        self::assertMatchesRegularExpression('/^mediumint(\(8\))? unsigned$/', $this->type('user_log'));
        self::assertMatchesRegularExpression('/^mediumint(\(8\))? unsigned$/', $this->type('user_auth_row_cache'));
        self::assertMatchesRegularExpression('/^int(\(10\))? unsigned$/', $this->type('settings_user_group'));
        self::assertSame($before, $this->rows());
        foreach (array_keys(self::COLUMNS) as $table) {
            self::assertStringContainsString('PRIMARY KEY', $this->create($table), $table);
        }

        // The widened columns hold ids the old ones clamped.
        self::assertNotFalse($db->exec("INSERT INTO user_log (username, user_id, time, result, ip) VALUES ('bigger', 9000000, '2026-01-01 00:00:03', 1, '192.0.2.4')"));
        self::assertSame('9000000', $this->value("SELECT user_id FROM user_log WHERE username = 'bigger'"));
        self::assertNotFalse($db->exec("INSERT INTO settings_user_group (group_id, name, value) VALUES (70000, 'selected_theme', 'classic')"));
        self::assertSame('classic', $this->value('SELECT value FROM settings_user_group WHERE group_id = 70000'));
        self::assertSame('dark', $this->value('SELECT value FROM settings_user_group WHERE group_id = 65535'));

        $widened = array_map($this->create(...), array_keys(self::COLUMNS));
        $GLOBALS['forward_schema_status'] = [];
        upgrade_to_1_2_38();
        self::assertNotContains(DB_STATUS_ERROR, $GLOBALS['forward_schema_status']);
        self::assertSame($widened, array_map($this->create(...), array_keys(self::COLUMNS)));
    }

    public function testUpgradeLeavesTablesCreatedFromCactiSqlUnchanged(): void
    {
        $db = $this->database();
        $sql = (string) file_get_contents(dirname(__DIR__, 2) . '/cacti.sql');
        foreach (array_keys(self::COLUMNS) as $table) {
            self::assertSame(1, preg_match('/^CREATE TABLE `?' . $table . '`? \(.*?^\)[^;]*;/ms', $sql, $found), $table);
            self::assertNotFalse($db->exec(rtrim($found[0], ';')), $table);
        }
        $before = array_map($this->create(...), array_keys(self::COLUMNS));

        widen_columns_with_statements(function (): void {
            upgrade_widen_account_id_columns();
        }, $alters);

        self::assertSame([], $alters);
        self::assertSame($before, array_map($this->create(...), array_keys(self::COLUMNS)));
    }

    public function testNegativeIdStopsTheUpgradeAndKeepsTheColumn(): void
    {
        $db = $this->database();
        $this->createOld();
        self::assertNotFalse($db->exec("INSERT INTO user_log (username, user_id, time, result, ip) VALUES ('odd', -5, '2026-01-01 00:00:04', 0, '192.0.2.5')"));

        try {
            upgrade_widen_account_id_columns();
            self::fail('A negative id must stop the upgrade.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('user_log.user_id holds negative ids', $error->getMessage());
        }

        self::assertMatchesRegularExpression('/^mediumint(\(8\))?$/', $this->type('user_log'));
        self::assertSame('-5', $this->value("SELECT user_id FROM user_log WHERE username = 'odd'"));
    }

    private function createOld(): void
    {
        $db = $this->database();
        foreach (self::OLD as $table => $ddl) {
            self::assertNotFalse($db->exec($ddl), $table);
        }
        self::assertNotFalse($db->exec("INSERT INTO user_log (username, user_id, time, result, ip) VALUES ('admin', 1, '2026-01-01 00:00:00', 1, '192.0.2.1'), ('unknown', 0, '2026-01-01 00:00:01', 0, '192.0.2.2'), ('big', 8388607, '2026-01-01 00:00:02', 1, '192.0.2.3')"));
        self::assertNotFalse($db->exec("INSERT INTO user_auth_row_cache (user_id, class, hash, total_rows) VALUES (1, 'graphs', 'a', 10), (8388607, 'graphs', 'b', 20)"));
        self::assertNotFalse($db->exec("INSERT INTO settings_user_group (group_id, name, value) VALUES (1, 'selected_theme', 'modern'), (65535, 'selected_theme', 'dark')"));
    }

    /** @return list<list<array<string, mixed>>> */
    private function rows(): array
    {
        return [
            $this->database()->query('SELECT username, user_id, result FROM user_log ORDER BY username')->fetchAll(PDO::FETCH_ASSOC),
            $this->database()->query('SELECT user_id, class, hash, total_rows FROM user_auth_row_cache ORDER BY user_id, hash')->fetchAll(PDO::FETCH_ASSOC),
            $this->database()->query('SELECT group_id, name, value FROM settings_user_group ORDER BY group_id')->fetchAll(PDO::FETCH_ASSOC),
        ];
    }

    private function type(string $table): string
    {
        $statement = $this->database()->prepare('SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $statement->execute([$table, self::COLUMNS[$table]]);

        return strtolower((string) $statement->fetchColumn());
    }

    private function create(string $table): string
    {
        return (string) $this->database()->query('SHOW CREATE TABLE `' . $table . '`')->fetch(PDO::FETCH_NUM)[1];
    }

    private function value(string $sql): string
    {
        return (string) $this->database()->query($sql)->fetchColumn();
    }

    private function database(): PDO
    {
        if ($this->db === null) {
            self::markTestSkipped('Set KADUPUL_TEST_MYSQL_DSN to a task-owned database; the 1.2.38 upgrade was not run.');
        }

        return $this->db;
    }

    private function drop(): void
    {
        foreach ([...array_keys(self::COLUMNS), ...self::BOOST] as $table) {
            $this->db?->exec('DROP TABLE IF EXISTS `' . $table . '`');
        }
    }

    private static function column(string $sql, string $table, string $column): string
    {
        self::assertSame(1, preg_match('/^CREATE TABLE `?' . $table . '`? \((.*?)^\)/ms', $sql, $body), $table);
        self::assertSame(1, preg_match('/^\s*`?' . $column . '`?\s+(.+?),?$/m', $body[1], $definition), $table . '.' . $column);

        return $definition[1];
    }

    private static function integerType(string $definition): string
    {
        preg_match('/^(tinyint|smallint|mediumint|int|bigint)\((\d+)\)( unsigned)?/i', $definition, $type);

        return strtolower($type[0] ?? '');
    }
}

/** Run a block and collect the ALTER statements the install bridge executed. */
function widen_columns_with_statements(callable $block, ?array &$alters): void
{
    $before = count($GLOBALS['forward_schema_status']);
    $block();
    $alters = array_slice($GLOBALS['forward_schema_status'], $before);
}
