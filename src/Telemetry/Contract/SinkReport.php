<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Telemetry\Contract;

/** One outcome per input sample, in input order, so a caller can pair results with the samples it sent. */
final readonly class SinkReport implements \Countable
{
    /** @param list<SampleOutcome> $outcomes */
    private function __construct(private array $outcomes) {}

    /** @param list<SampleOutcome> $outcomes */
    public static function forOutcomes(array $outcomes): self
    {
        if (!array_is_list($outcomes)) {
            throw new InvalidTelemetryValue('Outcomes must be a list in input order.');
        }
        foreach ($outcomes as $outcome) {
            if (!$outcome instanceof SampleOutcome) {
                throw new InvalidTelemetryValue('A sink report holds only sample outcomes.');
            }
        }

        return new self($outcomes);
    }

    /** @return list<SampleOutcome> */
    public function outcomes(): array
    {
        return $this->outcomes;
    }

    public function count(): int
    {
        return count($this->outcomes);
    }

    public function allAccepted(): bool
    {
        return $this->rejected() === [];
    }

    /** @return array<int, SampleOutcome> rejected outcomes keyed by the position of their input sample */
    public function rejected(): array
    {
        return array_filter($this->outcomes, static fn(SampleOutcome $o): bool => !$o->isAccepted());
    }
}
