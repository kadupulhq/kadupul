<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Monitoring\Domain;

use Kadupul\Telemetry\Contract\MetricId;
use Kadupul\Telemetry\Contract\ResourceId;

/** A metric already collected for a resource, by legacy polling or a pack plan. */
final readonly class MonitoredSeries
{
    /**
     * @param string $origin what collects it, such as legacy:data-query/SNMP - Interface Statistics or pack:openconfig-interfaces
     */
    public function __construct(
        public ResourceId $resource,
        public MetricId $metric,
        public string $origin,
        public string $protocol,
    ) {}
}
