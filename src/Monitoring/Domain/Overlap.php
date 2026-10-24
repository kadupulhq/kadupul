<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Monitoring\Domain;

use Kadupul\Telemetry\Contract\MetricId;
use Kadupul\Telemetry\Contract\ResourceId;

/**
 * A pack offers a series something else already collects. The planner
 * reports it and never resolves it by changing the existing monitoring.
 */
final readonly class Overlap
{
    /**
     * @param bool $planned true when policy prefers the new source and the plan collects it too
     */
    public function __construct(
        public ResourceId $resource,
        public MetricId $metric,
        public string $existingOrigin,
        public string $existingProtocol,
        public string $offeredProtocol,
        public string $packName,
        public bool $planned,
    ) {}
}
