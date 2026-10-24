<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Monitoring\Domain;

use Kadupul\Telemetry\Contract\MetricId;
use Kadupul\Telemetry\Contract\MetricType;

final readonly class PlannedMetric
{
    /**
     * @param string $source the path, OID or resource#/property in the target's subscription that yields the metric
     * @param list<string> $labels
     * @param array<string, int>|null $valueMap
     * @param PlanBinding|null $binding set only when the source yields exactly one resource with an approved binding
     */
    public function __construct(
        public MetricId $id,
        public MetricType $type,
        public string $unit,
        public string $source,
        public ?int $counterBits,
        public array $labels,
        public int|float|null $scale,
        public ?array $valueMap,
        public ?PlanBinding $binding = null,
    ) {}

    /** @return array<string, mixed> */
    public function canonical(): array
    {
        $canonical = [$this->id->toString(), $this->type->value, $this->unit, $this->source, $this->counterBits, $this->labels, $this->scale, $this->valueMap];
        // Appended only when present, so the dedup key of every unbound plan is unchanged.
        if ($this->binding !== null) {
            $canonical[] = $this->binding->canonical();
        }

        return $canonical;
    }
}
