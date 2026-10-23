<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Tests\Telemetry;

use Kadupul\Telemetry\Contract\Sample;
use Kadupul\Telemetry\Contract\SampleOutcome;
use Kadupul\Telemetry\Contract\SampleSink;
use Kadupul\Telemetry\Contract\SinkReport;

/** Test double that remembers every batch and answers with scripted outcomes. */
final class RecordingSampleSink implements SampleSink
{
    /** @var list<list<Sample>> */
    public array $batches = [];

    /**
     * @param array<int, SampleOutcome> $scripted outcomes by position within a batch; other positions are accepted
     */
    public function __construct(private readonly array $scripted = []) {}

    public function accept(array $samples): SinkReport
    {
        $this->batches[] = $samples;
        $outcomes = [];
        foreach ($samples as $position => $_) {
            $outcomes[] = $this->scripted[$position] ?? SampleOutcome::accepted();
        }

        return SinkReport::forOutcomes($outcomes);
    }

    /** @return list<Sample> */
    public function samples(): array
    {
        return array_merge(...$this->batches);
    }
}
