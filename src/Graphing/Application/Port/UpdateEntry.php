<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Graphing\Application\Port;

use Kadupul\Monitoring\Contract\RrdDataSourceType;
use Kadupul\Telemetry\Contract\Sample;

/**
 * One sample bound for one data source in one RRD file. The name is kept
 * as the binding gave it; the formatter validates it, so a bad name is
 * reported against this sample rather than failing the whole file.
 */
final readonly class UpdateEntry
{
    /** @param int $position index of the sample in the batch handed to the sink */
    public function __construct(
        public int $position,
        public Sample $sample,
        public string $dataSourceName,
        public RrdDataSourceType $type,
    ) {}
}
