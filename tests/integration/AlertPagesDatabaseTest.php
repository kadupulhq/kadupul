<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/*
 * The rule and incident page stores on MariaDB or MySQL, over the shipped
 * cacti.sql tables in a schema this test owns and drops: the real realm
 * checks (DbalRuleAccess, DbalIncidentAccess), the real device visibility
 * projection (DbalManagementVisibility), InnoDB row locks and the audit
 * sink in a private directory.
 */

namespace AlertPagesDatabaseTest;

$applicationLoader = require dirname(__DIR__, 2) . '/include/vendor/autoload.php';
$applicationLoader->unregister();
$applicationLoader->register(false);

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Kadupul\Alerting\Application\Port\RuleStore;
use Kadupul\Alerting\Application\Query\AcknowledgeOutcome;
use Kadupul\Alerting\Application\Query\AlertingAccessDenied;
use Kadupul\Alerting\Application\Rule\RuleDefinition;
use Kadupul\Alerting\Application\Rule\RuleSubmission;
use Kadupul\Alerting\Application\Rule\RuleSubmissionRefused;
use Kadupul\Alerting\Infrastructure\Dbal\DbalIncidentAccess;
use Kadupul\Alerting\Infrastructure\Dbal\DbalIncidentCatalog;
use Kadupul\Alerting\Infrastructure\Dbal\DbalIncidentStore;
use Kadupul\Alerting\Infrastructure\Dbal\DbalRuleAccess;
use Kadupul\Alerting\Infrastructure\Dbal\DbalRuleCatalog;
use Kadupul\Alerting\Infrastructure\Dbal\DbalRuleStore;
use Kadupul\Alerting\Infrastructure\Dbal\SeriesVisibility;
use Kadupul\Alerting\Domain\Rule\Comparator;
use Kadupul\Alerting\Domain\Rule\SeriesKey;
use Kadupul\Alerting\Domain\Rule\ThresholdRule;
use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\IdentityAccess\Infrastructure\Legacy\LegacyAuditTrail;
use Kadupul\IdentityAccess\Infrastructure\Persistence\DbalManagementVisibility;
use Kadupul\Platform\Contract\LegacyConfiguration;
use Kadupul\Tests\Alerting\Fakes\MockClockAdapter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

const TABLES = ['alert_rule', 'alert_rule_state', 'alert_incident', 'alert_notification', 'settings', 'host', 'data_local', 'data_template_rrd', 'graph_local',
    'user_auth', 'user_auth_realm', 'user_auth_group', 'user_auth_group_members', 'user_auth_group_realm', 'user_auth_perms', 'user_auth_group_perms'];

final class AlertPagesDatabaseTest extends TestCase
{
    private const int VIEWER = 42;
    private const int NOW = 1_800_000_000;

    private Connection $database;
    private string $schema = '';
    private string $project = '';
    private DbalRuleStore $rules;
    private DbalRuleCatalog $ruleCatalog;
    private DbalIncidentStore $incidents;
    private DbalIncidentCatalog $incidentCatalog;

