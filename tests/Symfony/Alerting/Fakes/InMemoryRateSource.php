<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Tests\Alerting\Fakes;

use Kadupul\Alerting\Application\Port\RateSource;
use Kadupul\Alerting\Domain\Evaluation\Observation;
use Kadupul\Alerting\Domain\Rule\SeriesKey;
use Symfony\Component\Clock\MockClock;

final class InMemoryRateSource implements RateSource
{
    /** @var array<string, Observation> */
    private array $observations = [];
    /** @var list<list<string>> */
    public array $requests = [];
    /** Thrown by currentRates(), to model a failed read. */
    public ?\Throwable $failure = null;

    /**
     * @param float $secondsPerRead how far each read advances $clock, to model
     *                              a slow source against the time budget
     */
    public function __construct(
        public bool $available = true,
        private ?MockClock $clock = null,
        private float $secondsPerRead = 0.0,
    ) {}

    public function put(SeriesKey $key, Observation $observation): void
    {
        $this->observations[$key->id()] = $observation;
    }

    public function available(): bool
    {
        return $this->available;
    }

    public function currentRates(array $keys): array
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }
        $this->requests[] = array_map(static fn(SeriesKey $key): string => $key->id(), $keys);
        if ($this->clock !== null && $this->secondsPerRead > 0.0) {
            $this->clock->sleep($this->secondsPerRead);
        }
        $found = [];
        foreach ($keys as $key) {
            if (isset($this->observations[$key->id()])) {
                $found[$key->id()] = $this->observations[$key->id()];
            }
        }

        return $found;
    }
}
