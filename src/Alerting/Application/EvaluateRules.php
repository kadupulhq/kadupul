<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Alerting\Application;

use Kadupul\Alerting\Application\Port\Clock;
use Kadupul\Alerting\Application\Port\EvaluationCursor;
use Kadupul\Alerting\Application\Port\IncidentRepository;
use Kadupul\Alerting\Application\Port\RateSource;
use Kadupul\Alerting\Application\Port\RuleRepository;
use Kadupul\Alerting\Application\Port\RuleStateStore;
use Kadupul\Alerting\Application\Port\TransactionRunner;
use Kadupul\Alerting\Domain\Evaluation\RuleState;
use Kadupul\Alerting\Domain\Evaluation\Transition;
use Kadupul\Alerting\Domain\Rule\AlertRule;

/**
 * One evaluation run (ADR 0011 decisions 4, 5, 8 and 9). Rules are read in
 * batches by ascending id from the stored cursor; the time budget is checked
 * between batches, so the first batch always runs. A run that reaches the last
 * rule resets the cursor and stops, so no rule is evaluated twice in a run and
 * a stopped run resumes after the last rule it finished.
 *
 * Each rule's writes run in one transaction: its state is saved first and
 * its incident change follows, so a failed incident write also undoes the
 * state. A rule whose state did not change is not written. Before the
 * batches, rules whose series or rule row is gone end their active incident
 * as orphaned and lose their state (ADR 0011 decision 6); that is never a
 * recovery.
 */
final readonly class EvaluateRules
{
    public const string RATE_SOURCE_UNAVAILABLE = 'rate_source_unavailable';

    public function __construct(
        private RateSource $rates,
        private RuleRepository $rules,
        private RuleStateStore $states,
        private IncidentRepository $incidents,
        private EvaluationCursor $cursor,
        private Clock $clock,
        private TransactionRunner $transaction,
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
        $orphaned = $this->endOrphans();
        $evaluated = 0;
        $skipped = 0;
        $after = $this->cursor->position();
        do {
            $batch = $this->rules->batchAfter($after, $batchSize);
            if ($batch === []) {
                $this->cursor->moveTo(0);
                break;
            }
            [$batchEvaluated, $batchSkipped] = $this->evaluateBatch($batch);
            $evaluated += $batchEvaluated;
            $skipped += $batchSkipped;
            $after = max(array_map(static fn(AlertRule $rule): int => $rule->id, $batch));
            if (count($batch) < $batchSize) {
                $this->cursor->moveTo(0);
                break;
            }
            $this->cursor->moveTo($after);
        } while (self::seconds($this->clock->now()) - $started < $budgetSeconds);

        return new EvaluationReport($evaluated, $skipped, null, $orphaned);
    }

    /** @return int rules whose incident or state was ended */
    private function endOrphans(): int
    {
        $ids = $this->rules->orphanedRuleIds();
        if ($ids === []) {
            return 0;
        }
        $stored = $this->states->load($ids);
        $at = $this->clock->now()->getTimestamp();
        foreach ($ids as $id) {
            $this->transaction->run(function () use ($id, $stored, $at): void {
                if (isset($stored[$id])) {
                    $this->states->delete($id);
                }
                // No notification: an orphaned incident did not recover.
                $this->incidents->orphan($id, $at);
            });
        }

        return count($ids);
    }

    /**
     * @param list<AlertRule> $batch
     *
     * @return array{int, int} evaluated and skipped counts
     */
    private function evaluateBatch(array $batch): array
    {
        $keys = [];
        foreach ($batch as $rule) {
            $keys[$rule->series->id()] = $rule->series;
        }
        $observations = $this->rates->currentRates(array_values($keys));
        $states = $this->states->load(array_map(static fn(AlertRule $rule): int => $rule->id, $batch));
        $now = $this->clock->now()->getTimestamp();

        $evaluated = 0;
        $skipped = 0;
        foreach ($batch as $rule) {
            $state = $states[$rule->id] ?? new RuleState();
            $before = clone $state;
            $observation = $observations[$rule->series->id()] ?? null;

            if ($observation === null || !$observation->isFreshAt($now)) {
                // No fresh rate is unknown: it never counts as ok or as breach.
                // Notifying an incident that became unknown is Task 4's queue.
                $transition = $state->markUnknown();
                $observedAt = null;
            } elseif (!$state->isNewer($observation->observedAt)) {
                ++$skipped;
                continue;
            } else {
                $observedAt = $observation->observedAt;
                $verdict = $rule->condition->evaluate($observation->rate, $state->isOpen());
                $transition = $state->advanceAt($verdict, $rule->condition, $observedAt);
            }

            if (!$state->equals($before)) {
                $this->transaction->run(function () use ($rule, $state, $transition, $observedAt): void {
                    $this->states->save($rule->id, $state);
                    if ($observedAt !== null && $transition === Transition::Opened) {
                        $this->incidents->open($rule->id, $observedAt);
                    } elseif ($observedAt !== null && $transition === Transition::Recovered) {
                        $this->incidents->close($rule->id, $observedAt);
                    }
                });
            }
            ++$evaluated;
        }

        return [$evaluated, $skipped];
    }

    private static function seconds(\DateTimeImmutable $instant): float
    {
        return (float) $instant->format('U.u');
    }
}
