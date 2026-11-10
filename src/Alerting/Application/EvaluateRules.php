<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Alerting\Application;

use Kadupul\Alerting\Application\Port\AlertingLog;
use Kadupul\Alerting\Application\Port\BaselineSource;
use Kadupul\Alerting\Application\Port\Clock;
use Kadupul\Alerting\Application\Port\EvaluationCursor;
use Kadupul\Alerting\Application\Port\IncidentRepository;
use Kadupul\Alerting\Application\Port\NotificationQueue;
use Kadupul\Alerting\Application\Port\RateSource;
use Kadupul\Alerting\Application\Port\RuleRepository;
use Kadupul\Alerting\Application\Port\RuleStateStore;
use Kadupul\Alerting\Application\Port\SeriesObservation;
use Kadupul\Alerting\Application\Port\TransactionRunner;
use Kadupul\Alerting\Application\Port\UnusableRuleRecord;
use Kadupul\Alerting\Domain\Evaluation\Observation;
use Kadupul\Alerting\Domain\Evaluation\RuleState;
use Kadupul\Alerting\Domain\Evaluation\Transition;
use Kadupul\Alerting\Domain\Rule\AlertRule;
use Kadupul\Alerting\Domain\Rule\BaselineRule;
use Kadupul\Alerting\Domain\Rule\HourBucket;

/**
 * One evaluation run (ADR 0011 decisions 4, 5, 8 and 9). Rules are read in
 * batches by ascending id from the stored cursor; the time budget is checked
 * between batches, so the first batch always runs. A run that reaches the last
 * rule resets the cursor and stops, so no rule is evaluated twice in a run and
 * a stopped run resumes after the last rule it finished.
 *
 * Each rule's writes run in one transaction: the rule row is locked, its
 * state is saved, its incident change follows and an opened or recovered
 * incident queues its notification (decision 7), so a failed incident or
 * notification write also undoes the state. A rule deleted after its batch
 * was read writes nothing. A rule whose state did not change is not
 * written. Mail is sent by DeliverNotifications, never here. Before the
 * batches, up to one batch of rules whose series or rule row is gone end
 * their active incident as orphaned and lose their state (ADR 0011 decision
 * 6); that is never a recovery, and the rest wait for later runs.
 *
 * Failures are split by what they concern (decision 8). An UnusableRuleRecord
 * is about one rule's stored rows: that rule is logged, counted as failed and
 * skipped, and the run and the cursor move on, so it cannot hold back the
 * rules after it, or before it once the cursor wraps. Every other exception,
 * such as a lost connection or a failed rate read, is not about one rule and
 * propagates without moving the cursor.
 *
 * Baseline rules (decision 3) need their hour-of-week history. Each batch
 * hands the fresh observations of its baseline rules that are still to be
 * counted to the BaselineSource once, to record and then to read back, so
 * the lookup is per batch, never per rule, and its time counts against the
 * budget. Too little history for the hour is unknown, like a missing rate.
 * Old history is pruned once per run, after the orphan sweep, bounded by
 * BaselineSource::PRUNE_LIMIT; like that sweep it is a count, not a charge
 * to the budget.
 */
