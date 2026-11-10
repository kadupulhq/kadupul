<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Tests\Alerting\Fakes;

use Kadupul\Alerting\Application\Port\BaselineSource;
use Kadupul\Alerting\Application\Port\SeriesObservation;
use Kadupul\Alerting\Domain\Rule\HourBucket;
use Kadupul\Alerting\Domain\Rule\SeriesKey;
use Symfony\Component\Clock\MockClock;

/** History set per series by the test; records what the evaluator hands it. */
final class InMemoryBaselineSource implements BaselineSource
{
    /** @var array<string, list<HourBucket>> */
    private array $history = [];
    /** @var list<int> the time passed to each prune() call */
    public array $prunes = [];
    /** @var list<int> how many history() calls preceded each prune() call */
    public array $readsBeforePrune = [];
    /** Thrown by the next prune() calls while set, to model a failed prune. */
    public ?\Throwable $pruneFailure = null;
    /** @var list<list<string>> series ids per record() call */
    public array $recorded = [];
    /** @var list<list<string>> series ids per history() call */
    public array $requests = [];
    /** Thrown by history(), to model a failed read. */
    public ?\Throwable $failure = null;

    /** @param float $secondsPerRead how far each history() call advances $clock */
    public function __construct(
        private ?MockClock $clock = null,
        private float $secondsPerRead = 0.0,
    ) {}

    /**
     * One bucket per week, each holding a single observation of that value.
     *
     * @param list<float> $weeklyValues
     */
    public function put(SeriesKey $series, array $weeklyValues): void
    {
        $this->history[$series->id()] = array_map(static fn(float $v): HourBucket => new HourBucket(1, $v, $v * $v), $weeklyValues);
    }

    public function prune(int $now): int
    {
        $this->prunes[] = $now;
        $this->readsBeforePrune[] = count($this->requests);
        if ($this->pruneFailure !== null) {
            throw $this->pruneFailure;
        }

        return 0;
    }

    public function record(array $observations): void
    {
        $this->recorded[] = array_map(static fn(SeriesObservation $o): string => $o->series->id(), $observations);
    }

    public function history(array $observations): array
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }
        $this->requests[] = array_map(static fn(SeriesObservation $o): string => $o->series->id(), $observations);
        if ($this->clock !== null && $this->secondsPerRead > 0.0) {
            $this->clock->sleep($this->secondsPerRead);
        }
        $found = [];
        foreach ($observations as $observation) {
            if (isset($this->history[$observation->series->id()])) {
                $found[$observation->series->id()] = $this->history[$observation->series->id()];
            }
        }

        return $found;
    }
}
