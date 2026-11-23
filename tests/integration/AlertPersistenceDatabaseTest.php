<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/*
 * The alert evaluator with its DBAL stores on MariaDB or MySQL, over the
 * shipped cacti.sql tables in a schema this test owns and drops. Rates are
 * written the way dsstats_poller_output() writes them (lib/dsstats.php
 * :924-940): a cache row with the sample time and the rate, and the last row
 * with the same rate in `calculated`. AlertRateSourceDatabaseTest runs the
 * real writer against the same rate source.
 */

namespace AlertPersistenceDatabaseTest;

$applicationLoader = require dirname(__DIR__, 2) . '/include/vendor/autoload.php';
$applicationLoader->unregister();
$applicationLoader->register(false);
// CI installs the application with --no-dev, so autoload-dev does not map the shared test fakes.
$applicationLoader->addPsr4('Kadupul\\Tests\\', dirname(__DIR__) . '/Symfony/');

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Kadupul\Alerting\Application\EvaluateRules;
use Kadupul\Alerting\Application\EvaluationReport;
use Kadupul\Alerting\Application\Port\IncidentRepository;
use Kadupul\Alerting\Application\Port\RuleStateStore;
use Kadupul\Alerting\Application\Port\UnusableRuleRecord;
use Kadupul\Alerting\Domain\Evaluation\RuleState;
use Kadupul\Alerting\Domain\Incident\Incident;
use Kadupul\Alerting\Domain\Incident\IncidentStatus;
use Kadupul\Alerting\Infrastructure\Dbal\DbalEvaluationCursor;
use Kadupul\Alerting\Infrastructure\Dbal\DbalIncidentRepository;
use Kadupul\Alerting\Infrastructure\Dbal\DbalNotificationQueue;
use Kadupul\Alerting\Infrastructure\Dbal\DbalRuleRepository;
use Kadupul\Alerting\Infrastructure\Dbal\DbalRuleStateStore;
use Kadupul\Alerting\Infrastructure\Dbal\DbalTransactionRunner;
use Kadupul\Alerting\Infrastructure\Dbal\DsstatsBaselineSource;
use Kadupul\Alerting\Infrastructure\Dbal\DsstatsRateSource;
use Kadupul\Tests\Alerting\Fakes\MockClockAdapter;
use Kadupul\Tests\Alerting\Fakes\RecordingLogger;
use Kadupul\Tests\Alerting\Fakes\StoredIncidents;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

const TABLES = ['alert_rule', 'alert_rule_state', 'alert_incident', 'alert_notification', 'alert_baseline_sample', 'settings', 'host', 'data_local', 'data_template_data', 'data_template_rrd', 'data_source_stats_hourly_cache', 'data_source_stats_hourly_last'];

/** Counts saves, so a test can tell an unchanged state from a rewritten one. */
final class CountingStates implements RuleStateStore
{
    public int $saves = 0;

    public function __construct(private readonly RuleStateStore $inner) {}

    public function load(array $ruleIds): array
    {
        return $this->inner->load($ruleIds);
    }

    public function lock(int $ruleId): RuleState|UnusableRuleRecord|null
    {
        return $this->inner->lock($ruleId);
    }

    public function save(int $ruleId, RuleState $state): void
    {
        ++$this->saves;
        $this->inner->save($ruleId, $state);
    }

    public function delete(array $ruleIds): void
    {
        $this->inner->delete($ruleIds);
    }
}

/** Writes the incident row, then fails, as a lost connection after the insert would. */
final readonly class FailingIncidents implements IncidentRepository
{
    public function __construct(private IncidentRepository $inner) {}

    public function open(int $ruleId, int $observedAt): int
    {
        $this->inner->open($ruleId, $observedAt);
        throw new \RuntimeException('injected failure after the incident insert');
    }

    public function close(int $ruleId, int $observedAt): int
    {
        $this->inner->close($ruleId, $observedAt);
        throw new \RuntimeException('injected failure after the incident update');
    }

    public function orphan(array $ruleIds, int $at): int
    {
        return $this->inner->orphan($ruleIds, $at);
    }
}

