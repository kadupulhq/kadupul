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
use Kadupul\Alerting\Application\EvaluateRules;
use Kadupul\Alerting\Application\EvaluationReport;
use Kadupul\Alerting\Application\Port\RuleStateStore;
use Kadupul\Alerting\Application\Port\RuleStore;
use Kadupul\Alerting\Application\Port\UnusableRuleRecord;
use Kadupul\Alerting\Application\Query\AcknowledgeOutcome;
use Kadupul\Alerting\Application\Query\AlertingAccessDenied;
use Kadupul\Alerting\Application\Rule\RuleDefinition;
use Kadupul\Alerting\Application\Rule\RuleSubmission;
use Kadupul\Alerting\Application\Rule\RuleSubmissionRefused;
use Kadupul\Alerting\Infrastructure\Dbal\DbalIncidentAccess;
use Kadupul\Alerting\Infrastructure\Dbal\DbalIncidentCatalog;
use Kadupul\Alerting\Infrastructure\Dbal\DbalEvaluationCursor;
use Kadupul\Alerting\Infrastructure\Dbal\DbalIncidentRepository;
use Kadupul\Alerting\Infrastructure\Dbal\DbalIncidentStore;
use Kadupul\Alerting\Infrastructure\Dbal\DbalNotificationQueue;
use Kadupul\Alerting\Infrastructure\Dbal\DbalRuleAccess;
use Kadupul\Alerting\Infrastructure\Dbal\DbalRuleCatalog;
use Kadupul\Alerting\Infrastructure\Dbal\DbalRuleRepository;
use Kadupul\Alerting\Infrastructure\Dbal\DbalRuleStateStore;
use Kadupul\Alerting\Infrastructure\Dbal\DbalRuleStore;
use Kadupul\Alerting\Infrastructure\Dbal\DbalTransactionRunner;
use Kadupul\Alerting\Infrastructure\Dbal\DsstatsBaselineSource;
use Kadupul\Alerting\Infrastructure\Dbal\DsstatsRateSource;
use Kadupul\Alerting\Infrastructure\Dbal\SeriesVisibility;
use Kadupul\Alerting\Domain\Evaluation\RuleState;
use Kadupul\Alerting\Domain\Rule\Comparator;
use Kadupul\Alerting\Domain\Rule\SeriesKey;
use Kadupul\Alerting\Domain\Rule\ThresholdRule;
use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\IdentityAccess\Infrastructure\Legacy\LegacyAuditTrail;
use Kadupul\IdentityAccess\Infrastructure\Persistence\DbalManagementVisibility;
use Kadupul\Platform\Contract\LegacyConfiguration;
use Kadupul\Tests\Alerting\Fakes\MockClockAdapter;
use Kadupul\Tests\Alerting\Fakes\RecordingLogger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

const TABLES = ['alert_rule', 'alert_rule_state', 'alert_incident', 'alert_notification', 'alert_baseline_sample', 'settings', 'host', 'data_local', 'data_template_rrd', 'graph_local',
    'data_template_data', 'data_source_stats_hourly_cache', 'data_source_stats_hourly_last', 'user_auth', 'user_auth_realm', 'user_auth_group', 'user_auth_group_members', 'user_auth_group_realm', 'user_auth_perms', 'user_auth_group_perms'];

/**
 * Runs $edit once, after the batch's rules and states were read and before
 * any rule's transaction, as an edit another session commits would.
 */
final class RacingStates implements RuleStateStore
{
    public function __construct(private readonly RuleStateStore $inner, private ?\Closure $edit) {}

    public function load(array $ruleIds): array
    {
        $states = $this->inner->load($ruleIds);
        if ($this->edit !== null) {
            ($this->edit)();
            $this->edit = null;
        }

        return $states;
    }

    public function lock(int $ruleId): RuleState|UnusableRuleRecord|null
    {
        return $this->inner->lock($ruleId);
    }

    public function save(int $ruleId, RuleState $state): void
    {
        $this->inner->save($ruleId, $state);
    }

    public function delete(array $ruleIds): void
    {
        $this->inner->delete($ruleIds);
    }
}

