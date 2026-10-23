<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Telemetry\Contract;

/** Where samples go to be stored. Implementations report on every sample and never drop one silently. */
interface SampleSink
{
    /**
     * @param list<Sample> $samples
     * @return SinkReport one outcome per sample, in the order given
     */
    public function accept(array $samples): SinkReport;
}
