<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Monitoring\Domain;

use Kadupul\Telemetry\Contract\ResourceId;

/** Why a pack was or was not selected for a device, and what it would collect. */
final readonly class PackMatch
{
    /**
     * @param list<string> $reasons
     * @param array<string, string> $transports metric id to the protocol chosen for it
     * @param array<string, string> $excludedMetrics metric id to why it is not planned
     * @param list<ResourceId> $resources
     * @param list<PackGraph> $graphs graphs whose every metric is planned
     */
    public function __construct(
        public string $packName,
        public string $packVersion,
        public bool $matched,
        public array $reasons,
        public array $transports = [],
        public array $excludedMetrics = [],
        public array $resources = [],
        public array $graphs = [],
    ) {}
}
