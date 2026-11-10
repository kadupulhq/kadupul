<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests\Alerting\Infrastructure;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Kadupul\Alerting\Application\Port\IncidentAccess;
use Kadupul\Alerting\Application\Port\RuleAccess;
use Kadupul\Alerting\Application\Port\RuleStore;
use Kadupul\Alerting\Application\Query\AcknowledgeOutcome;
use Kadupul\Alerting\Application\Query\AlertingAccessDenied;
use Kadupul\Alerting\Application\ReadModel\NotificationView;
use Kadupul\Alerting\Application\Rule\RuleSubmission;
use Kadupul\Alerting\Application\Rule\RuleSubmissionRefused;
use Kadupul\Alerting\Infrastructure\Dbal\DbalIncidentCatalog;
use Kadupul\Alerting\Infrastructure\Dbal\DbalIncidentStore;
use Kadupul\Alerting\Infrastructure\Dbal\DbalRuleCatalog;
use Kadupul\Alerting\Infrastructure\Dbal\DbalRuleStore;
use Kadupul\Alerting\Infrastructure\Dbal\SeriesVisibility;
use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\ManagementVisibility;
use Kadupul\IdentityAccess\Infrastructure\Legacy\LegacyAuditTrail;
use Kadupul\Platform\Contract\LegacyConfiguration;
use Kadupul\Tests\Alerting\Fakes\MockClockAdapter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * The rule and incident page stores on SQLite, with the real audit sink in a
 * private directory. Device visibility is a fixed allowlist standing in for
 * ManagementVisibility, whose own rules are tested in IdentityAccess; the
 * InnoDB tables, locks and the real visibility projection are covered by
 * tests/integration/AlertPagesDatabaseTest.php.
 */
final class DbalAlertingPagesTest extends TestCase
{
    private const int VIEWER = 42;
    private const int NOW = 1_800_000_000;

    private Connection $database;
    private string $project;
    /** @var array<int, list<int>> devices each actor may see */
    private array $devices = [self::VIEWER => [1]];
    private DbalRuleStore $rules;
    private DbalRuleCatalog $ruleCatalog;
    private DbalIncidentStore $incidents;
    private DbalIncidentCatalog $incidentCatalog;
    private bool $revoked = false;