final class AlertPersistenceDatabaseTest extends TestCase
{
    private const int NOW = 1_800_000_000;
    private const int STEP = 300;

    private Connection $database;
    private string $schema = '';
    private MockClock $clock;
    private CountingStates $states;
    private DbalIncidentRepository $incidents;

    protected function setUp(): void
    {
        $dsn = getenv('KADUPUL_TEST_MYSQL_DSN');
        if ($dsn === false || $dsn === '') {
            self::markTestSkipped('Set KADUPUL_TEST_MYSQL_DSN to a task-owned MariaDB or MySQL server; these alert persistence tests were not run.');
        }
        parse_str(str_replace(';', '&', substr($dsn, strlen('mysql:'))), $target);
        $prefix = getenv('KADUPUL_TEST_MYSQL_ADMIN_USER') ? 'KADUPUL_TEST_MYSQL_ADMIN_' : 'KADUPUL_TEST_MYSQL_';
        $this->schema = 'alert_persist_' . bin2hex(random_bytes(6));
        $this->database = DriverManager::getConnection([
            'driver' => 'pdo_mysql',
            'host' => is_string($target['host'] ?? null) ? $target['host'] : '127.0.0.1',
            'port' => (int) ($target['port'] ?? 3306),
            'user' => (string) (getenv($prefix . 'USER') ?: 'root'),
            'password' => (string) (getenv($prefix . 'PASSWORD') ?: ''),
            'charset' => 'utf8mb4',
        ]);
        $this->database->executeStatement('CREATE DATABASE `' . $this->schema . '`');
        $this->database->executeStatement('USE `' . $this->schema . '`');
        // The legacy session modes (lib/database.php) so the shipped zero-date DDL loads on MySQL too.
        $this->database->executeStatement("SET SESSION sql_mode = 'NO_ENGINE_SUBSTITUTION,ALLOW_INVALID_DATES'");
        $this->database->executeStatement("SET time_zone = '+00:00'");

        $sql = file_get_contents(dirname(__DIR__, 2) . '/cacti.sql');
        self::assertIsString($sql);
        foreach (TABLES as $table) {
            self::assertSame(1, preg_match('/^CREATE TABLE `?' . $table . '`? \(.*?^\s*\) [^;\n]*;/ms', $sql, $create), $table);
            $this->database->executeStatement(rtrim($create[0], ';'));
        }
        $this->database->insert('settings', ['name' => 'dsstats_enable', 'value' => 'on']);

        $this->clock = new MockClock(new \DateTimeImmutable('@' . self::NOW));
        $this->states = new CountingStates(new DbalRuleStateStore($this->database));
        $this->incidents = new DbalIncidentRepository($this->database);
    }

    protected function tearDown(): void
    {
        if (isset($this->database)) {
            if ($this->schema !== '') {
                $this->database->executeStatement('DROP DATABASE IF EXISTS `' . $this->schema . '`');
            }
            $this->database->close();
        }
    }

    private function evaluate(?IncidentRepository $incidents = null, int $batchSize = 100, float $budgetSeconds = 30.0, ?RecordingLogger $logger = null): EvaluationReport
    {
        $evaluate = new EvaluateRules(
            new DsstatsRateSource($this->database),
            new DsstatsBaselineSource($this->database),
            new DbalRuleRepository($this->database),
            $this->states,
            $incidents ?? $this->incidents,
            new DbalNotificationQueue($this->database),
            new DbalEvaluationCursor($this->database),
            new MockClockAdapter($this->clock),
            new DbalTransactionRunner($this->database),
            $logger ?? new RecordingLogger(),
        );

        return $evaluate($batchSize, $budgetSeconds);
    }