final readonly class EvaluateRules
{
    public const string RATE_SOURCE_UNAVAILABLE = 'rate_source_unavailable';

    public function __construct(
        private RateSource $rates,
        private BaselineSource $baselines,
        private RuleRepository $rules,
        private RuleStateStore $states,
        private IncidentRepository $incidents,
        private NotificationQueue $notifications,
        private EvaluationCursor $cursor,
        private Clock $clock,
        private TransactionRunner $transaction,
        private AlertingLog $logger,
    ) {}

    public function __invoke(int $batchSize, float $budgetSeconds): EvaluationReport
    {
        if ($batchSize < 1) {
            throw new \InvalidArgumentException('The batch size must be at least 1.');
        }
        if (!is_finite($budgetSeconds) || $budgetSeconds < 0.0) {
            throw new \InvalidArgumentException('The time budget must be a finite number of seconds, zero or more.');
        }
        if (!$this->rates->available()) {
            return EvaluationReport::inactive(self::RATE_SOURCE_UNAVAILABLE);
        }

        $started = self::seconds($this->clock->now());
        $orphaned = $this->endOrphans($batchSize);
        // Once per run, with or without baseline rules, so history ages out after the last one is gone.
        $this->baselines->prune($this->clock->now()->getTimestamp());
        $evaluated = 0;
        $skipped = 0;
        $failed = 0;
        $after = $this->cursor->position();
        do {
            $batch = $this->rules->batchAfter($after, $batchSize);
            if ($batch === []) {
                $this->cursor->moveTo(0);
                break;
            }
            [$batchEvaluated, $batchSkipped, $batchFailed] = $this->evaluateBatch($batch);
            $evaluated += $batchEvaluated;
            $skipped += $batchSkipped;
            $failed += $batchFailed;
            // An unusable rule counts as passed, so the cursor never stops at it.
            $after = max(array_map(static fn(AlertRule|UnusableRuleRecord $rule): int => $rule instanceof AlertRule ? $rule->id : $rule->ruleId, $batch));
            if (count($batch) < $batchSize) {
                $this->cursor->moveTo(0);
                break;
            }
            $this->cursor->moveTo($after);
        } while (self::seconds($this->clock->now()) - $started < $budgetSeconds);

        return new EvaluationReport($evaluated, $skipped, null, $orphaned, $failed);
    }

    /**
     * Ends at most $limit orphans, the batch size, in one transaction. Neither
     * write reads the rule's state, so an unreadable state row is deleted
     * like any other. Handled orphans no longer qualify, so the rest are
     * found by later runs.
     *
     * @return int rules whose incident or state was ended
     */
    private function endOrphans(int $limit): int
    {
        $ids = $this->rules->orphanedRuleIds($limit);
        if ($ids === []) {
            return 0;
        }
        $at = $this->clock->now()->getTimestamp();
        $this->transaction->run(function () use ($ids, $at): void {
            $this->states->delete($ids);
            // No notification: an orphaned incident did not recover.
            $this->incidents->orphan($ids, $at);
        });

        return count($ids);
    }

    /**
     * @param list<AlertRule|UnusableRuleRecord> $batch
     *
     * @return array{int, int, int} evaluated, skipped and failed counts
     */
    private function evaluateBatch(array $batch): array
    {
        $failed = 0;
        $rules = [];
        foreach ($batch as $rule) {
            if ($rule instanceof UnusableRuleRecord) {
                $this->quarantine($rule);
                ++$failed;
            } else {
                $rules[] = $rule;
            }
        }
        if ($rules === []) {
            return [0, 0, $failed];
        }
        $keys = [];
        foreach ($rules as $rule) {
            $keys[$rule->series->id()] = $rule->series;
        }
        // A failed rate read is not about one rule: it propagates.
        $observations = $this->rates->currentRates(array_values($keys));
        $states = $this->states->load(array_map(static fn(AlertRule $rule): int => $rule->id, $rules));
        $now = $this->clock->now()->getTimestamp();

        $history = $this->baselineHistory($rules, $observations, $states, $now);

        $evaluated = 0;
        $skipped = 0;
        foreach ($rules as $rule) {
            $state = $states[$rule->id] ?? new RuleState();
            if ($state instanceof UnusableRuleRecord) {
                $this->quarantine($state);
                ++$failed;
                continue;
            }
            $before = clone $state;
            $observation = $observations[$rule->series->id()] ?? null;
            $trigger = $clear = 0.0;

            if ($observation === null || !$observation->isFreshAt($now)) {
                // No fresh rate is unknown: it never counts as ok or as breach.
                // It changes no incident, so it queues no notification.
                $transition = $state->markUnknown();
                $observation = null;
            } elseif (!$state->isNewer($observation->observedAt)) {
                ++$skipped;
                continue;
            } elseif ($rule->condition instanceof BaselineRule) {
                $band = $rule->condition->band($history[$rule->series->id()] ?? []);
                if ($band === null) {
                    // Too few weeks for this hour is unknown too, and counts no observation.
                    $transition = $state->markUnknown();
                    $observation = null;
                } else {
                    $verdict = $rule->condition->evaluate($observation->rate, $band, $state->isOpen());
                    $transition = $state->advanceAt($verdict, $rule->condition, $observation->observedAt);
                    $trigger = $rule->condition->triggerEdge($observation->rate, $band);
                    $clear = $rule->condition->clearEdge($observation->rate, $band);
                }
            } else {
                $verdict = $rule->condition->evaluate($observation->rate, $state->isOpen());
                $transition = $state->advanceAt($verdict, $rule->condition, $observation->observedAt);
                $trigger = $rule->condition->trigger;
                $clear = $rule->condition->clear;
            }

            if (!$state->equals($before)) {
                $gone = false;
                try {
                    $this->transaction->run(function () use ($rule, $state, $transition, $observation, $trigger, $clear, $now, &$gone): void {
                        // Lock order: rule, state, incident, notification.
                        if (!$this->rules->lock($rule->id)) {
                            $gone = true;

                            return;
                        }
                        $this->states->save($rule->id, $state);
                        if ($observation === null) {
                            return;
                        }
                        if ($transition === Transition::Opened) {
                            $incident = $this->incidents->open($rule->id, $observation->observedAt);
                            $this->notifications->enqueue($incident, $transition, $observation->rate, $trigger, $now);
                        } elseif ($transition === Transition::Recovered) {
                            $incident = $this->incidents->close($rule->id, $observation->observedAt);
                            $this->notifications->enqueue($incident, $transition, $observation->rate, $clear, $now);
                        }
                    });
                } catch (UnusableRuleRecord $mismatch) {
                    // Rolled back: the incident rows disagree with this rule's state.
                    $this->quarantine($mismatch);
                    ++$failed;
                    continue;
                }
                if ($gone) {
                    // Deleted after this batch was read; the next run's orphan sweep ends whatever it left.
                    continue;
                }
            }
            ++$evaluated;
        }

        return [$evaluated, $skipped, $failed];
    }

    /**
     * Records and reads the history of the batch's baseline series that have
     * a fresh observation some usable rule has not yet counted; a series
     * shared by several rules is handed over once. No such series, no call.
     *
     * @param list<AlertRule>                          $rules
     * @param array<string, Observation>               $observations
     * @param array<int, RuleState|UnusableRuleRecord> $states
     *
     * @return array<string, list<HourBucket>>
     */
    private function baselineHistory(array $rules, array $observations, array $states, int $now): array
    {
        $wanted = [];
        foreach ($rules as $rule) {
            $observation = $observations[$rule->series->id()] ?? null;
            $state = $states[$rule->id] ?? new RuleState();
            if (!$rule->condition instanceof BaselineRule || $observation === null || $state instanceof UnusableRuleRecord
                || !$observation->isFreshAt($now) || !$state->isNewer($observation->observedAt)) {
                continue;
            }
            $wanted[$rule->series->id()] = new SeriesObservation($rule->series, $observation);
        }
        if ($wanted === []) {
            return [];
        }
        $wanted = array_values($wanted);
        // A failed write or read is not about one rule: it propagates.
        $this->baselines->record($wanted);

        return $this->baselines->history($wanted);
    }

    private function quarantine(UnusableRuleRecord $record): void
    {
        $this->logger->warning('Alert rule {rule} was skipped: {reason}', ['rule' => $record->ruleId, 'reason' => $record->reason]);
    }

    private static function seconds(\DateTimeImmutable $instant): float
    {
        return (float) $instant->format('U.u');
    }
}
