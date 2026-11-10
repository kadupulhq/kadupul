<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Tests\Alerting\Infrastructure;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Kadupul\Alerting\Domain\Evaluation\RuleState;
use Kadupul\Alerting\Domain\Evaluation\Transition;
use Kadupul\Alerting\Domain\Incident\Incident;
use Kadupul\Alerting\Domain\Incident\IncidentStatus;
use Kadupul\Alerting\Domain\Rule\AlertRule;
use Kadupul\Alerting\Domain\Rule\BaselineDirection;
use Kadupul\Alerting\Domain\Rule\BaselineRule;
use Kadupul\Alerting\Domain\Rule\Comparator;
use Kadupul\Alerting\Domain\Rule\SeriesKey;
use Kadupul\Alerting\Domain\Rule\ThresholdRule;
use Kadupul\Alerting\Infrastructure\Dbal\DbalEvaluationCursor;
use Kadupul\Alerting\Infrastructure\Dbal\DbalIncidentRepository;
use Kadupul\Alerting\Infrastructure\Dbal\DbalNotificationQueue;
use Kadupul\Alerting\Infrastructure\Dbal\DbalRuleRepository;
use Kadupul\Alerting\Infrastructure\Dbal\DbalRuleStateStore;
use Kadupul\Alerting\Infrastructure\Dbal\DbalTransactionRunner;
use Kadupul\Alerting\Infrastructure\Dbal\StoredAlertingRowUnreadable;
use Kadupul\Alerting\Application\Port\NotificationContent;
use Kadupul\Alerting\Application\Port\UnusableRuleRecord;
use Kadupul\Alerting\Infrastructure\Dbal\StoredValue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The alerting stores' SQL on SQLite, which runs the same statements. The
 * shipped InnoDB tables, the engine's transactions and the evaluator against
 * them are covered by tests/integration/AlertPersistenceDatabaseTest.php.
 */
final class DbalAlertingStoresTest extends TestCase
{
    private Connection $database;

    protected function setUp(): void
    {
        $this->database = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        foreach ([
            "CREATE TABLE alert_rule (id INTEGER PRIMARY KEY, local_data_id INT NOT NULL, rrd_name BLOB NOT NULL, kind TEXT NOT NULL DEFAULT 'threshold', comparator TEXT NULL, trigger_threshold REAL NULL, clear_threshold REAL NULL, baseline_direction TEXT NULL, baseline_deviations REAL NULL, baseline_clear_deviations REAL NULL, baseline_floor REAL NULL, baseline_min_samples INT NULL, for_cycles INT NOT NULL DEFAULT 1, owner_id INT NOT NULL DEFAULT 0)",
            'CREATE TABLE alert_rule_state (rule_id INTEGER PRIMARY KEY, incident_open INT NOT NULL DEFAULT 0, consecutive_breaches INT NOT NULL DEFAULT 0, consecutive_oks INT NOT NULL DEFAULT 0, is_unknown INT NOT NULL DEFAULT 0, last_observed_at INT NULL)',
            'CREATE TABLE alert_incident (id INTEGER PRIMARY KEY AUTOINCREMENT, rule_id INT NOT NULL, status TEXT NOT NULL, active_rule_id INT NULL UNIQUE, opened_at INT NOT NULL, acknowledged_at INT NULL, acknowledged_by INT NULL, ended_at INT NULL)',
            'CREATE TABLE data_local (id INTEGER PRIMARY KEY, host_id INT NOT NULL DEFAULT 0)',
            "CREATE TABLE host (id INTEGER PRIMARY KEY, description TEXT NOT NULL DEFAULT '', snmp_community TEXT NULL, deleted TEXT NOT NULL DEFAULT '', disabled TEXT NOT NULL DEFAULT '')",
            "CREATE TABLE alert_notification (id INTEGER PRIMARY KEY AUTOINCREMENT, incident_id INT NOT NULL, kind TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'queued', attempts INT NOT NULL DEFAULT 0, queued_at INT NOT NULL, next_attempt_at INT NOT NULL, sent_at INT NULL, last_error TEXT NOT NULL DEFAULT '', observed_value REAL NOT NULL, threshold REAL NOT NULL)",
            'CREATE TABLE data_template_rrd (id INTEGER PRIMARY KEY, local_data_id INT NOT NULL, data_source_name TEXT NOT NULL)',
            'CREATE TABLE settings (name TEXT PRIMARY KEY, value TEXT NOT NULL)',
        ] as $ddl) {
            $this->database->executeStatement($ddl);
        }
    }

