<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Monitoring\Contract;

use Kadupul\Telemetry\Contract\MetricId;
use Kadupul\Telemetry\Contract\ResourceId;

/**
 * Finds the storage binding for one logical series. It never guesses: a
 * missing, duplicated or unapproved binding comes back as that status with
 * a reason, not as the nearest match.
 */
interface BindingCatalog
{
    public function resolve(int $deviceId, MetricId $metric, ResourceId $resource, string $protocol): BindingResolution;
}