    protected function setUp(): void
    {
        $dsn = getenv('KADUPUL_TEST_MYSQL_DSN');
        if ($dsn === false || $dsn === '') {
            self::markTestSkipped('Set KADUPUL_TEST_MYSQL_DSN to a task-owned MariaDB or MySQL server; these alert page tests were not run.');
        }
        $this->schema = 'alert_pages_' . bin2hex(random_bytes(6));
        $this->database = $this->connect();
        $this->database->executeStatement('CREATE DATABASE `' . $this->schema . '`');
        $this->database->executeStatement('USE `' . $this->schema . '`');
        $sql = file_get_contents(dirname(__DIR__, 2) . '/cacti.sql');
        self::assertIsString($sql);
        foreach (TABLES as $table) {
            self::assertSame(1, preg_match('/^CREATE TABLE `?' . $table . '`? \(.*?^\s*\) [^;\n]*;/ms', $sql, $create), $table);
            $this->database->executeStatement(rtrim($create[0], ';'));
        }
        $this->database->insert('settings', ['name' => 'auth_method', 'value' => '1']);
        $this->database->insert('settings', ['name' => 'graph_auth_method', 'value' => '1']);
        $this->database->insert('settings', ['name' => 'guest_user', 'value' => '0']);
        // Devices are denied by default, with device 1 granted as an exception.
        $this->database->insert('user_auth', ['id' => self::VIEWER, 'username' => 'operator', 'policy_graphs' => 2, 'policy_hosts' => 2, 'policy_graph_templates' => 2]);
        $this->database->insert('user_auth_perms', ['user_id' => self::VIEWER, 'item_id' => 1, 'type' => 3]);
        foreach ([8, 29, 30] as $realm) {
            $this->database->insert('user_auth_realm', ['realm_id' => $realm, 'user_id' => self::VIEWER]);
        }
        foreach ([[1, 'Core router'], [2, 'Payroll server']] as [$id, $description]) {
            $this->database->executeStatement('INSERT INTO host (id, description, hostname) VALUES (?, ?, ?)', [$id, $description, 'host' . $id]);
        }
        foreach ([[101, 1], [201, 2], [301, 0]] as [$localDataId, $hostId]) {
            $this->database->insert('data_local', ['id' => $localDataId, 'host_id' => $hostId, 'data_template_id' => 1, 'snmp_query_id' => 0, 'snmp_index' => '']);
            $this->database->insert('data_template_rrd', ['local_data_id' => $localDataId, 'data_source_name' => 'traffic_in', 'data_template_id' => 1, 'hash' => '']);
        }

        $this->project = sys_get_temp_dir() . '/kadupul-alert-pages-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->project . '/log', 0700, true));
        $console = $this->createStub(ConsoleAccess::class);
        $console->method('consoleActor')->willReturn(new Actor(self::VIEWER, 'operator'));
        $configuration = $this->createStub(LegacyConfiguration::class);
        $configuration->method('values')->willReturn(['collector_id' => 1]);
        $visibility = new SeriesVisibility(new DbalManagementVisibility($this->database));
        $audit = new LegacyAuditTrail($this->project);
        $clock = new MockClockAdapter(new MockClock('@' . self::NOW));
        $this->rules = new DbalRuleStore($this->database, $visibility, $clock, new DbalRuleAccess($console, $this->database), $audit, $configuration);
        $this->ruleCatalog = new DbalRuleCatalog($this->database, $visibility);
        $this->incidents = new DbalIncidentStore($this->database, $visibility, new DbalIncidentAccess($console, $this->database), $audit, $configuration);
        $this->incidentCatalog = new DbalIncidentCatalog($this->database, $visibility);
    }