    private function addRule(int $id, int $localDataId, string $rrdName = 'traffic_in', string $comparator = 'gt', int $forCycles = 2): void
    {
        $this->database->insert('alert_rule', ['id' => $id, 'local_data_id' => $localDataId, 'rrd_name' => $rrdName, 'comparator' => $comparator, 'trigger_threshold' => 90.5, 'clear_threshold' => 80, 'for_cycles' => $forCycles]);
    }

    private function addSeries(int $localDataId, int $hostId, string $rrdName = 'traffic_in'): void
    {
        $this->database->insert('data_local', ['id' => $localDataId, 'host_id' => $hostId]);
        $this->database->insert('data_template_rrd', ['local_data_id' => $localDataId, 'data_source_name' => $rrdName]);
    }

    public function testRulesAreReadInIdOrderAfterTheCursor(): void
    {
        $this->addRule(9, 109, 'traffic_out', 'ge', 3);
        $this->addRule(4, 104);
        $this->addRule(2, 102);
        $this->addRule(6, 106);
        $this->addSeries(109, 0, 'traffic_out');
        $this->addSeries(104, 0);
        $this->addSeries(102, 0);
        // Rule 6's data source is gone: it is never evaluated again.
        $rules = new DbalRuleRepository($this->database);

        self::assertEquals(
            [
                new AlertRule(4, new SeriesKey(104, 'traffic_in'), new ThresholdRule(Comparator::GreaterThan, 90.5, 80.0, 2)),
                new AlertRule(9, new SeriesKey(109, 'traffic_out'), new ThresholdRule(Comparator::GreaterOrEqual, 90.5, 80.0, 3)),
            ],
            $rules->batchAfter(2, 10),
        );
        self::assertSame([2], array_map(static fn(AlertRule $rule): int => $rule->id, $rules->batchAfter(0, 1)));
        self::assertSame([], $rules->batchAfter(9, 10));
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function unreadableRules(): iterable
    {
        yield 'unknown comparator' => ['comparator', 'gte'];
        yield 'empty data source name' => ['rrd_name', ''];
        yield 'zero for_cycles' => ['for_cycles', 0];
        yield 'clear on the breaching side' => ['clear_threshold', 95];
        yield 'text threshold' => ['trigger_threshold', 'high'];
    }

    #[DataProvider('unreadableRules')]
    public function testARuleTheFormCannotWriteIsReported(string $column, mixed $value): void
    {
        $this->addRule(3, 103);
        $this->addSeries(103, 0, $column === 'rrd_name' ? '' : 'traffic_in');
        $this->database->update('alert_rule', [$column => $value], ['id' => 3]);
        $this->addRule(4, 104);
        $this->addSeries(104, 0);

        // The unreadable row stands in its place; it does not hide rule 4.
        [$unusable, $next] = (new DbalRuleRepository($this->database))->batchAfter(0, 10);

        self::assertInstanceOf(UnusableRuleRecord::class, $unusable);
        self::assertSame(3, $unusable->ruleId);
        self::assertStringContainsString('alert_rule 3', $unusable->reason);
        self::assertInstanceOf(AlertRule::class, $next);
        self::assertSame(4, $next->id);
    }

    private function addBaselineRule(int $id, int $localDataId): void
    {
        $this->database->insert('alert_rule', ['id' => $id, 'local_data_id' => $localDataId, 'rrd_name' => 'traffic_in', 'kind' => 'baseline', 'baseline_direction' => 'above', 'baseline_deviations' => 3.0, 'baseline_clear_deviations' => 2.5, 'baseline_floor' => 0.25, 'baseline_min_samples' => 5, 'for_cycles' => 2]);
    }

    public function testABaselineRuleIsReadWithItsParameters(): void
    {
        $this->addBaselineRule(4, 104);
        $this->addRule(5, 105);
        $this->addSeries(104, 0);
        $this->addSeries(105, 0);

        self::assertEquals(
            [
                new AlertRule(4, new SeriesKey(104, 'traffic_in'), new BaselineRule(3.0, 2.5, 0.25, 2, BaselineDirection::Above, 5)),
                new AlertRule(5, new SeriesKey(105, 'traffic_in'), new ThresholdRule(Comparator::GreaterThan, 90.5, 80.0, 2)),
            ],
            (new DbalRuleRepository($this->database))->batchAfter(0, 10),
        );
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function unreadableBaselineRules(): iterable
    {
        yield 'unknown kind' => [['kind' => 'Baseline']];
        yield 'unknown direction' => [['baseline_direction' => 'up']];
        yield 'missing direction' => [['baseline_direction' => null]];
        yield 'missing multiplier' => [['baseline_deviations' => null]];
        yield 'text multiplier' => [['baseline_deviations' => 'wide']];
        yield 'clear wider than trigger' => [['baseline_clear_deviations' => 4.0]];
        yield 'zero floor' => [['baseline_floor' => 0]];
        yield 'missing minimum samples' => [['baseline_min_samples' => null]];
        yield 'one sample' => [['baseline_min_samples' => 1]];
        yield 'more samples than the history kept' => [['baseline_min_samples' => 9]];
        yield 'a threshold column as well' => [['comparator' => 'gt']];
        yield 'a threshold rule with a baseline column' => [['kind' => 'threshold', 'comparator' => 'gt', 'trigger_threshold' => 90, 'clear_threshold' => 80]];
        yield 'a threshold rule without its thresholds' => [['kind' => 'threshold', 'baseline_direction' => null, 'baseline_deviations' => null, 'baseline_clear_deviations' => null, 'baseline_floor' => null, 'baseline_min_samples' => null]];
    }

    /** @param array<string, mixed> $columns */
    #[DataProvider('unreadableBaselineRules')]
    public function testAnUnreadableBaselineRuleIsQuarantinedNotFatal(array $columns): void
    {
        $this->addBaselineRule(3, 103);
        $this->addSeries(103, 0);
        $this->database->update('alert_rule', $columns, ['id' => 3]);
        $this->addBaselineRule(4, 104);
        $this->addSeries(104, 0);

        [$unusable, $next] = (new DbalRuleRepository($this->database))->batchAfter(0, 10);

        self::assertInstanceOf(UnusableRuleRecord::class, $unusable);
        self::assertSame(3, $unusable->ruleId);
        self::assertStringContainsString('alert_rule 3', $unusable->reason);
        self::assertInstanceOf(AlertRule::class, $next);
        self::assertInstanceOf(BaselineRule::class, $next->condition);
    }

    public function testOrphansAreRulesWithAGoneSeriesAndSomethingToEnd(): void
    {
        $this->database->insert('host', ['id' => 50]);
        $this->database->insert('host', ['id' => 51, 'deleted' => 'on']);
        $this->database->insert('host', ['id' => 52, 'disabled' => 'on']);
        $this->addSeries(101, 50);
        $this->addSeries(102, 51);
        $this->addSeries(103, 52);
        $this->addSeries(104, 0);
        $this->addSeries(105, 53);
        $this->addSeries(106, 50, 'traffic_out');
        $this->addSeries(108, 50);
        $cases = [
            1 => 101, // exists
            2 => 102, // device marked deleted on a remote collector
            3 => 103, // device disabled: not an orphan
            4 => 104, // no device (host_id 0): not an orphan
            5 => 105, // device row gone
            6 => 106, // data source no longer has traffic_in
            7 => 107, // data source gone
            8 => 108, // gone, but nothing stored to end
        ];
        foreach ($cases as $rule => $localDataId) {
            $this->addRule($rule, $localDataId, $localDataId === 108 ? 'missing' : 'traffic_in');
            if ($rule !== 8) {
                $this->database->insert('alert_rule_state', ['rule_id' => $rule, 'last_observed_at' => 100]);
            }
        }
        // Deleted rules: one with state, one with an active incident, one with only an ended incident.
        $this->database->insert('alert_rule_state', ['rule_id' => 20]);
        $this->database->insert('alert_incident', ['rule_id' => 21, 'status' => 'open', 'active_rule_id' => 21, 'opened_at' => 100]);
        $this->database->insert('alert_incident', ['rule_id' => 22, 'status' => 'closed', 'opened_at' => 100, 'ended_at' => 200]);
        // An orphan whose only stored thing is its active incident.
        $this->addRule(30, 130);
        $this->database->insert('alert_incident', ['rule_id' => 30, 'status' => 'acknowledged', 'active_rule_id' => 30, 'opened_at' => 100, 'acknowledged_at' => 110, 'acknowledged_by' => 1]);

        $rules = new DbalRuleRepository($this->database);
        self::assertSame([2, 5, 6, 7, 20, 21, 30], $rules->orphanedRuleIds(10));
        self::assertSame([2, 5, 6], $rules->orphanedRuleIds(3), 'The limit takes the lowest ids.');
    }

    public function testTheDataSourceNameMustMatchExactly(): void
    {
        $this->addSeries(101, 0, 'Traffic_In');
        $this->addRule(1, 101);
        $this->database->insert('alert_rule_state', ['rule_id' => 1]);

        self::assertSame([1], (new DbalRuleRepository($this->database))->orphanedRuleIds(10));
    }

    public function testEveryStateFieldSurvivesAReload(): void
    {
        $store = new DbalRuleStateStore($this->database);
        $states = [
            1 => new RuleState(true, 3, 0, true, 1_800_000_000),
            2 => new RuleState(true, 0, 2, false, 1_800_000_060),
            3 => new RuleState(false, RuleState::COUNTER_LIMIT, 0, false, 5),
            4 => new RuleState(),
        ];
        foreach ($states as $id => $state) {
            $store->save($id, $state);
        }
        $store->save(1, $states[1]);

        self::assertEquals($states, $store->load([4, 3, 2, 1, 1, 99]));
        self::assertSame(4, (int) $this->database->fetchOne('SELECT COUNT(*) FROM alert_rule_state'));

        $store->delete([2]);
        self::assertSame([1, 3, 4], array_keys($store->load([1, 2, 3, 4])));
        $store->delete([]);
        $store->delete([3, 4, 3, 99]);
        self::assertSame([1], array_keys($store->load([1, 2, 3, 4])));
        self::assertSame([], $store->load([]));
    }

    public function testStatesAreReadInChunks(): void
    {
        $store = new DbalRuleStateStore($this->database);
        foreach (range(1, 1001) as $id) {
            $store->save($id, new RuleState(false, 1, 0, false, $id));
        }

        $loaded = $store->load(range(1, 1001));

        self::assertCount(1001, $loaded);
        self::assertSame(1001, $loaded[1001]->lastObservedAt());
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function impossibleStateRows(): iterable
    {
        yield 'closed with an ok run' => [['incident_open' => 0, 'consecutive_oks' => 2, 'last_observed_at' => 100]];
        yield 'unknown while closed' => [['is_unknown' => 1, 'last_observed_at' => 100]];
        yield 'negative count' => [['incident_open' => 1, 'consecutive_breaches' => -1, 'last_observed_at' => 100]];
        yield 'flag of 2' => [['incident_open' => 2, 'last_observed_at' => 100]];
        yield 'open with no observation' => [['incident_open' => 1, 'consecutive_breaches' => 1]];
        yield 'text count' => [['consecutive_breaches' => 'x1', 'last_observed_at' => 100]];
    }

    /** @param array<string, mixed> $row */
    #[DataProvider('impossibleStateRows')]
    public function testAStateRowNoVerdictSequenceCanReachIsReported(array $row): void
    {
        $this->database->insert('alert_rule_state', ['rule_id' => 7] + $row);
        $store = new DbalRuleStateStore($this->database);
        $store->save(8, new RuleState(false, 1, 0, false, 100));

        $loaded = $store->load([7, 8]);

        self::assertInstanceOf(UnusableRuleRecord::class, $loaded[7]);
        self::assertSame(7, $loaded[7]->ruleId);
        self::assertStringContainsString('alert_rule_state 7', $loaded[7]->reason);
        self::assertEquals(new RuleState(false, 1, 0, false, 100), $loaded[8], 'One unreadable row does not hide the others.');

        // Deleting does not read the row, so an unreadable one goes too.
        $store->delete([7]);
        self::assertSame([8], array_keys($store->load([7, 8])));
    }

    public function testIncidentsOpenCloseAndOrphanTheActiveIncidentOnly(): void
    {
        $incidents = new DbalIncidentRepository($this->database);

        $incidents->open(1, 100);
        $incidents->close(1, 200);
        $incidents->open(1, 300);
        self::assertSame(1, $incidents->orphan([1], 400));
        self::assertSame(0, $incidents->orphan([1], 500), 'An ended incident is not ended again.');
        self::assertSame(0, $incidents->orphan([2], 500));
        self::assertSame(0, $incidents->orphan([], 500));

        self::assertEquals([
            new Incident(1, 1, IncidentStatus::Closed, 100, 200),
            new Incident(2, 1, IncidentStatus::Orphaned, 300, 400),
        ], $incidents->forRule(1));
    }

    public function testASecondActiveIncidentOrACloseWithNoneFails(): void
    {
        $incidents = new DbalIncidentRepository($this->database);
        $incidents->open(1, 100);

        try {
            $incidents->open(1, 200);
            self::fail('A second active incident was opened.');
        } catch (UnusableRuleRecord $e) {
            self::assertSame(1, $e->ruleId);
            self::assertStringContainsString('already has an open or acknowledged incident', $e->reason);
        }
        try {
            $incidents->close(2, 300);
            self::fail('A recovery closed an incident that does not exist.');
        } catch (UnusableRuleRecord $e) {
            self::assertSame([2, 'it recovered, but it has no open or acknowledged incident to close.'], [$e->ruleId, $e->reason]);
        }
    }

    public function testOrphaningEndsEveryActiveIncidentOfTheRulesInOneCall(): void
    {
        $incidents = new DbalIncidentRepository($this->database);
        foreach ([1, 2, 3] as $rule) {
            $incidents->open($rule, 100);
        }

        self::assertSame(2, $incidents->orphan([1, 3, 3, 9], 400));

        self::assertSame(IncidentStatus::Orphaned, $incidents->forRule(1)[0]->status);
        self::assertSame(IncidentStatus::Open, $incidents->forRule(2)[0]->status);
        self::assertSame(IncidentStatus::Orphaned, $incidents->forRule(3)[0]->status);
    }

    public function testAnAcknowledgedIncidentClosesAndKeepsItsAcknowledgement(): void
    {
        $this->database->insert('alert_incident', ['rule_id' => 4, 'status' => 'acknowledged', 'active_rule_id' => 4, 'opened_at' => 100, 'acknowledged_at' => 110, 'acknowledged_by' => 3]);
        $incidents = new DbalIncidentRepository($this->database);

        $incidents->close(4, 200);

        self::assertEquals([new Incident(1, 4, IncidentStatus::Closed, 100, 200, 110, 3)], $incidents->forRule(4));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function unreadableIncidents(): iterable
    {
        yield 'unknown status' => [['status' => 'resolved', 'active_rule_id' => null, 'ended_at' => 200]];
        yield 'ended but still active' => [['status' => 'closed', 'active_rule_id' => 5, 'ended_at' => 200]];
        yield 'open but not active' => [['status' => 'open', 'active_rule_id' => null]];
        yield 'closed with no end' => [['status' => 'closed', 'active_rule_id' => null]];
    }

    /** @param array<string, mixed> $row */
    #[DataProvider('unreadableIncidents')]
    public function testAnIncidentRowTheLifecycleCannotReachIsReported(array $row): void
    {
        $this->database->insert('alert_incident', $row + ['rule_id' => 5, 'opened_at' => 100]);

        $this->expectException(StoredAlertingRowUnreadable::class);
        $this->expectExceptionMessage('alert_incident 1');
        (new DbalIncidentRepository($this->database))->forRule(5);
    }

    public function testTheCursorIsASettingWrittenOnlyWhenItMoves(): void
    {
        $cursor = new DbalEvaluationCursor($this->database);
        self::assertSame(0, $cursor->position());

        $cursor->moveTo(0);
        self::assertFalse($this->database->fetchOne("SELECT value FROM settings WHERE name = 'alerting_evaluation_cursor'"), 'Moving to where it is writes nothing.');

        $cursor->moveTo(42);
        self::assertSame(42, $cursor->position());
        $cursor->moveTo(0);
        self::assertSame('0', $this->database->fetchOne("SELECT value FROM settings WHERE name = 'alerting_evaluation_cursor'"));

        $this->expectException(\InvalidArgumentException::class);
        $cursor->moveTo(-1);
    }

    public function testACursorSettingThatIsNotAnIdIsReported(): void
    {
        foreach (['abc', '-5', '07'] as $value) {
            $this->database->executeStatement("REPLACE INTO settings (name, value) VALUES ('alerting_evaluation_cursor', ?)", [$value]);
            try {
                (new DbalEvaluationCursor($this->database))->position();
                self::fail('Accepted cursor ' . $value);
            } catch (StoredAlertingRowUnreadable $e) {
                self::assertStringContainsString('alerting_evaluation_cursor', $e->getMessage());
            }
        }
    }

    public function testTheRunnerCommitsOrRollsBackTheWholeWork(): void
    {
        $runner = new DbalTransactionRunner($this->database);
        $store = new DbalRuleStateStore($this->database);

        $runner->run(static function () use ($store): void {
            $store->save(1, new RuleState(false, 1, 0, false, 100));
        });
        try {
            $runner->run(static function () use ($store): void {
                $store->save(2, new RuleState(false, 1, 0, false, 100));
                throw new \RuntimeException('incident write failed');
            });
            self::fail('The failure was not rethrown.');
        } catch (\RuntimeException $e) {
            self::assertSame('incident write failed', $e->getMessage());
        }

        self::assertSame([1], array_keys($store->load([1, 2])));
        self::assertFalse($this->database->isTransactionActive());
    }

    public function testTheRunnerLeavesACallersTransactionToTheCaller(): void
    {
        $runner = new DbalTransactionRunner($this->database);
        $store = new DbalRuleStateStore($this->database);

        $this->database->beginTransaction();
        $store->save(1, new RuleState(false, 1, 0, false, 100));
        $runner->run(static function () use ($store): void {
            $store->save(2, new RuleState(false, 1, 0, false, 100));
        });
        try {
            $runner->run(static function () use ($store): void {
                $store->save(3, new RuleState(false, 1, 0, false, 100));
                throw new \RuntimeException('failed');
            });
        } catch (\RuntimeException) {
        }
        self::assertTrue($this->database->isTransactionActive(), 'The runner did not commit or end the caller\'s transaction.');
        self::assertSame([1, 2], array_keys($store->load([1, 2, 3])));
        $this->database->rollBack();

        self::assertSame([], $store->load([1, 2, 3]));
    }

    public function testStoredValuesAreReadOnlyInTheirCanonicalForm(): void
    {
        self::assertSame(12, StoredValue::int('12', 'r', 'c'));
        self::assertSame(-3, StoredValue::int(-3, 'r', 'c'));
        self::assertSame(1.5, StoredValue::float('1.5', 'r', 'c'));
        self::assertSame(2.0, StoredValue::float(2, 'r', 'c'));
        self::assertNull(StoredValue::nullableInt(null, 'r', 'c'));
        foreach (['', ' 1', '1.0', '01', '99999999999999999999', null, 1.0] as $value) {
            try {
                StoredValue::int($value, 'r', 'c');
                self::fail('Accepted ' . var_export($value, true));
            } catch (StoredAlertingRowUnreadable) {
                self::addToAssertionCount(1);
            }
        }
        $this->expectException(StoredAlertingRowUnreadable::class);
        StoredValue::float('x', 'r', 'c');
    }

    public function testOpenAndCloseReturnTheIncidentTheyChanged(): void
    {
        $incidents = new DbalIncidentRepository($this->database);
        $this->database->insert('alert_incident', ['rule_id' => 9, 'status' => 'closed', 'opened_at' => 10, 'ended_at' => 20]);

        self::assertSame(2, $incidents->open(5, 100));
        self::assertSame(3, $incidents->open(6, 100));
        self::assertSame(2, $incidents->close(5, 200));
        self::assertSame(3, $incidents->close(6, 300));
        self::assertEquals([new Incident(2, 5, IncidentStatus::Closed, 100, 200)], $incidents->forRule(5));
    }

    public function testLockTellsWhetherTheRuleStillExists(): void
    {
        $this->addRule(3, 103);
        $rules = new DbalRuleRepository($this->database);

        self::assertTrue($rules->lock(3));
        self::assertFalse($rules->lock(4));
    }

    public function testEachDueNotificationIsClaimedOnceAndSettledOnlyByItsHolder(): void
    {
        $this->addRule(5, 105);
        $this->addSeries(105, 7);
        $this->database->insert('host', ['id' => 7, 'description' => 'edge-1', 'snmp_community' => 'never-in-mail']);
        $incident = (new DbalIncidentRepository($this->database))->open(5, 900);
        $queue = new DbalNotificationQueue($this->database);
        $queue->enqueue($incident, Transition::Opened, 95.5, 90.5, 1000);
        $queue->enqueue($incident, Transition::Opened, 96.0, 90.5, 1010);

        self::assertSame([], $queue->due(999, 5, 10), 'Not due before its queue time.');
        self::assertSame([1, 2], $queue->due(1010, 5, 10));
        self::assertSame([1], $queue->due(1010, 5, 1));

        $claim = $queue->claim(1, 1010, 5, 1910);
        self::assertNotNull($claim);
        self::assertEquals(new NotificationContent($incident, 5, Transition::Opened, 95.5, 90.5, 900, 105, 'traffic_in', 'edge-1'), $claim->content);
        self::assertSame([1, 1910], [$claim->attempts, $claim->leaseUntil]);
        self::assertNull($queue->claim(1, 1010, 5, 1910), 'A held row cannot be claimed again.');
        self::assertSame([2], $queue->due(1010, 5, 10));

        self::assertTrue($queue->markSent($claim, 1020));
        self::assertFalse($queue->markSent($claim, 1030), 'A settled claim is no longer held.');
        self::assertSame(['sent', '1', '1020'], array_map('strval', $this->database->fetchNumeric('SELECT status, attempts, sent_at FROM alert_notification WHERE id = 1') ?: []));

        // Row 2's claim expires; a second deliverer takes it and the first holder loses it.
        $first = $queue->claim(2, 1010, 5, 1910);
        self::assertNotNull($first);
        self::assertSame([], $queue->due(1909, 5, 10));
        self::assertSame([2], $queue->due(1910, 5, 10));
        $second = $queue->claim(2, 1910, 5, 2810);
        self::assertNotNull($second);
        self::assertSame(2, $second->attempts);
        self::assertFalse($queue->markSent($first, 1950), 'The first holder lost its claim and cannot mark it sent.');
        self::assertFalse($queue->markAttemptFailed($first, 'late', 2000));
        self::assertTrue($queue->markAttemptFailed($second, 'MailDeliveryFailed: x', 3000));
        self::assertSame(['queued', '2', '3000', 'MailDeliveryFailed: x'], array_map('strval', $this->database->fetchNumeric('SELECT status, attempts, next_attempt_at, last_error FROM alert_notification WHERE id = 2') ?: []));

        // Out of attempts: not due, not claimable, and an expired last claim fails.
        $this->database->executeStatement("UPDATE alert_notification SET attempts = 4 WHERE id = 2");
        $last = $queue->claim(2, 3000, 5, 3900);
        self::assertNotNull($last);
        self::assertSame([], $queue->due(3900, 5, 10));
        self::assertNull($queue->claim(2, 3900, 5, 4800));
        self::assertSame(0, $queue->failExpiredClaims(3899, 5), 'A live claim is not failed.');
        self::assertSame(1, $queue->failExpiredClaims(3900, 5));
        self::assertSame(['failed', DbalNotificationQueue::EXPIRED], array_map('strval', $this->database->fetchNumeric('SELECT status, last_error FROM alert_notification WHERE id = 2') ?: []));
    }

    public function testARecoveryReadsItsEndTimeAndAGoneSeriesIsNotNamed(): void
    {
        $this->addRule(5, 105);
        $incidents = new DbalIncidentRepository($this->database);
        $incidents->open(5, 900);
        $incident = $incidents->close(5, 1200);
        $queue = new DbalNotificationQueue($this->database);
        $queue->enqueue($incident, Transition::Recovered, 10.0, 80.0, 1200);

        // Rule 5's data source row is missing.
        self::assertEquals(new NotificationContent($incident, 5, Transition::Recovered, 10.0, 80.0, 1200), $queue->claim(1, 1200, 5, 2100)?->content);
    }

    public function testANotificationThatCannotBeReadIsClaimedWithNoContent(): void
    {
        $queue = new DbalNotificationQueue($this->database);
        // Incident 99 does not exist.
        $queue->enqueue(99, Transition::Opened, 1.0, 0.5, 100);
        $this->database->insert('alert_notification', ['incident_id' => 99, 'kind' => 'became_unknown', 'queued_at' => 100, 'next_attempt_at' => 100, 'observed_value' => 1, 'threshold' => 1]);

        foreach ([1, 2] as $id) {
            $claim = $queue->claim($id, 100, 5, 1000);
            self::assertNotNull($claim);
            self::assertNull($claim->content, 'row ' . $id);
        }
    }

    public function testTheValueAndThresholdAreStoredWithoutLosingDigits(): void
    {
        $incident = (new DbalIncidentRepository($this->database))->open(5, 900);
        $queue = new DbalNotificationQueue($this->database);
        $queue->enqueue($incident, Transition::Opened, 123456789.12345679, 0.1 + 0.2, 1000);

        $content = $queue->claim(1, 1000, 5, 1900)?->content;

        self::assertSame([123456789.12345679, 0.1 + 0.2], [$content?->value, $content?->threshold]);
    }

    public function testOnlyTheHolderEndsARowAsSuppressedAndItIsNotDueAgain(): void
    {
        $incident = (new DbalIncidentRepository($this->database))->open(5, 900);
        $queue = new DbalNotificationQueue($this->database);
        $queue->enqueue($incident, Transition::Opened, 1.0, 0.5, 1000);
        $first = $queue->claim(1, 1000, 5, 1900);
        self::assertNotNull($first);
        $second = $queue->claim(1, 1900, 5, 2800);
        self::assertNotNull($second);

        self::assertFalse($queue->markSuppressed($first, 'administrator notification: Disabled'), 'A lost claim cannot settle the row.');
        self::assertTrue($queue->markSuppressed($second, 'administrator notification: Disabled'));

        self::assertSame(['suppressed', '2', 'administrator notification: Disabled'], array_map('strval', $this->database->fetchNumeric('SELECT status, attempts, last_error FROM alert_notification WHERE id = 1') ?: []));
        self::assertSame([], $queue->due(10_000, 5, 10));
        self::assertNull($queue->claim(1, 10_000, 5, 10_900));
        self::assertSame(0, $queue->failExpiredClaims(10_000, 1));
    }

    public function testOnlyAnOpenedOrRecoveredIncidentIsQueued(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new DbalNotificationQueue($this->database))->enqueue(1, Transition::BecameUnknown, 1.0, 1.0, 100);
    }
}
