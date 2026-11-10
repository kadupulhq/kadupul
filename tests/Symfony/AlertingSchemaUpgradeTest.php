<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * The 1.2.40 step: the native alerting tables (ADR 0011 decision 13). A fresh
 * install and an upgrade must give the same tables, a rerun must change
 * nothing, and a table the step cannot confirm must stop it before the
 * installer records the version. The database tests need
 * KADUPUL_TEST_MYSQL_DSN.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class AlertingSchemaUpgradeTest extends TestCase
{
    private const array TABLES = ['alert_baseline_sample', 'alert_incident', 'alert_notification', 'alert_rule', 'alert_rule_state'];

    private ?PDO $db = null;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 2);
        require_once $root . '/include/global_constants.php';
        require_once __DIR__ . '/Fixtures/forward_schema_repair_bridge.php';
        require_once $root . '/install/upgrades/1_2_40.php';
        $dsn = getenv('KADUPUL_TEST_MYSQL_DSN');
        if ($dsn === false || $dsn === '') {
            return;
        }
        $this->db = new PDO($dsn, getenv('KADUPUL_TEST_MYSQL_USER') ?: 'root', getenv('KADUPUL_TEST_MYSQL_PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT]);
        // The upgrade session (lib/database.php) relaxes sql_mode as the installer does.
        $this->db->exec("SET SESSION sql_mode = 'NO_ENGINE_SUBSTITUTION'");
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

    public function testFreshInstallAndUpgradeDefineTheSameTables(): void
    {
        $sql = (string) file_get_contents(dirname(__DIR__, 2) . '/cacti.sql');
        $definitions = upgrade_alerting_tables();
        self::assertSame(self::TABLES, array_values(array_intersect(self::TABLES, array_keys($definitions))));
        self::assertCount(5, $definitions);
        foreach ($definitions as $table => $definition) {
            self::assertStringStartsWith('CREATE TABLE IF NOT EXISTS ' . $table . ' (', $definition['create']);
            self::assertSame(self::normalized(self::freshInstall($sql, $table)), self::normalized($definition['create']), $table);
        }
    }

    public function testTheStepAddsOnlyTablesAndIsReachableAboveTheDeployedRelease(): void
    {
        $root = dirname(__DIR__, 2);
        self::assertSame('1.2.40', trim((string) file_get_contents($root . '/include/cacti_version')));
        self::assertMatchesRegularExpression("/^\\s*'1\\.2\\.39'\\s*=>\\s*'0103',\\n\\s*'1\\.2\\.40'\\s*=>\\s*'0103',\\n\\);/m", (string) file_get_contents($root . '/include/global_arrays.php'));
        // Off by default (decision 10): the step writes no setting, rule or
        // row; its only write is the realm grant of decision 12.
        $source = (string) file_get_contents($root . '/install/upgrades/1_2_40.php');
        $writes = preg_replace("/'INSERT IGNORE INTO user_auth_realm \\(realm_id, user_id\\) VALUES \\(\\?, 1\\)'/", '', $source, 1, $grants);
        self::assertSame(1, $grants);
        self::assertDoesNotMatchRegularExpression('/\b(INSERT|UPDATE|REPLACE|DELETE)\b/i', (string) $writes);
    }

    public function testAFreshInstallGrantsTheAlertingRealmsToTheAdminAccount(): void
    {
        $root = dirname(__DIR__, 2);
        $sql = (string) file_get_contents($root . '/cacti.sql');
        foreach ([29, 30] as $realm) {
            self::assertStringContainsString('INSERT INTO user_auth_realm VALUES (' . $realm . ',1);', $sql);
        }
        $arrays = (string) file_get_contents($root . '/include/global_arrays.php');
        self::assertStringContainsString("29   => __('Alert Rules'),", $arrays);
        self::assertStringContainsString("30   => __('Alert Incidents'),", $arrays);
    }

    public function testUpgradeGrantsTheAlertingRealmsToTheAdminAccountOnce(): void
    {
        $db = $this->database();
        $created = $this->realmTables();
        try {
            $db->exec('DELETE FROM user_auth_realm WHERE realm_id IN (29, 30)');
            upgrade_to_1_2_40();
            self::assertNotContains(DB_STATUS_ERROR, $GLOBALS['forward_schema_status']);
            self::assertSame('29,30', $this->scalar('SELECT GROUP_CONCAT(realm_id ORDER BY realm_id) FROM user_auth_realm WHERE user_id = 1 AND realm_id IN (29, 30)'));
            self::assertSame('0', $this->scalar('SELECT COUNT(*) FROM user_auth_realm WHERE user_id <> 1 AND realm_id IN (29, 30)'));

            // A rerun keeps the grants and adds none.
            upgrade_to_1_2_40();
            self::assertSame('2', $this->scalar('SELECT COUNT(*) FROM user_auth_realm WHERE realm_id IN (29, 30)'));

            // Without the admin account there is nobody to grant them to.
            $db->exec('DELETE FROM user_auth_realm WHERE realm_id IN (29, 30)');
            $db->exec('UPDATE user_auth SET id = 900 WHERE id = 1');
            upgrade_to_1_2_40();
            self::assertSame('0', $this->scalar('SELECT COUNT(*) FROM user_auth_realm WHERE realm_id IN (29, 30)'));
            $db->exec('UPDATE user_auth SET id = 1 WHERE id = 900');
        } finally {
            $db->exec('DELETE FROM user_auth_realm WHERE realm_id IN (29, 30)');
            foreach ($created as $table) {
                $db->exec('DROP TABLE IF EXISTS ' . $table);
            }
        }
    }

    public function testUpgradeCreatesTheTablesAFreshInstallCreates(): void
    {
        $db = $this->database();
        $sql = (string) file_get_contents(dirname(__DIR__, 2) . '/cacti.sql');
        $fresh = [];
        foreach (self::TABLES as $table) {
            self::assertNotFalse($db->exec(self::freshInstall($sql, $table)), $table);
            $fresh[$table] = $this->createStatement($table);
        }
        $this->drop();

        upgrade_to_1_2_40();

        self::assertNotContains(DB_STATUS_ERROR, $GLOBALS['forward_schema_status']);
        foreach (self::TABLES as $table) {
            self::assertSame($fresh[$table], $this->createStatement($table), $table);
            self::assertSame('0', $this->scalar('SELECT COUNT(*) FROM ' . $table), $table);
        }

        // A fresh install passes the step's own check unchanged, so a rerun keeps rows.
        self::assertNotFalse($db->exec("INSERT INTO alert_rule (local_data_id, rrd_name, comparator, trigger_threshold, clear_threshold) VALUES (5, 'traffic_in', 'gt', 90, 80)"));
        $GLOBALS['forward_schema_status'] = [];
        upgrade_to_1_2_40();
        self::assertNotContains(DB_STATUS_ERROR, $GLOBALS['forward_schema_status']);
        self::assertSame($fresh['alert_rule'], $this->createStatement('alert_rule'));
        self::assertSame('1', $this->scalar('SELECT COUNT(*) FROM alert_rule'));
    }

    public function testOneActiveIncidentPerRuleIsEnforcedByTheSchema(): void
    {
        $db = $this->database();
        upgrade_to_1_2_40();

        self::assertNotFalse($db->exec("INSERT INTO alert_incident (rule_id, status, active_rule_id, opened_at) VALUES (7, 'open', 7, 100)"));
        self::assertFalse($db->exec("INSERT INTO alert_incident (rule_id, status, active_rule_id, opened_at) VALUES (7, 'open', 7, 200)"), 'a second active incident for a rule');
        // Ended incidents leave active_rule_id NULL, and any number of them may coexist.
        self::assertNotFalse($db->exec("INSERT INTO alert_incident (rule_id, status, opened_at, ended_at) VALUES (7, 'closed', 10, 20), (7, 'orphaned', 30, 40)"));
        self::assertSame('3', $this->scalar('SELECT COUNT(*) FROM alert_incident WHERE rule_id = 7'));
    }

    public function testARetryAfterAPartialAttemptCompletesTheStep(): void
    {
        $db = $this->database();
        $sql = (string) file_get_contents(dirname(__DIR__, 2) . '/cacti.sql');
        self::assertNotFalse($db->exec(self::freshInstall($sql, 'alert_rule')));

        upgrade_to_1_2_40();

        self::assertNotContains(DB_STATUS_ERROR, $GLOBALS['forward_schema_status']);
        foreach (self::TABLES as $table) {
            self::assertSame('InnoDB', $this->scalar("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . $table . "'"), $table);
        }
    }

    public function testATableOfAnotherShapeStopsTheStepWithoutTouchingIt(): void
    {
        $db = $this->database();
        // No unique key, so it could hold two active incidents for a rule.
        self::assertNotFalse($db->exec('CREATE TABLE alert_incident (id int(10) unsigned NOT NULL auto_increment, rule_id int(10) unsigned NOT NULL, status varchar(16) NOT NULL, active_rule_id int(10) unsigned default NULL, opened_at int(10) unsigned NOT NULL, acknowledged_at int(10) unsigned default NULL, acknowledged_by mediumint(8) unsigned default NULL, ended_at int(10) unsigned default NULL, PRIMARY KEY (id), KEY rule_id (rule_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'));
        self::assertNotFalse($db->exec("INSERT INTO alert_incident (rule_id, status, active_rule_id, opened_at) VALUES (7, 'open', 7, 100)"));
        $before = $this->createStatement('alert_incident');

        try {
            upgrade_to_1_2_40();
            self::fail('The step accepted a table it did not define.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('alert_incident is missing or differs', $e->getMessage());
        }
        self::assertSame($before, $this->createStatement('alert_incident'));
        self::assertSame('1', $this->scalar('SELECT COUNT(*) FROM alert_incident'));
    }

    public function testATableThatCouldNotBeCreatedStopsTheStep(): void
    {
        $db = $this->database();
        // A view holds the name, so CREATE TABLE IF NOT EXISTS creates nothing.
        self::assertNotFalse($db->exec('CREATE VIEW alert_notification AS SELECT 1 AS id'));

        try {
            upgrade_to_1_2_40();
            self::fail('The step accepted a missing table.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('alert_notification is missing or differs', $e->getMessage());
        } finally {
            $db->exec('DROP VIEW IF EXISTS alert_notification');
        }
    }

    public function testASignedOrNullableColumnStopsTheStep(): void
    {
        $db = $this->database();
        self::assertNotFalse($db->exec('CREATE TABLE alert_rule_state (rule_id int(10) unsigned NOT NULL, incident_open tinyint(3) unsigned NOT NULL default 0, consecutive_breaches int(10) NOT NULL default 0, consecutive_oks int(10) unsigned NOT NULL default 0, is_unknown tinyint(3) unsigned NOT NULL default 0, last_observed_at int(10) unsigned default NULL, PRIMARY KEY (rule_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('alert_rule_state is missing or differs');
        upgrade_to_1_2_40();
    }

    /**
     * user_auth with the admin row and user_auth_realm, as cacti.sql creates
     * them, unless the database already has them.
     *
     * @return list<string> the tables this test created
     */
    private function realmTables(): array
    {
        $db = $this->database();
        $created = [];
        if ($this->scalar("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_auth'") === '0') {
            self::assertNotFalse($db->exec("CREATE TABLE user_auth (id mediumint(8) unsigned NOT NULL auto_increment, username varchar(50) NOT NULL default '0', PRIMARY KEY (id)) ENGINE=InnoDB"));
            $created[] = 'user_auth';
        }
        $db->exec("INSERT IGNORE INTO user_auth (id, username) VALUES (1, 'admin')");
        if ($this->scalar("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_auth_realm'") === '0') {
            self::assertNotFalse($db->exec("CREATE TABLE user_auth_realm (realm_id mediumint(8) unsigned NOT NULL default '0', user_id mediumint(8) unsigned NOT NULL default '0', PRIMARY KEY (realm_id, user_id), KEY user_id (user_id)) ENGINE=InnoDB"));
            $created[] = 'user_auth_realm';
        }

        return $created;
    }

    private function database(): PDO
    {
        if ($this->db === null) {
            self::markTestSkipped('Set KADUPUL_TEST_MYSQL_DSN to a task-owned database; the 1.2.40 upgrade was not run.');
        }

        return $this->db;
    }

    private function createStatement(string $table): string
    {
        $row = $this->database()->query('SHOW CREATE TABLE `' . $table . '`')->fetch(PDO::FETCH_NUM);
        self::assertIsArray($row, $table);

        // AUTO_INCREMENT=n follows the rows, not the definition.
        return (string) preg_replace('/ AUTO_INCREMENT=\d+/', '', (string) $row[1]);
    }

    private function scalar(string $sql): string
    {
        return (string) $this->database()->query($sql)->fetchColumn();
    }

    private function drop(): void
    {
        $this->db?->exec('DROP VIEW IF EXISTS alert_notification');
        foreach (self::TABLES as $table) {
            $this->db?->exec('DROP TABLE IF EXISTS ' . $table);
        }
    }

    private static function freshInstall(string $sql, string $table): string
    {
        self::assertSame(1, preg_match('/^CREATE TABLE `?' . $table . '`? \(.*?^\)[^;\n]*;/ms', $sql, $found), $table . ' must be created once in cacti.sql');

        return rtrim($found[0], ';');
    }

    private static function normalized(string $create): string
    {
        return (string) preg_replace('/\s+/', ' ', str_replace(['CREATE TABLE IF NOT EXISTS ', '`'], ['CREATE TABLE ', ''], $create));
    }
}