    protected function setUp(): void
    {
        $this->database = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        foreach ([
            "CREATE TABLE alert_rule (id INTEGER PRIMARY KEY AUTOINCREMENT, local_data_id INT NOT NULL, rrd_name BLOB NOT NULL, kind TEXT NOT NULL DEFAULT 'threshold', comparator TEXT NULL, trigger_threshold REAL NULL, clear_threshold REAL NULL, baseline_direction TEXT NULL, baseline_deviations REAL NULL, baseline_clear_deviations REAL NULL, baseline_floor REAL NULL, baseline_min_samples INT NULL, for_cycles INT NOT NULL DEFAULT 1, owner_id INT NOT NULL DEFAULT 0)",
            'CREATE TABLE alert_rule_state (rule_id INTEGER PRIMARY KEY, incident_open INT NOT NULL DEFAULT 0, consecutive_breaches INT NOT NULL DEFAULT 0, consecutive_oks INT NOT NULL DEFAULT 0, is_unknown INT NOT NULL DEFAULT 0, last_observed_at INT NULL)',
            'CREATE TABLE alert_incident (id INTEGER PRIMARY KEY AUTOINCREMENT, rule_id INT NOT NULL, status TEXT NOT NULL, active_rule_id INT NULL UNIQUE, opened_at INT NOT NULL, acknowledged_at INT NULL, acknowledged_by INT NULL, ended_at INT NULL)',
            "CREATE TABLE alert_notification (id INTEGER PRIMARY KEY AUTOINCREMENT, incident_id INT NOT NULL, kind TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'queued', attempts INT NOT NULL DEFAULT 0, queued_at INT NOT NULL, next_attempt_at INT NOT NULL, sent_at INT NULL, last_error TEXT NOT NULL DEFAULT '', observed_value REAL NOT NULL, threshold REAL NOT NULL)",
            'CREATE TABLE data_local (id INTEGER PRIMARY KEY, host_id INT NOT NULL DEFAULT 0)',
            "CREATE TABLE host (id INTEGER PRIMARY KEY, description TEXT NOT NULL DEFAULT '', deleted TEXT NOT NULL DEFAULT '')",
            'CREATE TABLE data_template_rrd (id INTEGER PRIMARY KEY, local_data_id INT NOT NULL, data_source_name TEXT NOT NULL)',
            'CREATE TABLE settings (name TEXT PRIMARY KEY, value TEXT NOT NULL)',
            "CREATE TABLE user_auth (id INTEGER PRIMARY KEY, username TEXT NOT NULL DEFAULT '')",
        ] as $ddl) {
            $this->database->executeStatement($ddl);
        }
        $this->database->insert('host', ['id' => 1, 'description' => 'Core <router>']);
        $this->database->insert('host', ['id' => 2, 'description' => 'Payroll server']);
        $this->database->insert('user_auth', ['id' => self::VIEWER, 'username' => 'operator']);
        $this->addSeries(101, 1);
        $this->addSeries(201, 2);
        $this->addSeries(301, 0);

        $this->project = sys_get_temp_dir() . '/kadupul-alerting-pages-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->project . '/log', 0700, true));
        $visibility = new SeriesVisibility(new class ($this->devices) implements ManagementVisibility {
            /** @param array<int, list<int>> $devices */
            public function __construct(private array $devices) {}

            public function forActor(int $actorId): array
            {
                $ids = implode(', ', array_merge([0], $this->devices[$actorId] ?? []));

                return ['graphs' => 'SELECT 0', 'devices' => "SELECT h.id FROM host h WHERE h.id IN ($ids) AND h.id > 0"];
            }
        });
        $clock = new MockClockAdapter(new MockClock('@' . self::NOW));
        $configuration = $this->createStub(LegacyConfiguration::class);
        $configuration->method('values')->willReturn(['collector_id' => 1]);
        $audit = new LegacyAuditTrail($this->project);
        $ruleAccess = $this->createStub(RuleAccess::class);
        $ruleAccess->method('authorize')->willReturn(new Actor(self::VIEWER, 'operator'));
        $ruleAccess->method('assertCurrent')->willReturnCallback(fn(int $id) => $this->revoked ? throw new AlertingAccessDenied(false) : null);
        $incidentAccess = $this->createStub(IncidentAccess::class);
        $incidentAccess->method('assertCurrent')->willReturnCallback(fn(int $id) => $this->revoked ? throw new AlertingAccessDenied(false) : null);
        $this->rules = new DbalRuleStore($this->database, $visibility, $clock, $ruleAccess, $audit, $configuration);
        $this->ruleCatalog = new DbalRuleCatalog($this->database, $visibility);
        $this->incidents = new DbalIncidentStore($this->database, $visibility, $incidentAccess, $audit, $configuration);
        $this->incidentCatalog = new DbalIncidentCatalog($this->database, $visibility);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->project . '/log/{,.}*', GLOB_BRACE) ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        @rmdir($this->project . '/log');
        @rmdir($this->project);
    }

    public function test_rule_cannot_reference_data_source_outside_viewer_permissions(): void
    {
        foreach ([201 => 'traffic_in', 999 => 'traffic_in', 101 => 'traffic_out'] as $localDataId => $name) {
            try {
                $this->rules->create(self::VIEWER, $this->threshold($localDataId, $name));
                self::fail('A rule on data source ' . $localDataId . '/' . $name . ' was saved.');
            } catch (RuleSubmissionRefused $refused) {
                // The hidden data source, a missing one and a missing RRD name read alike.
                self::assertSame(RuleStore::SERIES_UNAVAILABLE, $refused->getMessage());
            }
        }
        self::assertSame(0, $this->rows('alert_rule'));

        // A visible rule cannot be moved onto the hidden data source either.
        $id = $this->rules->create(self::VIEWER, $this->threshold(101));
        $revision = $this->ruleCatalog->find(self::VIEWER, $id)?->revision ?? '';
        try {
            $this->rules->update(self::VIEWER, $id, $revision, $this->threshold(201));
            self::fail('The rule was moved to a hidden data source.');
        } catch (RuleSubmissionRefused $refused) {
            self::assertSame(RuleStore::SERIES_UNAVAILABLE, $refused->getMessage());
        }
        self::assertSame(101, (int) $this->database->fetchOne('SELECT local_data_id FROM alert_rule WHERE id = ?', [$id]));

        // A data source without a device is visible, and the owner is the actor.
        $this->rules->create(self::VIEWER, $this->threshold(301));
        self::assertSame([[101, self::VIEWER], [301, self::VIEWER]], array_map(
            static fn(array $row): array => [(int) $row['local_data_id'], (int) $row['owner_id']],
            $this->database->fetchAllAssociative('SELECT local_data_id, owner_id FROM alert_rule ORDER BY id'),
        ));
    }

    public function testRulesOnHiddenDataSourcesCannotBeListedReadEditedOrDeleted(): void
    {
        $hidden = $this->insertRule(201);
        $visible = $this->insertRule(101);
        $gone = $this->insertRule(555);

        self::assertSame([$visible, $gone], array_map(static fn($rule): int => $rule->id, $this->ruleCatalog->page(self::VIEWER, 1)->rules));
        self::assertSame(2, $this->ruleCatalog->page(self::VIEWER, 1)->total);
        self::assertNull($this->ruleCatalog->find(self::VIEWER, $hidden));
        $view = $this->ruleCatalog->find(self::VIEWER, $visible);
        self::assertSame('Core <router>', $view?->device);
        self::assertTrue($view->seriesExists);
        self::assertFalse($this->ruleCatalog->find(self::VIEWER, $gone)?->seriesExists);

        foreach (['update', 'delete'] as $operation) {
            try {
                $operation === 'update'
                    ? $this->rules->update(self::VIEWER, $hidden, 'x', $this->threshold(101))
                    : $this->rules->delete(self::VIEWER, $hidden, 'x');
                self::fail($operation . ' changed a hidden rule.');
            } catch (RuleSubmissionRefused $refused) {
                self::assertSame(RuleStore::NOT_FOUND, $refused->getMessage());
            }
        }
        self::assertSame(3, $this->rows('alert_rule'));
    }

    public function testIncidentsOfHiddenRulesCannotBeListedViewedOrAcknowledged(): void
    {
        $hidden = $this->openIncident($this->insertRule(201));
        $visible = $this->openIncident($this->insertRule(101));

        self::assertSame([$visible], array_map(static fn($view): int => $view->id, $this->incidentCatalog->page(self::VIEWER, true, 1)->incidents));
        self::assertNull($this->incidentCatalog->find(self::VIEWER, $hidden, self::NOW));
        self::assertNotNull($this->incidentCatalog->find(self::VIEWER, $visible, self::NOW));
        self::assertSame(AcknowledgeOutcome::NotFound, $this->incidents->acknowledge(self::VIEWER, $hidden, self::NOW));
        self::assertSame(AcknowledgeOutcome::NotFound, $this->incidents->acknowledge(self::VIEWER, 9999, self::NOW));
        self::assertSame(['open', null, null], $this->incidentRow($hidden));
    }

    public function testDeletingARuleWithAnOpenIncidentOrphansItAndKeepsItsQueuedNotification(): void
    {
        $rule = $this->insertRule(101);
        $incident = $this->openIncident($rule);
        $this->database->insert('alert_rule_state', ['rule_id' => $rule, 'incident_open' => 1, 'consecutive_breaches' => 3]);
        $this->database->insert('alert_notification', ['incident_id' => $incident, 'kind' => 'opened', 'queued_at' => self::NOW - 60, 'next_attempt_at' => self::NOW - 60, 'observed_value' => 99, 'threshold' => 90]);

        $revision = $this->ruleCatalog->find(self::VIEWER, $rule)?->revision ?? '';
        $this->rules->delete(self::VIEWER, $rule, $revision);

        self::assertSame(0, $this->rows('alert_rule'));
        self::assertSame(0, $this->rows('alert_rule_state'));
        self::assertSame(['orphaned', null, self::NOW], array_map(
            static fn(mixed $value): mixed => $value === null ? null : (is_numeric($value) ? (int) $value : $value),
            array_values($this->database->fetchAssociative('SELECT status, active_rule_id, ended_at FROM alert_incident WHERE id = ?', [$incident]) ?: []),
        ));
        // No notification is queued for the orphan; the queued one is still sent.
        self::assertSame([['opened', 'queued']], $this->database->fetchAllNumeric('SELECT kind, status FROM alert_notification'));
        self::assertSame(['alerting.rule.delete', 'rule', (string) $rule, 'allowed', 'succeeded'], $this->lastAudit());
    }

    public function testChangingTheSeriesOrKindEndsTheIncidentButOtherChangesKeepIt(): void
    {
        $rule = $this->insertRule(101);
        $incident = $this->openIncident($rule);
        $this->database->insert('alert_rule_state', ['rule_id' => $rule, 'incident_open' => 1]);

        $this->rules->update(self::VIEWER, $rule, $this->revision($rule), $this->threshold(101, 'traffic_in', '95'));
        self::assertSame(['open', $rule, null], $this->incidentRow($incident, true));
        self::assertSame(1, $this->rows('alert_rule_state'));
        self::assertSame(95.0, (float) $this->database->fetchOne('SELECT trigger_threshold FROM alert_rule'));

        $this->rules->update(self::VIEWER, $rule, $this->revision($rule), RuleSubmission::definition([
            'kind' => 'baseline', 'local_data_id' => '101', 'rrd_name' => 'traffic_in', 'for_cycles' => '2',
            'baseline_direction' => 'above', 'baseline_deviations' => '3', 'baseline_clear_deviations' => '2', 'baseline_floor' => '0.5', 'baseline_min_samples' => '4',
        ]));
        self::assertSame(['orphaned', null, self::NOW], $this->incidentRow($incident, true));
        self::assertSame(0, $this->rows('alert_rule_state'));
        self::assertSame(['baseline', null, 'above', 4], array_map(
            static fn(mixed $value): mixed => is_int($value) || $value === null || !is_numeric($value) ? $value : (int) $value,
            array_values($this->database->fetchAssociative('SELECT kind, trigger_threshold, baseline_direction, baseline_min_samples FROM alert_rule') ?: []),
        ));
    }

    public function testABaselineRuleAlwaysWritesItsSampleCountAndDirection(): void
    {
        $id = $this->rules->create(self::VIEWER, RuleSubmission::definition([
            'kind' => 'baseline', 'local_data_id' => '101', 'rrd_name' => 'traffic_in', 'for_cycles' => '1',
            'baseline_deviations' => '3', 'baseline_clear_deviations' => '2', 'baseline_floor' => '1',
        ]));
        self::assertSame(['both', 4], array_map(
            static fn(mixed $value): mixed => is_numeric($value) ? (int) $value : $value,
            array_values($this->database->fetchAssociative('SELECT baseline_direction, baseline_min_samples FROM alert_rule WHERE id = ?', [$id]) ?: []),
        ));
    }

    public function testAStaleRevisionIsRefusedWithoutAWrite(): void
    {
        $rule = $this->insertRule(101);
        $revision = $this->revision($rule);
        $this->database->update('alert_rule', ['trigger_threshold' => 91], ['id' => $rule]);
        foreach (['update', 'delete'] as $operation) {
            try {
                $operation === 'update'
                    ? $this->rules->update(self::VIEWER, $rule, $revision, $this->threshold(101, 'traffic_in', '99'))
                    : $this->rules->delete(self::VIEWER, $rule, $revision);
                self::fail('A stale ' . $operation . ' was applied.');
            } catch (RuleSubmissionRefused $refused) {
                self::assertSame(RuleStore::STALE, $refused->getMessage());
            }
        }
        self::assertSame(91.0, (float) $this->database->fetchOne('SELECT trigger_threshold FROM alert_rule'));
        self::assertSame(['alerting.rule.delete', 'rule', (string) $rule, 'allowed', 'failed'], $this->lastAudit());
    }

    public function testAcknowledgeRecordsTheActorAndTimeAndAuditsIt(): void
    {
        $incident = $this->openIncident($this->insertRule(101));

        self::assertSame(AcknowledgeOutcome::Acknowledged, $this->incidents->acknowledge(self::VIEWER, $incident, self::NOW));
        self::assertSame(['acknowledged', self::NOW, self::VIEWER], $this->incidentRow($incident));
        $entry = $this->auditEntries()[0];
        self::assertSame(['id' => self::VIEWER], $entry['actor']);
        self::assertSame('alerting.incident.acknowledge', $entry['action']);
        self::assertSame(['type' => 'alert_incident', 'id' => (string) $incident], $entry['target']);
        self::assertSame(['allowed', 'succeeded'], [$entry['decision'], $entry['outcome']]);
        self::assertMatchesRegularExpression('/\A\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ\z/', $entry['recorded_at']);

        $detail = $this->incidentCatalog->find(self::VIEWER, $incident, self::NOW);
        self::assertSame('operator', $detail?->view->acknowledgedBy);
        self::assertSame(self::NOW, $detail->view->incident?->acknowledgedAt);

        // Repeating it keeps the first acknowledgement.
        self::assertSame(AcknowledgeOutcome::AlreadyAcknowledged, $this->incidents->acknowledge(self::VIEWER, $incident, self::NOW + 60));
        self::assertSame(['acknowledged', self::NOW, self::VIEWER], $this->incidentRow($incident));
    }

    public function testEndedIncidentsAndRevokedActorsAreNotAcknowledged(): void
    {
        $rule = $this->insertRule(101);
        $this->database->insert('alert_incident', ['rule_id' => $rule, 'status' => 'closed', 'opened_at' => self::NOW - 600, 'ended_at' => self::NOW - 60]);
        $closed = (int) $this->database->lastInsertId();
        self::assertSame(AcknowledgeOutcome::Ended, $this->incidents->acknowledge(self::VIEWER, $closed, self::NOW));
        self::assertSame(['closed', null, null], $this->incidentRow($closed));
        self::assertSame(['allowed', 'failed'], array_slice($this->lastAudit(), 3));

        $open = $this->openIncident($rule);
        $this->revoked = true;
        try {
            $this->incidents->acknowledge(self::VIEWER, $open, self::NOW);
            self::fail('A revoked actor acknowledged the incident.');
        } catch (AlertingAccessDenied) {
        }
        self::assertSame(['open', null, null], $this->incidentRow($open));
        self::assertSame(['denied', 'denied'], array_slice($this->lastAudit(), 3));
    }

    public function testAnExpiredSendingClaimIsShownAsRetryingOrFailed(): void
    {
        $incident = $this->openIncident($this->insertRule(101));
        foreach ([
            ['sending', 1, self::NOW + 300],
            ['sending', 2, self::NOW - 1],
            ['sending', 5, self::NOW - 1],
            ['queued', 0, self::NOW],
            ['queued', 3, self::NOW + 60],
            ['sent', 1, self::NOW - 600],
        ] as [$status, $attempts, $next]) {
            $this->database->insert('alert_notification', ['incident_id' => $incident, 'kind' => 'opened', 'status' => $status, 'attempts' => $attempts, 'queued_at' => self::NOW - 900, 'next_attempt_at' => $next, 'observed_value' => 1, 'threshold' => 1]);
        }
        $shown = array_map(
            static fn(NotificationView $n): array => [$n->status, $n->nextAttemptAt],
            $this->incidentCatalog->find(self::VIEWER, $incident, self::NOW)?->notifications ?? [],
        );
        self::assertSame([
            ['sending', null],
            ['retrying', self::NOW - 1],
            ['failed', null],
            ['queued', self::NOW],
            ['retrying', self::NOW + 60],
            ['sent', null],
        ], $shown);
    }

    private function addSeries(int $localDataId, int $hostId, string $rrdName = 'traffic_in'): void
    {
        $this->database->insert('data_local', ['id' => $localDataId, 'host_id' => $hostId]);
        $this->database->insert('data_template_rrd', ['local_data_id' => $localDataId, 'data_source_name' => $rrdName]);
    }

    private function insertRule(int $localDataId): int
    {
        $this->database->insert('alert_rule', ['local_data_id' => $localDataId, 'rrd_name' => 'traffic_in', 'comparator' => 'gt', 'trigger_threshold' => 90, 'clear_threshold' => 80, 'for_cycles' => 1, 'owner_id' => 1]);

        return (int) $this->database->lastInsertId();
    }

    private function openIncident(int $rule): int
    {
        $this->database->insert('alert_incident', ['rule_id' => $rule, 'status' => 'open', 'active_rule_id' => $rule, 'opened_at' => self::NOW - 600]);

        return (int) $this->database->lastInsertId();
    }

    private function threshold(int $localDataId, string $name = 'traffic_in', string $trigger = '90'): \Kadupul\Alerting\Application\Rule\RuleDefinition
    {
        return RuleSubmission::definition(['kind' => 'threshold', 'local_data_id' => (string) $localDataId, 'rrd_name' => $name, 'for_cycles' => '1', 'comparator' => 'gt', 'trigger_threshold' => $trigger, 'clear_threshold' => '80']);
    }

    private function revision(int $rule): string
    {
        return $this->ruleCatalog->find(self::VIEWER, $rule)?->revision ?? '';
    }

    private function rows(string $table): int
    {
        return (int) $this->database->fetchOne('SELECT COUNT(*) FROM ' . $table);
    }

    /** @return list<int|string|null> status, then acknowledged_at and _by, or active_rule_id and ended_at */
    private function incidentRow(int $id, bool $lifecycle = false): array
    {
        $row = $this->database->fetchNumeric($lifecycle
            ? 'SELECT status, active_rule_id, ended_at FROM alert_incident WHERE id = ?'
            : 'SELECT status, acknowledged_at, acknowledged_by FROM alert_incident WHERE id = ?', [$id]) ?: [];

        return array_map(static fn(mixed $value): mixed => $value === null || !is_numeric($value) ? $value : (int) $value, $row);
    }

    /** @return list<array<string, mixed>> */
    private function auditEntries(): array
    {
        $lines = file($this->project . '/log/kadupul-audit.jsonl', FILE_IGNORE_NEW_LINES) ?: [];

        return array_map(static fn(string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR), $lines);
    }

    /** @return list<string> action, target type and id, decision, outcome */
    private function lastAudit(): array
    {
        $entries = $this->auditEntries();
        $entry = end($entries);
        self::assertIsArray($entry);

        return [$entry['action'], $entry['target']['type'] === 'alert_rule' ? 'rule' : $entry['target']['type'], $entry['target']['id'], $entry['decision'], $entry['outcome']];
    }
}
