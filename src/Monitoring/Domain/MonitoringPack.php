<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Monitoring\Domain;

/**
 * A pack that passed PackValidator. Alerts and the legacy template mapping
 * are validated but not modelled: nothing evaluates alerts from packs yet,
 * and the legacy mapping is information for operators only.
 */
final readonly class MonitoringPack
{
    /**
     * @param list<string> $modelFamilies
     * @param list<PackMetric> $metrics
     * @param list<string> $protocolPreference most preferred first
     * @param list<PackGraph> $graphs
     */
    public function __construct(
        public string $name,
        public SemanticVersion $version,
        public CapabilityRequirement $requirement,
        public array $modelFamilies,
        public PackDiscovery $discovery,
        public array $metrics,
        public array $protocolPreference,
        public int $minimumInterval,
        public int $defaultInterval,
        public array $graphs,
        public string $kadupulConstraint,
    ) {}

    /** @return list<string> */
    public function metricIds(): array
    {
        return array_map(static fn(PackMetric $m): string => $m->id->toString(), $this->metrics);
    }

    public function metric(string $id): ?PackMetric
    {
        foreach ($this->metrics as $metric) {
            if ($metric->id->toString() === $id) {
                return $metric;
            }
        }

        return null;
    }
}