final class AlertPagesDatabaseTest extends TestCase
{
    private const int VIEWER = 42;
    private const int ADMIN = 1;
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

    public function testIncidentVisibilityFollowsTheRecordedSeriesAndFailsClosedWhenItIsGone(): void
    {
        // Full device access: device policy allow, no device exceptions.
        $this->database->insert('user_auth', ['id' => self::ADMIN, 'username' => 'admin', 'policy_graphs' => 1, 'policy_hosts' => 1, 'policy_graph_templates' => 1]);
        $moved = $this->insertRule(201);
        $old = $this->openIncident($moved);
        $this->database->executeStatement("UPDATE alert_incident SET status = 'orphaned', active_rule_id = NULL, ended_at = ? WHERE id = ?", [self::NOW - 30, $old]);
        $this->database->update('alert_rule', ['local_data_id' => 101], ['id' => $moved]);
        $gone = $this->openIncident($this->insertRule(301));
        $this->database->executeStatement('DELETE FROM data_template_rrd WHERE local_data_id = 301');
        $this->database->executeStatement('DELETE FROM data_local WHERE id = 301');

        // The viewer sees device 1 only: not the incident opened on 201
        // before its rule moved to 101, nor the one whose data source is gone.
        self::assertSame([], array_map(static fn($view): int => $view->id, $this->incidentCatalog->page(self::VIEWER, false, 1)->incidents));
        foreach ([$old, $gone] as $incident) {
            self::assertNull($this->incidentCatalog->find(self::VIEWER, $incident, self::NOW));
            self::assertSame(AcknowledgeOutcome::NotFound, $this->incidents->acknowledge(self::VIEWER, $incident, self::NOW));
        }
        self::assertSame([$gone, $old], array_map(static fn($view): int => $view->id, $this->incidentCatalog->page(self::ADMIN, false, 1)->incidents));
        self::assertSame([201, false], [$this->incidentCatalog->find(self::ADMIN, $old, self::NOW)?->view->series->localDataId, $this->incidentCatalog->find(self::ADMIN, $gone, self::NOW)?->view->series->seriesExists]);

        $this->database->insert('user_auth_perms', ['user_id' => self::ADMIN, 'item_id' => 2, 'type' => 3]);
        self::assertNull($this->incidentCatalog->find(self::ADMIN, $gone, self::NOW), 'a device exception removes full access');
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

    /**
     * The read-path gate, DbalRuleAccess::authorize() and
     * DbalIncidentAccess::authorize(), on the cacti.sql user, group and realm
     * tables. The console actor is always present, so each denial comes from
     * the account or realm check; each denied account differs from an
     * admitted one in a single row. The same cases run on SQLite in
     * tests/Symfony/Alerting/Infrastructure/AlertingRealmCheckTest.php.
     */
    public function testTheReadGateAdmitsOnlyAnEligibleAccountWithTheRealm(): void
    {
        $this->database->executeStatement("UPDATE settings SET value = '109' WHERE name = 'guest_user'");
        $actor = 0;
        $console = $this->createStub(ConsoleAccess::class);
        $console->method('consoleActor')->willReturnCallback(static function () use (&$actor): Actor {
            return new Actor($actor, 'user' . $actor);
        });
        $cases = [
            'user grant' => [101, 'admitted', ['realm' => true]],
            'group grant' => [102, 'admitted', ['group' => 'on']],
            'console realm only' => [103, 'denied', []],
            'other alerting realm only' => [104, 'denied', ['other' => true]],
            'disabled group grant' => [105, 'denied', ['group' => '']],
            'locked account' => [106, 'denied', ['realm' => true, 'locked' => 'on']],
            'disabled account' => [107, 'denied', ['realm' => true, 'enabled' => '']],
            'must change password' => [108, 'denied', ['realm' => true, 'must_change_password' => 'on']],
            'guest account' => [109, 'denied', ['realm' => true]],
        ];
        foreach ([[29, 30], [30, 29]] as [$realm, $other]) {
            $this->database->executeStatement('DELETE FROM user_auth_realm WHERE user_id > 100');
            $this->database->executeStatement('DELETE FROM user_auth_group_realm');
            $this->database->executeStatement('DELETE FROM user_auth_group_members');
            $this->database->executeStatement('DELETE FROM user_auth_group');
            $this->database->executeStatement('DELETE FROM user_auth WHERE id > 100');
            $expected = [];
            foreach ($cases as $name => [$id, $decision, $change]) {
                $this->database->insert('user_auth', [
                    'id' => $id, 'username' => 'user' . $id,
                    'enabled' => $change['enabled'] ?? 'on',
                    'locked' => $change['locked'] ?? '',
                    'must_change_password' => $change['must_change_password'] ?? null,
                ]);
                $this->database->insert('user_auth_realm', ['realm_id' => 8, 'user_id' => $id]);
                if (isset($change['realm'])) {
                    $this->database->insert('user_auth_realm', ['realm_id' => $realm, 'user_id' => $id]);
                }
                if (isset($change['other'])) {
                    $this->database->insert('user_auth_realm', ['realm_id' => $other, 'user_id' => $id]);
                }
                if (isset($change['group'])) {
                    $this->database->insert('user_auth_group', ['id' => $id, 'name' => 'group' . $id, 'enabled' => $change['group']]);
                    $this->database->insert('user_auth_group_members', ['group_id' => $id, 'user_id' => $id]);
                    $this->database->insert('user_auth_group_realm', ['group_id' => $id, 'realm_id' => $realm]);
                }
                $expected[$name] = $decision;
            }
            $access = $realm === 29 ? new DbalRuleAccess($console, $this->database) : new DbalIncidentAccess($console, $this->database);
            $seen = [];
            foreach ($cases as $name => [$id]) {
                $actor = $id;
                try {
                    self::assertSame($id, $access->authorize()->id);
                    $seen[$name] = 'admitted';
                } catch (AlertingAccessDenied $denied) {
                    self::assertFalse($denied->unauthenticated, $name);
                    $seen[$name] = 'denied';
                }
            }
            self::assertSame($expected, $seen, 'realm ' . $realm);
        }
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

    /**
     * A rule edited through the rule page while the evaluator runs: the
     * edit commits after the evaluator read its batch and before it locked
     * the rule. Each case breaches on the old definition, so a run that
     * evaluated the old row would write its state or open an incident.
     */
    public function testAnEditedSeriesWithAnOpenIncidentIsLeftForTheNextRunAndAlertsAgain(): void
    {
        $rule = $this->insertRule(101);
        $incident = $this->openIncident($rule);
        $this->database->insert('alert_rule_state', ['rule_id' => $rule, 'incident_open' => 1, 'consecutive_breaches' => 1, 'consecutive_oks' => 0, 'is_unknown' => 0, 'last_observed_at' => self::NOW - 300]);
        $this->sample(101, self::NOW, 95.0);
        $revision = $this->ruleCatalog->find(self::VIEWER, $rule)?->revision ?? '';

        $report = $this->evaluate(self::NOW, function () use ($rule, $revision): void {
            $this->rules->update(self::VIEWER, $rule, $revision, $this->threshold(301));
        });

        self::assertSame(0, $this->rows('alert_rule_state'), 'No state from the old series is written over the edit.');
        self::assertSame(['status' => 'orphaned', 'active_rule_id' => null], $this->typed($this->database->fetchAssociative('SELECT status, active_rule_id FROM alert_incident WHERE id = ?', [$incident]) ?: []));
        self::assertSame(0, $this->rows('alert_notification'));
        self::assertSame([0, 1, 0], [$report->evaluated, $report->changed, $report->failed]);

        $this->sample(301, self::NOW + 300, 96.0);
        $next = $this->evaluate(self::NOW + 300);

        self::assertSame([1, 0, 0], [$next->evaluated, $next->changed, $next->failed]);
        self::assertSame([['open', 301]], array_map($this->typed(...), $this->database->fetchAllNumeric('SELECT status, local_data_id FROM alert_incident WHERE active_rule_id = ?', [$rule])), 'The moved rule opens an incident on its new series.');
        self::assertSame([['opened', 96.0, 90.0]], $this->database->fetchAllNumeric('SELECT kind, observed_value, threshold FROM alert_notification'));
    }

    public function testAnEditedSeriesOneBreachShortOpensNothing(): void
    {
        $rule = $this->insertRule(101);
        $this->database->update('alert_rule', ['for_cycles' => 2], ['id' => $rule]);
        $this->database->insert('alert_rule_state', ['rule_id' => $rule, 'incident_open' => 0, 'consecutive_breaches' => 1, 'consecutive_oks' => 0, 'is_unknown' => 0, 'last_observed_at' => self::NOW - 300]);
        $this->sample(101, self::NOW, 95.0);
        $revision = $this->ruleCatalog->find(self::VIEWER, $rule)?->revision ?? '';

        $report = $this->evaluate(self::NOW, function () use ($rule, $revision): void {
            $this->rules->update(self::VIEWER, $rule, $revision, $this->threshold(301));
        });

        self::assertSame(0, $this->rows('alert_incident'), 'No incident is recorded on the new series with the old series\' rate.');
        self::assertSame(0, $this->rows('alert_notification'), 'No opened mail is queued.');
        self::assertSame(0, $this->rows('alert_rule_state'));
        self::assertSame(1, $report->changed);
    }

    public function testAThresholdEditedDuringTheRunIsNotEvaluatedWithTheOldThreshold(): void
    {
        $rule = $this->insertRule(101);
        $this->sample(101, self::NOW, 95.0);
        $revision = $this->ruleCatalog->find(self::VIEWER, $rule)?->revision ?? '';
        $raised = RuleSubmission::definition(['kind' => 'threshold', 'local_data_id' => '101', 'rrd_name' => 'traffic_in', 'for_cycles' => '1', 'comparator' => 'gt', 'trigger_threshold' => '99', 'clear_threshold' => '80']);

        $report = $this->evaluate(self::NOW, function () use ($rule, $revision, $raised): void {
            $this->rules->update(self::VIEWER, $rule, $revision, $raised);
        });

        self::assertSame(0, $this->rows('alert_incident'), '95 breached the old trigger of 90, not the new 99.');
        self::assertSame(0, $this->rows('alert_rule_state'));
        self::assertSame([0, 1], [$report->evaluated, $report->changed]);

        self::assertSame(1, $this->evaluate(self::NOW)->evaluated);
        self::assertSame(0, $this->rows('alert_incident'));
        self::assertSame(['incident_open' => 0, 'consecutive_breaches' => 0], $this->typed($this->database->fetchAssociative('SELECT incident_open, consecutive_breaches FROM alert_rule_state WHERE rule_id = ?', [$rule]) ?: []));
    }

    public function testADataSourceDeletedDuringTheRunWritesNothing(): void
    {
        $rule = $this->insertRule(101);
        $this->sample(101, self::NOW, 95.0);

        $report = $this->evaluate(self::NOW, function (): void {
            // As lib/api_data_source.php removes a data source, without touching alert_rule.
            $this->database->executeStatement('DELETE FROM data_template_rrd WHERE local_data_id = 101');
            $this->database->executeStatement('DELETE FROM data_local WHERE id = 101');
        });

        self::assertSame(0, $this->rows('alert_incident'), 'A rate still fresh after its data source was deleted opens nothing.');
        self::assertSame(0, $this->rows('alert_notification'));
        self::assertSame(0, $this->rows('alert_rule_state'));
        self::assertSame(1, $this->rows('alert_rule'), 'The rule stays, for the operator to move or delete.');
        self::assertSame(0, $this->evaluate(self::NOW)->orphaned, 'Nothing was left for the orphan sweep.');
        self::assertSame($rule, (int) $this->database->fetchOne('SELECT id FROM alert_rule'));
        self::assertSame([0, 1], [$report->evaluated, $report->changed]);
    }

    public function testAStateEndedDuringTheRunIsNotWrittenBack(): void
    {
        // Moved to another series and back: the rule row reads as it did, but its state and incident were ended.
        $rule = $this->insertRule(101);
        $incident = $this->openIncident($rule);
        $this->database->insert('alert_rule_state', ['rule_id' => $rule, 'incident_open' => 1, 'consecutive_breaches' => 1, 'consecutive_oks' => 0, 'is_unknown' => 0, 'last_observed_at' => self::NOW - 300]);
        $this->sample(101, self::NOW, 95.0);

        $report = $this->evaluate(self::NOW, function () use ($rule): void {
            $this->rules->update(self::VIEWER, $rule, $this->ruleCatalog->find(self::VIEWER, $rule)?->revision ?? '', $this->threshold(301));
            $this->rules->update(self::VIEWER, $rule, $this->ruleCatalog->find(self::VIEWER, $rule)?->revision ?? '', $this->threshold(101));
        });

        self::assertSame(0, $this->rows('alert_rule_state'), 'An open state is not written back over the ended incident.');
        self::assertSame('orphaned', $this->database->fetchOne('SELECT status FROM alert_incident WHERE id = ?', [$incident]));
        self::assertSame([0, 1], [$report->evaluated, $report->changed]);

        $this->sample(101, self::NOW + 300, 96.0);
        self::assertSame(1, $this->evaluate(self::NOW + 300)->evaluated);
        self::assertSame(1, (int) $this->database->fetchOne('SELECT COUNT(*) FROM alert_incident WHERE active_rule_id = ?', [$rule]), 'The rule alerts again.');
    }

    /** One evaluator run at $now on this connection, with $edit committed between its batch reads and the rule's transaction. */
    private function evaluate(int $now, ?\Closure $edit = null): EvaluationReport
    {
        if ($this->database->fetchOne("SELECT value FROM settings WHERE name = 'dsstats_enable'") === false) {
            $this->database->insert('settings', ['name' => 'dsstats_enable', 'value' => 'on']);
            foreach ([101, 201, 301] as $localDataId) {
                $this->database->insert('data_template_data', ['local_data_id' => $localDataId, 'rrd_step' => 300, 'name' => 'ds ' . $localDataId]);
            }
        }
        $this->database->executeStatement("SET time_zone = '+00:00'");
        $evaluate = new EvaluateRules(
            new DsstatsRateSource($this->database),
            new DsstatsBaselineSource($this->database),
            new DbalRuleRepository($this->database),
            new RacingStates(new DbalRuleStateStore($this->database), $edit),
            new DbalIncidentRepository($this->database),
            new DbalNotificationQueue($this->database),
            new DbalEvaluationCursor($this->database),
            new MockClockAdapter(new MockClock('@' . $now)),
            new DbalTransactionRunner($this->database),
            new RecordingLogger(),
        );

        return $evaluate(100, 30.0);
    }

    /** One dsstats cycle for the series, written as dsstats_poller_output() writes it. */
    private function sample(int $localDataId, int $time, float $rate): void
    {
        $this->database->executeStatement("SET time_zone = '+00:00'");
        $this->database->executeStatement(
            'INSERT INTO data_source_stats_hourly_cache (local_data_id, rrd_name, time, value) VALUES (?, ?, FROM_UNIXTIME(?), ?)',
            [$localDataId, 'traffic_in', $time, $rate],
        );
        $this->database->executeStatement(
            'INSERT INTO data_source_stats_hourly_last (local_data_id, rrd_name, value, calculated) VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE value = VALUES(value), calculated = VALUES(calculated)',
            [$localDataId, 'traffic_in', $rate, $rate],
        );
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

    /** An open incident recording its rule's current series, as DbalIncidentRepository::open() writes it. */
    private function openIncident(int $rule): int
    {
        $this->database->executeStatement(
            'INSERT INTO alert_incident (rule_id, status, active_rule_id, opened_at, local_data_id, rrd_name, rule_kind) SELECT id, ?, id, ?, local_data_id, rrd_name, kind FROM alert_rule WHERE id = ?',
            ['open', self::NOW - 600, $rule],
        );

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
