<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Alerting\Application\Port;

use Kadupul\Alerting\Domain\Evaluation\Observation;
use Kadupul\Alerting\Domain\Rule\SeriesKey;

/** A fresh observation of one series, as the evaluator hands it to the BaselineSource. */
final readonly class SeriesObservation
{
    public function __construct(
        public SeriesKey $series,
        public Observation $observation,
    ) {}
}