    protected function tearDown(): void
    {
        if (isset($this->database)) {
            if ($this->schema !== '') {
                $this->database->executeStatement('DROP DATABASE IF EXISTS `' . $this->schema . '`');
            }
            $this->database->close();
        }
        if ($this->project !== '') {
            foreach (glob($this->project . '/log/{,.}*', GLOB_BRACE) ?: [] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            @rmdir($this->project . '/log');
            @rmdir($this->project);
        }
    }

    public function test_rule_cannot_reference_data_source_outside_viewer_permissions(): void
    {
        // Device 2 is outside the viewer's device policy; the RRD name compares byte for byte.
        foreach ([[201, 'traffic_in'], [101, 'TRAFFIC_IN'], [101, 'traffic_in '], [999, 'traffic_in']] as [$localDataId, $name]) {
            try {
                // Built directly, as the form's grammar would refuse the trailing space first.
                $this->rules->create(self::VIEWER, new RuleDefinition(new SeriesKey($localDataId, $name), new ThresholdRule(Comparator::GreaterThan, 90.0, 80.0, 1)));
                self::fail('A rule on ' . $localDataId . '/' . $name . ' was saved.');
            } catch (RuleSubmissionRefused $refused) {
                self::assertSame(RuleStore::SERIES_UNAVAILABLE, $refused->getMessage());
            }
        }
        self::assertSame(0, $this->rows('alert_rule'));
        $visible = $this->rules->create(self::VIEWER, $this->threshold(101));
        $deviceless = $this->rules->create(self::VIEWER, $this->threshold(301));
        self::assertSame([$visible, $deviceless], array_map(static fn($rule): int => $rule->id, $this->ruleCatalog->page(self::VIEWER, 1)->rules));
        self::assertSame('Core router', $this->ruleCatalog->find(self::VIEWER, $visible)?->device);

        // Granting the device makes the hidden data source usable.
        $this->database->insert('user_auth_perms', ['user_id' => self::VIEWER, 'item_id' => 2, 'type' => 3]);
        $this->rules->create(self::VIEWER, $this->threshold(201));
        self::assertSame(3, $this->rows('alert_rule'));
    }

    public function testAnIncidentOutsideTheViewersPermissionsCannotBeViewedOrAcknowledged(): void
    {
        $hidden = $this->openIncident($this->insertRule(201));
        $visible = $this->openIncident($this->insertRule(101));
        self::assertNull($this->incidentCatalog->find(self::VIEWER, $hidden, self::NOW));
        self::assertSame([$visible], array_map(static fn($view): int => $view->id, $this->incidentCatalog->page(self::VIEWER, false, 1)->incidents));
        self::assertSame(AcknowledgeOutcome::NotFound, $this->incidents->acknowledge(self::VIEWER, $hidden, self::NOW));
        self::assertSame('open', $this->database->fetchOne('SELECT status FROM alert_incident WHERE id = ?', [$hidden]));
    }

    public function testDeletingARuleWithAnOpenIncidentOrphansItOnInnoDb(): void
    {
        $rule = $this->insertRule(101);
        $incident = $this->openIncident($rule);
        $this->database->insert('alert_rule_state', ['rule_id' => $rule, 'incident_open' => 1, 'consecutive_breaches' => 2, 'consecutive_oks' => 0, 'is_unknown' => 0]);
        $this->database->insert('alert_notification', ['incident_id' => $incident, 'kind' => 'opened', 'status' => 'queued', 'queued_at' => self::NOW - 60, 'next_attempt_at' => self::NOW - 60, 'observed_value' => 99, 'threshold' => 90]);

        $this->rules->delete(self::VIEWER, $rule, $this->ruleCatalog->find(self::VIEWER, $rule)?->revision ?? '');

        self::assertSame(0, $this->rows('alert_rule'));
        self::assertSame(0, $this->rows('alert_rule_state'));
        self::assertSame(['status' => 'orphaned', 'active_rule_id' => null, 'ended_at' => self::NOW], $this->typed($this->database->fetchAssociative('SELECT status, active_rule_id, ended_at FROM alert_incident WHERE id = ?', [$incident]) ?: []));
        self::assertSame([['opened', 'queued']], $this->database->fetchAllNumeric('SELECT kind, status FROM alert_notification'));
        // The orphan has no active rule, so a new rule may open its own incident.
        $this->openIncident($this->insertRule(101));
    }

    public function testAcknowledgeWaitsForTheRuleLockTheEvaluatorHolds(): void
    {
        $rule = $this->insertRule(101);
        $incident = $this->openIncident($rule);
        $evaluator = $this->connect();
        $evaluator->executeStatement('USE `' . $this->schema . '`');
        $evaluator->beginTransaction();
        $evaluator->fetchOne('SELECT id FROM alert_rule WHERE id = ? FOR UPDATE', [$rule]);
        $this->database->executeStatement('SET SESSION innodb_lock_wait_timeout = 1');
        try {
            $this->incidents->acknowledge(self::VIEWER, $incident, self::NOW);
            self::fail('Acknowledgement did not wait for the rule row lock.');
        } catch (LockWaitTimeoutException) {
        } finally {
            $evaluator->rollBack();
            $evaluator->close();
        }
        self::assertSame(['status' => 'open', 'acknowledged_by' => null], $this->typed($this->database->fetchAssociative('SELECT status, acknowledged_by FROM alert_incident WHERE id = ?', [$incident]) ?: []));

        self::assertSame(AcknowledgeOutcome::Acknowledged, $this->incidents->acknowledge(self::VIEWER, $incident, self::NOW));
        self::assertSame(['status' => 'acknowledged', 'acknowledged_by' => self::VIEWER], $this->typed($this->database->fetchAssociative('SELECT status, acknowledged_by FROM alert_incident WHERE id = ?', [$incident]) ?: []));
        $entries = array_map(static fn(string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR), file($this->project . '/log/kadupul-audit.jsonl', FILE_IGNORE_NEW_LINES) ?: []);
        self::assertSame([['failed', null], ['succeeded', self::VIEWER]], array_map(static fn(array $entry): array => [$entry['outcome'], $entry['outcome'] === 'succeeded' ? $entry['actor']['id'] : null], $entries));
    }

    public function testARevokedRealmStopsTheWriteInsideItsTransaction(): void
    {
        $rule = $this->insertRule(101);
        $incident = $this->openIncident($rule);
        $this->database->executeStatement('DELETE FROM user_auth_realm WHERE realm_id IN (29, 30)');
        foreach ([fn() => $this->incidents->acknowledge(self::VIEWER, $incident, self::NOW), fn() => $this->rules->delete(self::VIEWER, $rule, 'x')] as $write) {
            try {
                $write();
                self::fail('A write ran without the realm.');
            } catch (AlertingAccessDenied) {
            }
        }
        self::assertSame(1, $this->rows('alert_rule'));
        self::assertSame('open', $this->database->fetchOne('SELECT status FROM alert_incident WHERE id = ?', [$incident]));
    }

    private function connect(): Connection
    {
        $dsn = (string) getenv('KADUPUL_TEST_MYSQL_DSN');
        parse_str(str_replace(';', '&', substr($dsn, strlen('mysql:'))), $target);
        $prefix = getenv('KADUPUL_TEST_MYSQL_ADMIN_USER') ? 'KADUPUL_TEST_MYSQL_ADMIN_' : 'KADUPUL_TEST_MYSQL_';
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_mysql',
            'host' => is_string($target['host'] ?? null) ? $target['host'] : '127.0.0.1',
            'port' => (int) ($target['port'] ?? 3306),
            'user' => (string) (getenv($prefix . 'USER') ?: 'root'),
            'password' => (string) (getenv($prefix . 'PASSWORD') ?: ''),
            'charset' => 'utf8mb4',
        ]);
        // The legacy session modes (lib/database.php) so the shipped zero-date DDL loads on MySQL too.
        $connection->executeStatement("SET SESSION sql_mode = 'NO_ENGINE_SUBSTITUTION,ALLOW_INVALID_DATES'");