    /** A device, a data source on it and a rule on its traffic_in (gt 90, clear 80). */
    private function watch(int $ruleId, int $localDataId, int $hostId, int $forCycles = 1): void
    {
        if ($hostId > 0 && $this->database->fetchOne('SELECT COUNT(*) FROM host WHERE id = ?', [$hostId]) == 0) {
            $this->database->insert('host', ['id' => $hostId, 'description' => 'device ' . $hostId, 'hostname' => '192.0.2.' . $hostId]);
        }
        $this->database->insert('data_local', ['id' => $localDataId, 'host_id' => $hostId]);
        $this->database->insert('data_template_data', ['local_data_id' => $localDataId, 'rrd_step' => self::STEP, 'name' => 'ds ' . $localDataId]);
        $this->database->insert('data_template_rrd', ['local_data_id' => $localDataId, 'data_source_name' => 'traffic_in']);
        $this->database->insert('alert_rule', ['id' => $ruleId, 'local_data_id' => $localDataId, 'rrd_name' => 'traffic_in', 'comparator' => 'gt', 'trigger_threshold' => 90, 'clear_threshold' => 80, 'for_cycles' => $forCycles]);
    }

    /** One dsstats cycle for the series; a NULL rate is a counter's first cycle after a restart. */
    private function sample(int $localDataId, int $time, ?float $rate): void
    {
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

    private function at(int $time): void
    {
        $this->clock->sleep($time - $this->clock->now()->getTimestamp());
    }

    /** @return array<string, int|null>|false */
    private function stateRow(int $ruleId): array|false
    {
        $row = $this->database->fetchAssociative('SELECT incident_open, consecutive_breaches, consecutive_oks, is_unknown, last_observed_at FROM alert_rule_state WHERE rule_id = ?', [$ruleId]);

        return $row === false ? false : array_map(static fn(mixed $value): ?int => $value === null ? null : (int) $value, $row);
    }

    /** @return list<string> the kind of each queued notification, oldest first */
    private function notifications(): array
    {
        return array_map('strval', $this->database->fetchFirstColumn('SELECT kind FROM alert_notification ORDER BY id'));
    }

    public function testStateAndIncidentWrittenInOneTransaction(): void
    {
        $this->watch(1, 101, 10, forCycles: 2);
        $this->states->save(1, new RuleState(false, 1, 0, false, self::NOW - self::STEP));
        $before = $this->stateRow(1);
        $this->sample(101, self::NOW, 95.0);

        try {
            $this->evaluate(new FailingIncidents($this->incidents));
            self::fail('The injected incident failure was not reported.');
        } catch (\RuntimeException $e) {
            self::assertSame('injected failure after the incident insert', $e->getMessage());
        }

        self::assertSame(['incident_open' => 0, 'consecutive_breaches' => 1, 'consecutive_oks' => 0, 'is_unknown' => 0, 'last_observed_at' => self::NOW - self::STEP], $before);
        self::assertSame($before, $this->stateRow(1), 'The state saved before the incident insert was rolled back with it.');
        self::assertSame([], StoredIncidents::forRule($this->database, 1), 'The incident insert was rolled back.');
        self::assertFalse($this->database->isTransactionActive());

        // The same observation is still new, so the retry opens the incident.
        // InnoDB does not return the rolled-back insert's auto-increment id.
        $this->evaluate();
        $incidents = StoredIncidents::forRule($this->database, 1);
        self::assertCount(1, $incidents);
        self::assertSame([IncidentStatus::Open, self::NOW], [$incidents[0]->status, $incidents[0]->openedAt]);
        self::assertSame(['incident_open' => 1, 'consecutive_breaches' => 2, 'consecutive_oks' => 0, 'is_unknown' => 0, 'last_observed_at' => self::NOW], $this->stateRow(1));
    }

    public function testAFailedRecoveryWriteKeepsTheIncidentOpen(): void
    {
        $this->watch(1, 101, 10);
        $this->sample(101, self::NOW, 95.0);
        $this->evaluate();
        $this->sample(101, self::NOW + self::STEP, 50.0);
        $this->at(self::NOW + self::STEP);
        $open = $this->stateRow(1);

        try {
            $this->evaluate(new FailingIncidents($this->incidents));
            self::fail('The injected incident failure was not reported.');
        } catch (\RuntimeException) {
        }

        self::assertSame($open, $this->stateRow(1));
        self::assertEquals([new Incident(1, 1, IncidentStatus::Open, self::NOW)], StoredIncidents::forRule($this->database, 1));
    }

    public function testTheRunnerLeavesACallersTransactionToTheCaller(): void
    {
        $runner = new DbalTransactionRunner($this->database);
        foreach ([5, 6, 7] as $rule) {
            $this->watch($rule, 100 + $rule, 10);
        }
        $this->database->beginTransaction();
        $this->incidents->open(5, 100);
        try {
            $runner->run(function (): void {
                $this->incidents->open(6, 100);
                throw new \RuntimeException('inner failure');
            });
        } catch (\RuntimeException) {
        }
        $runner->run(function (): void {
            $this->incidents->open(7, 100);
        });

        self::assertTrue($this->database->isTransactionActive());
        self::assertSame(['5', '7'], array_map('strval', $this->database->fetchFirstColumn('SELECT rule_id FROM alert_incident ORDER BY rule_id')));
        $this->database->rollBack();
        self::assertSame('0', (string) $this->database->fetchOne('SELECT COUNT(*) FROM alert_incident'));
    }

    public function testMemoryTableResetProducesUnknownNotRecovery(): void
    {
        $this->watch(1, 101, 10);
        $this->sample(101, self::NOW, 95.0);
        $this->evaluate();
        self::assertSame(1, $this->states->saves);

        // A database restart empties both MEMORY tables.
        $this->database->executeStatement('DELETE FROM data_source_stats_hourly_last');
        $this->database->executeStatement('DELETE FROM data_source_stats_hourly_cache');
        $this->at(self::NOW + self::STEP);
        $report = $this->evaluate();

        self::assertNull($report->inactiveReason, 'Empty tables are unknown, not an unavailable source.');
        self::assertSame(['incident_open' => 1, 'consecutive_breaches' => 1, 'consecutive_oks' => 0, 'is_unknown' => 1, 'last_observed_at' => self::NOW], $this->stateRow(1));
        self::assertSame(2, $this->states->saves);

        // A counter's first cycle after the restart has no rate yet.
        $this->sample(101, self::NOW + 2 * self::STEP, null);
        $this->at(self::NOW + 2 * self::STEP);
        $this->evaluate();
        $this->evaluate();
        self::assertSame(2, $this->states->saves, 'Staying unknown, also across reloads, writes nothing.');
        self::assertEquals([new Incident(1, 1, IncidentStatus::Open, self::NOW)], StoredIncidents::forRule($this->database, 1), 'No recovery during the gap.');
        self::assertSame(['opened'], $this->notifications(), 'Nothing is queued during the gap.');

        // The second cycle has a rate again; only a real ok recovers.
        $this->sample(101, self::NOW + 3 * self::STEP, 50.0);
        $this->at(self::NOW + 3 * self::STEP);
        $this->evaluate();
        self::assertEquals([new Incident(1, 1, IncidentStatus::Closed, self::NOW, self::NOW + 3 * self::STEP)], StoredIncidents::forRule($this->database, 1));
        self::assertSame(['incident_open' => 0, 'consecutive_breaches' => 0, 'consecutive_oks' => 0, 'is_unknown' => 0, 'last_observed_at' => self::NOW + 3 * self::STEP], $this->stateRow(1));
    }

    public function testDeletedDataSourceClosesIncidentAsOrphanedWithoutNotification(): void
    {
        $this->watch(1, 101, 10);
        $this->watch(2, 102, 10);
        $this->sample(101, self::NOW, 95.0);
        $this->sample(102, self::NOW, 95.0);
        $this->evaluate();

        // What api_data_source_remove() deletes for the data source.
        foreach (['data_local' => 'id', 'data_template_data' => 'local_data_id', 'data_template_rrd' => 'local_data_id', 'data_source_stats_hourly_cache' => 'local_data_id', 'data_source_stats_hourly_last' => 'local_data_id'] as $table => $column) {
            $this->database->executeStatement('DELETE FROM ' . $table . ' WHERE ' . $column . ' = 101');
        }
        $this->at(self::NOW + 60);
        $report = $this->evaluate();

        self::assertSame(1, $report->orphaned);
        self::assertEquals([new Incident(1, 1, IncidentStatus::Orphaned, self::NOW, self::NOW + 60)], StoredIncidents::forRule($this->database, 1));
        self::assertFalse($this->stateRow(1), 'The orphaned rule starts again from no state.');
        self::assertSame(['opened', 'opened'], $this->notifications(), 'An orphaned incident queues no notification.');
        self::assertEquals([new Incident(2, 2, IncidentStatus::Open, self::NOW)], StoredIncidents::forRule($this->database, 2), 'Other rules are untouched.');

        $this->at(self::NOW + self::STEP);
        $again = $this->evaluate();
        self::assertSame(0, $again->orphaned, 'An orphan is handled once.');
        self::assertCount(1, StoredIncidents::forRule($this->database, 1));
        self::assertFalse($this->stateRow(1));
    }

    public function testRemovedDevicesRrdNamesAndRulesOrphanTheirIncidents(): void
    {
        $this->watch(1, 101, 10);
        $this->watch(2, 102, 11);
        $this->watch(3, 103, 12);
        $this->watch(4, 104, 13);
        $this->watch(5, 105, 0);
        $this->watch(6, 106, 14);
        foreach ([101, 102, 103, 104, 105, 106] as $id) {
            $this->sample($id, self::NOW, 95.0);
        }
        $this->evaluate();
        self::assertSame(6, (int) $this->database->fetchOne('SELECT COUNT(*) FROM alert_incident WHERE active_rule_id IS NOT NULL'));

        $this->database->executeStatement('DELETE FROM host WHERE id = 10');
        $this->database->executeStatement("UPDATE host SET deleted = 'on' WHERE id = 11");
        $this->database->executeStatement("UPDATE data_template_rrd SET data_source_name = 'traffic_out' WHERE local_data_id = 103");
        $this->database->executeStatement('DELETE FROM alert_rule WHERE id = 4');
        // The column's collation ignores case; the rule names one exact series.
        $this->database->executeStatement("UPDATE data_template_rrd SET data_source_name = 'TRAFFIC_IN' WHERE local_data_id = 106");
        $this->at(self::NOW + 60);
        $report = $this->evaluate();

        self::assertSame(5, $report->orphaned);
        foreach ([1, 2, 3, 4, 6] as $rule) {
            self::assertSame([IncidentStatus::Orphaned], array_map(static fn(Incident $incident): IncidentStatus => $incident->status, StoredIncidents::forRule($this->database, $rule)), 'rule ' . $rule);
            self::assertFalse($this->stateRow($rule), 'rule ' . $rule);
        }

        // The device's data sources were kept and their last rates are still
        // fresh, but a rule whose series is gone is not evaluated again.
        $this->at(self::NOW + 120);
        self::assertSame(0, $this->evaluate()->orphaned);
        foreach ([1, 2, 3, 6] as $rule) {
            self::assertCount(1, StoredIncidents::forRule($this->database, $rule), 'rule ' . $rule);
            self::assertFalse($this->stateRow($rule), 'rule ' . $rule);
        }
        self::assertSame(IncidentStatus::Open, StoredIncidents::forRule($this->database, 5)[0]->status, 'A data source with no device is not an orphan.');
        self::assertSame(array_fill(0, 6, 'opened'), $this->notifications(), 'Only the six openings were queued.');
    }

    public function testADisabledDeviceKeepsItsIncidentOpenAndUnknownForAsLongAsItLasts(): void
    {
        $this->watch(1, 101, 10);
        $this->sample(101, self::NOW, 95.0);
        $this->evaluate();
        $this->database->executeStatement("UPDATE host SET disabled = 'on' WHERE id = 10");

        // The poller stops sampling a disabled device, so its rate goes stale.
        foreach ([3 * self::STEP, 86_400, 30 * 86_400] as $later) {
            $this->at(self::NOW + $later);
            $report = $this->evaluate();
            self::assertSame(0, $report->orphaned);
        }

        self::assertEquals([new Incident(1, 1, IncidentStatus::Open, self::NOW)], StoredIncidents::forRule($this->database, 1));
        self::assertSame(1, $this->stateRow(1)['is_unknown']);
        self::assertSame(2, $this->states->saves, 'Opened once, became unknown once, then nothing.');
    }

    public function testCountersSaturateWithinTheColumn(): void
    {
        $this->watch(1, 101, 10);
        $this->states->save(1, new RuleState(true, RuleState::COUNTER_LIMIT, 0, false, self::NOW - self::STEP));
        $this->database->insert('alert_incident', ['rule_id' => 1, 'status' => 'open', 'active_rule_id' => 1, 'opened_at' => self::NOW - self::STEP, 'local_data_id' => 101, 'rrd_name' => 'traffic_in', 'rule_kind' => 'threshold']);
        $this->sample(101, self::NOW, 95.0);

        $this->evaluate();

        self::assertSame(RuleState::COUNTER_LIMIT, $this->stateRow(1)['consecutive_breaches']);
        self::assertSame(self::NOW, $this->stateRow(1)['last_observed_at']);
    }

    public function testAnUnreadableStateRowSkipsOnlyItsRuleAcrossACursorWrap(): void
    {
        foreach ([1, 2, 3, 4, 5] as $rule) {
            $this->watch($rule, 100 + $rule, 10);
            $this->sample(100 + $rule, self::NOW, 95.0);
        }
        // An ok run on a closed rule: no verdict sequence writes this.
        $this->database->insert('alert_rule_state', ['rule_id' => 3, 'incident_open' => 0, 'consecutive_oks' => 2, 'last_observed_at' => self::NOW - self::STEP]);
        $bad = $this->stateRow(3);
        $logger = new RecordingLogger();
        $cursor = new DbalEvaluationCursor($this->database);

        $runs = [];
        foreach (range(1, 4) as $run) {
            // A zero budget runs one batch of two per call.
            $report = $this->evaluate(batchSize: 2, budgetSeconds: 0.0, logger: $logger);
            $runs[] = [$report->evaluated, $report->failed, $cursor->position()];
        }

        self::assertSame([[2, 0, 2], [1, 1, 4], [1, 0, 0], [0, 0, 2]], $runs, 'The cursor passes rule 3 and wraps to rules 1 and 2.');
        foreach ([1, 2, 4, 5] as $rule) {
            self::assertEquals([new Incident($rule < 3 ? $rule : $rule - 1, $rule, IncidentStatus::Open, self::NOW)], StoredIncidents::forRule($this->database, $rule), 'rule ' . $rule);
        }
        self::assertSame([], StoredIncidents::forRule($this->database, 3));
        self::assertSame($bad, $this->stateRow(3), 'The unreadable row is left for an operator.');
        self::assertCount(1, $logger->records);
        self::assertSame(3, $logger->records[0]['context']['rule']);
        self::assertStringContainsString('alert_rule_state 3', $logger->records[0]['context']['reason']);
        self::assertFalse($this->database->isTransactionActive());
    }

    public function testAnIncidentThatDisagreesWithTheStateSkipsOnlyThatRule(): void
    {
        $this->watch(1, 101, 10);
        $this->watch(2, 102, 10);
        // Rule 1 has an open incident, but no state row says so.
        $this->database->insert('alert_incident', ['rule_id' => 1, 'status' => 'open', 'active_rule_id' => 1, 'opened_at' => self::NOW - self::STEP, 'local_data_id' => 101, 'rrd_name' => 'traffic_in', 'rule_kind' => 'threshold']);
        $this->sample(101, self::NOW, 95.0);
        $this->sample(102, self::NOW, 95.0);
        $logger = new RecordingLogger();

        $report = $this->evaluate(logger: $logger);

        self::assertSame([1, 1], [$report->evaluated, $report->failed]);
        self::assertFalse($this->stateRow(1), 'The state save was rolled back with the refused insert.');
        self::assertEquals([new Incident(1, 1, IncidentStatus::Open, self::NOW - self::STEP)], StoredIncidents::forRule($this->database, 1));
        self::assertSame(IncidentStatus::Open, StoredIncidents::forRule($this->database, 2)[0]->status);
        self::assertSame(1, $logger->records[0]['context']['rule']);
        self::assertSame(0, (new DbalEvaluationCursor($this->database))->position());
        self::assertFalse($this->database->isTransactionActive());

        self::assertSame(1, $this->evaluate()->failed, 'It stays skipped until the rows agree.');
    }

    public function testMoreOrphansThanABatchAreEndedOverSeveralRuns(): void
    {
        foreach ([1, 2, 3, 4, 5, 9] as $rule) {
            $this->watch($rule, 100 + $rule, 10);
            $this->sample(100 + $rule, self::NOW, 95.0);
        }
        $this->evaluate();
        $this->database->executeStatement('DELETE FROM data_local WHERE id IN (101, 102, 103, 104, 105)');

        $runs = [];
        foreach (range(1, 4) as $run) {
            $this->at(self::NOW + $run * self::STEP);
            $this->sample(109, self::NOW + $run * self::STEP, 95.0);
            $report = $this->evaluate(batchSize: 2);
            $runs[] = [$report->orphaned, $report->evaluated];
        }

        self::assertSame([[2, 1], [2, 1], [1, 1], [0, 1]], $runs, 'At most a batch of orphans per run, and rule 9 is evaluated every run.');
        foreach ([1, 2, 3, 4, 5] as $rule) {
            self::assertSame([IncidentStatus::Orphaned], array_map(static fn(Incident $incident): IncidentStatus => $incident->status, StoredIncidents::forRule($this->database, $rule)), 'rule ' . $rule);
            self::assertFalse($this->stateRow($rule), 'rule ' . $rule);
        }
        self::assertSame(IncidentStatus::Open, StoredIncidents::forRule($this->database, 9)[0]->status);
        self::assertSame(array_fill(0, 6, 'opened'), $this->notifications(), 'Only the six openings were queued.');
    }

    public function testAnOrphanWithAnUnreadableStateRowIsRemoved(): void
    {
        $this->watch(1, 101, 10);
        $this->database->insert('alert_incident', ['rule_id' => 1, 'status' => 'open', 'active_rule_id' => 1, 'opened_at' => self::NOW - self::STEP, 'local_data_id' => 101, 'rrd_name' => 'traffic_in', 'rule_kind' => 'threshold']);
        $this->database->insert('alert_rule_state', ['rule_id' => 1, 'incident_open' => 2, 'last_observed_at' => self::NOW - self::STEP]);
        $this->database->executeStatement('DELETE FROM data_local WHERE id = 101');

        $report = $this->evaluate();

        self::assertSame([1, 0], [$report->orphaned, $report->failed]);
        self::assertFalse($this->stateRow(1));
        self::assertEquals([new Incident(1, 1, IncidentStatus::Orphaned, self::NOW - self::STEP, self::NOW)], StoredIncidents::forRule($this->database, 1));
        self::assertSame(0, $this->evaluate()->orphaned);
    }
}
