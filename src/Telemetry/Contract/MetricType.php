<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Telemetry\Contract;

/**
 * Whether a metric is a monotonically increasing counter or an instantaneous gauge.
 */
enum MetricType: string
{
    case Counter = 'counter';
    case Gauge = 'gauge';
}
