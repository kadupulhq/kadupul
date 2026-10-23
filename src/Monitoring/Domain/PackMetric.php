<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Monitoring\Domain;

use Kadupul\Telemetry\Contract\MetricId;
use Kadupul\Telemetry\Contract\MetricType;

final readonly class PackMetric
{
    /**
     * @param string $unit free text in a pack (for example state); Telemetry's Unit covers only sample units
     * @param list<string> $labels
     * @param array<string, PackMapping> $mappings keyed by protocol
     */
    public function __construct(
        public MetricId $id,
        public MetricType $type,
        public string $unit,
        public ?int $counterBits,
        public array $labels,
        public array $mappings,
    ) {}

    public function mapping(string $protocol): ?PackMapping
    {
        return $this->mappings[$protocol] ?? null;
    }

    /** The fields whose change breaks stored history, as compared by an upgrade. */
    public function signature(): string
    {
        return $this->type->value . '|' . $this->unit . '|' . ($this->counterBits ?? '-');
    }
}