        return $connection;
    }

    private function threshold(int $localDataId, string $name = 'traffic_in'): RuleDefinition
    {
        return RuleSubmission::definition(['kind' => 'threshold', 'local_data_id' => (string) $localDataId, 'rrd_name' => $name, 'for_cycles' => '1', 'comparator' => 'gt', 'trigger_threshold' => '90', 'clear_threshold' => '80']);
    }

    private function insertRule(int $localDataId): int
    {
        $this->database->insert('alert_rule', ['local_data_id' => $localDataId, 'rrd_name' => 'traffic_in', 'kind' => 'threshold', 'comparator' => 'gt', 'trigger_threshold' => 90, 'clear_threshold' => 80, 'for_cycles' => 1, 'owner_id' => 1]);

        return (int) $this->database->lastInsertId();
    }

    private function openIncident(int $rule): int
    {
        $this->database->insert('alert_incident', ['rule_id' => $rule, 'status' => 'open', 'active_rule_id' => $rule, 'opened_at' => self::NOW - 600]);

        return (int) $this->database->lastInsertId();
    }

    private function rows(string $table): int
    {
        return (int) $this->database->fetchOne('SELECT COUNT(*) FROM ' . $table);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function typed(array $row): array
    {
        return array_map(static fn(mixed $value): mixed => is_string($value) && preg_match('/\A-?[0-9]+\z/', $value) === 1 ? (int) $value : $value, $row);
    }
}
