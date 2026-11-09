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
use Kadupul\Alerting\Domain\Incident\Incident;
use Kadupul\Alerting\Domain\Incident\IncidentStatus;
use Kadupul\Alerting\Domain\Rule\AlertRule;
use Kadupul\Alerting\Domain\Rule\Comparator;
use Kadupul\Alerting\Domain\Rule\SeriesKey;
use Kadupul\Alerting\Domain\Rule\ThresholdRule;
use Kadupul\Alerting\Infrastructure\Dbal\DbalEvaluationCursor;
use Kadupul\Alerting\Infrastructure\Dbal\DbalIncidentRepository;
use Kadupul\Alerting\Infrastructure\Dbal\DbalRuleRepository;
use Kadupul\Alerting\Infrastructure\Dbal\DbalRuleStateStore;
use Kadupul\Alerting\Infrastructure\Dbal\DbalTransactionRunner;
use Kadupul\Alerting\Infrastructure\Dbal\StoredAlertingRowUnreadable;
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
            'CREATE TABLE alert_rule (id INTEGER PRIMARY KEY, local_data_id INT NOT NULL, rrd_name BLOB NOT NULL, comparator TEXT NOT NULL, trigger_threshold REAL NOT NULL, clear_threshold REAL NOT NULL, for_cycles INT NOT NULL DEFAULT 1, owner_id INT NOT NULL DEFAULT 0)',
            'CREATE TABLE alert_rule_state (rule_id INTEGER PRIMARY KEY, incident_open INT NOT NULL DEFAULT 0, consecutive_breaches INT NOT NULL DEFAULT 0, consecutive_oks INT NOT NULL DEFAULT 0, is_unknown INT NOT NULL DEFAULT 0, last_observed_at INT NULL)',
            'CREATE TABLE alert_incident (id INTEGER PRIMARY KEY AUTOINCREMENT, rule_id INT NOT NULL, status TEXT NOT NULL, active_rule_id INT NULL UNIQUE, opened_at INT NOT NULL, acknowledged_at INT NULL, acknowledged_by INT NULL, ended_at INT NULL)',
            'CREATE TABLE data_local (id INTEGER PRIMARY KEY, host_id INT NOT NULL DEFAULT 0)',
            "CREATE TABLE host (id INTEGER PRIMARY KEY, deleted TEXT NOT NULL DEFAULT '', disabled TEXT NOT NULL DEFAULT '')",
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
}
