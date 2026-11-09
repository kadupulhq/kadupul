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
use Kadupul\Alerting\Domain\Evaluation\RuleState;
use Kadupul\Alerting\Domain\Evaluation\Transition;
use Kadupul\Alerting\Domain\Evaluation\Verdict;
use Kadupul\Alerting\Domain\Rule\AlertRule;

/**
 * One evaluation run (ADR 0011 decisions 4, 5, 8 and 9). Rules are read in
 * batches by ascending id from the stored cursor; the time budget is checked
 * between batches, so the first batch always runs. A run that reaches the last
 * rule resets the cursor and stops, so no rule is evaluated twice in a run and
 * a stopped run resumes after the last rule it finished.
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

        return new EvaluationReport($evaluated, $skipped);
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
            $observation = $observations[$rule->series->id()] ?? null;

            if ($observation === null || !$observation->isFreshAt($now)) {
                // No fresh rate is unknown: it never counts as ok or as breach.
                // Notifying an incident that became unknown is Task 4's queue.
                $state->advance(Verdict::Unknown, $rule->condition);
            } elseif (!$state->isNewer($observation->observedAt)) {
                ++$skipped;
                continue;
            } else {
                $verdict = $rule->condition->evaluate($observation->rate, $state->isOpen());
                $transition = $state->advanceAt($verdict, $rule->condition, $observation->observedAt);
                if ($transition === Transition::Opened) {
                    $this->incidents->open($rule->id, $observation->observedAt);
                } elseif ($transition === Transition::Recovered) {
                    $this->incidents->close($rule->id, $observation->observedAt);
                }
            }

            $this->states->save($rule->id, $state);
            ++$evaluated;
        }

        return [$evaluated, $skipped];
    }

    private static function seconds(\DateTimeImmutable $instant): float
    {
        return (float) $instant->format('U.u');
    }
}
