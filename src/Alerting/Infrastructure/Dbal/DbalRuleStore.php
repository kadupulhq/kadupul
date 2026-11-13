<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Alerting\Infrastructure\Dbal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Kadupul\Alerting\Application\Port\Clock;
use Kadupul\Alerting\Application\Port\RuleAccess;
use Kadupul\Alerting\Application\Port\RuleStore;
use Kadupul\Alerting\Application\Rule\RuleDefinition;
use Kadupul\Alerting\Application\Rule\RuleSubmissionRefused;
use Kadupul\Alerting\Domain\Incident\IncidentStatus;
use Kadupul\Alerting\Domain\Rule\BaselineRule;
use Kadupul\Alerting\Domain\Rule\AlertRule;
use Kadupul\Alerting\Domain\Rule\ThresholdRule;
use Kadupul\IdentityAccess\Contract\AuditTrail;
use Kadupul\Platform\Contract\LegacyConfiguration;
use Kadupul\Platform\Infrastructure\Persistence\AuditedPrimaryWrite;

/**
 * Rule writes on the evaluator's connection (config/services.yaml), so the
 * row lock below is the one EvaluateRules waits on. AuditedPrimaryWrite
 * refuses a remote collector and non-transactional tables, runs at
 * REPEATABLE READ, rechecks the actor inside the transaction and audits
 * every attempt. Lock order is the evaluator's: the alert_rule row
 * (SELECT ... FOR UPDATE), then alert_rule_state, then alert_incident.
 */
final readonly class DbalRuleStore implements RuleStore
{
    // Every table a write changes or locks, with the permission tables the actor recheck reads.
    private const array WRITE_TABLES = ['alert_rule', 'alert_rule_state', 'alert_incident', 'data_local', 'data_template_rrd', 'host',
        'user_auth', 'user_auth_realm', 'user_auth_group', 'user_auth_group_realm', 'user_auth_group_members', 'user_auth_perms', 'user_auth_group_perms', 'settings'];

    private AuditedPrimaryWrite $writes;

    public function __construct(
        private Connection $database,
        private SeriesVisibility $visibility,
        private Clock $clock,
        RuleAccess $access,
        AuditTrail $audit,
        LegacyConfiguration $configuration,
    ) {
        $this->writes = new AuditedPrimaryWrite($database, $audit, $configuration, $access->assertCurrent(...), self::WRITE_TABLES, 'Alert rule', 'Alert rules', 'alerting.rule', 'alert_rule');
    }

    public function create(int $actorId, RuleDefinition $rule): int
    {
        return $this->writes->run($actorId, 'create', 'new', function () use ($actorId, $rule): int {
            $this->assertSeries($actorId, $rule);
            $this->database->insert('alert_rule', self::columns($rule) + ['owner_id' => $actorId]);

            return StoredValue::int($this->database->lastInsertId(), 'alert_rule', 'id');
        });
    }

    public function update(int $actorId, int $ruleId, string $revision, RuleDefinition $rule): void
    {
        $this->writes->run($actorId, 'update', (string) $ruleId, function () use ($actorId, $ruleId, $revision, $rule): void {
            $stored = $this->lockVisible($actorId, $ruleId, $revision);
            $this->assertSeries($actorId, $rule);
            if (StoredValue::int($stored['local_data_id'], 'alert_rule ' . $ruleId, 'local_data_id') !== $rule->series->localDataId || $stored['rrd_name'] !== $rule->series->rrdName || $stored['kind'] !== $rule->kind()) {
                // Another series or kind is another rule for the evaluator:
                // the old state and incident describe what it no longer watches.
                $this->endRule($ruleId);
            } else {
                $previous = DbalRuleRepository::fromRow($stored);
                if (!$previous instanceof AlertRule || !$previous->equals(new AlertRule($ruleId, $rule->series, $rule->condition))) {
                    // Counts describe the old condition. Keep the incident and
                    // watermark, so an edit cannot count the same sample twice.
                    $this->database->update('alert_rule_state', ['consecutive_breaches' => 0, 'consecutive_oks' => 0], ['rule_id' => $ruleId]);
                }
            }
            $this->database->update('alert_rule', self::columns($rule), ['id' => $ruleId]);
        });
    }

    public function delete(int $actorId, int $ruleId, string $revision): void
    {
        $this->writes->run($actorId, 'delete', (string) $ruleId, function () use ($actorId, $ruleId, $revision): void {
            $this->lockVisible($actorId, $ruleId, $revision);
            $this->endRule($ruleId);
            $this->database->delete('alert_rule', ['id' => $ruleId]);
        });
    }

    /**
     * Locks the rule row, then checks that the actor may still see it and
     * that it is the revision the form showed.
     *
     * @return array<string, mixed> the locked row
     */
    private function lockVisible(int $actorId, int $ruleId, string $revision): array
    {
        $row = $this->database->fetchAssociative('SELECT ' . implode(', ', RuleRevision::COLUMNS) . ' FROM alert_rule WHERE id = ?' . RowLock::forUpdate($this->database), [$ruleId]);
        if ($row === false) {
            throw new RuleSubmissionRefused(RuleStore::NOT_FOUND);
        }
        $visible = $this->database->fetchOne('SELECT r.id FROM alert_rule r ' . DbalRuleRepository::SERIES_JOINS . ' WHERE r.id = ? AND ' . $this->visibility->ruleVisible($actorId), [$ruleId]);
        if ($visible === false) {
            // The same answer as a missing rule, so ids of hidden rules are not disclosed.
            throw new RuleSubmissionRefused(RuleStore::NOT_FOUND);
        }
        if (!hash_equals(RuleRevision::of($row), $revision)) {
            throw new RuleSubmissionRefused(RuleStore::STALE);
        }

        return $row;
    }

    /**
     * The series exists as the evaluator requires it (SERIES_EXISTS) and is
     * one the actor can view. The data source row is share-locked, so it
     * cannot be deleted before this rule commits; a later deletion is the
     * evaluator's orphan sweep to handle.
     */
    private function assertSeries(int $actorId, RuleDefinition $rule): void
    {
        $lock = $this->database->getDatabasePlatform() instanceof AbstractMySQLPlatform ? ' LOCK IN SHARE MODE' : '';
        $found = $this->database->fetchOne(
            "SELECT dl.id FROM data_local dl LEFT JOIN host h ON h.id = dl.host_id
            WHERE dl.id = ? AND (dl.host_id = 0 OR (h.id IS NOT NULL AND h.deleted <> 'on'))
            AND EXISTS (SELECT 1 FROM data_template_rrd dtr WHERE dtr.local_data_id = dl.id AND dtr.data_source_name = " . SeriesVisibility::bytes($this->database) . ')
            AND ' . $this->visibility->visible($actorId) . $lock,
            [$rule->series->localDataId, $rule->series->rrdName],
        );
        if ($found === false) {
            throw new RuleSubmissionRefused(RuleStore::SERIES_UNAVAILABLE);
        }
    }

    /** Decision 6's orphan handling, in lock order: the state, then the active incident. */
    private function endRule(int $ruleId): void
    {
        $this->database->executeStatement('DELETE FROM alert_rule_state WHERE rule_id = ?', [$ruleId]);
        $this->database->executeStatement(
            'UPDATE alert_incident SET status = ?, active_rule_id = NULL, ended_at = ? WHERE active_rule_id = ?',
            [IncidentStatus::Orphaned->value, $this->clock->now()->getTimestamp(), $ruleId],
        );
    }

    /**
     * Every definition column, the other kind's NULL. A baseline rule always
     * writes baseline_min_samples and baseline_direction: they have no
     * column default, and the domain supplies 4 and both.
     *
     * @return array<string, mixed>
     */
    private static function columns(RuleDefinition $rule): array
    {
        $condition = $rule->condition;
        // A float bound as a string would be cut to the 14 digits of PHP's precision setting.
        $number = static fn(float $value): string => sprintf('%.17G', $value);

        return [
            'local_data_id' => $rule->series->localDataId,
            'rrd_name' => $rule->series->rrdName,
            'kind' => $rule->kind(),
            'comparator' => $condition instanceof ThresholdRule ? $condition->comparator->value : null,
            'trigger_threshold' => $condition instanceof ThresholdRule ? $number($condition->trigger) : null,
            'clear_threshold' => $condition instanceof ThresholdRule ? $number($condition->clear) : null,
            'baseline_direction' => $condition instanceof BaselineRule ? $condition->direction->value : null,
            'baseline_deviations' => $condition instanceof BaselineRule ? $number($condition->deviations) : null,
            'baseline_clear_deviations' => $condition instanceof BaselineRule ? $number($condition->clearDeviations) : null,
            'baseline_floor' => $condition instanceof BaselineRule ? $number($condition->deviationFloor) : null,
            'baseline_min_samples' => $condition instanceof BaselineRule ? $condition->minSamples : null,
            'for_cycles' => $condition->forCycles,
        ];
    }
}
