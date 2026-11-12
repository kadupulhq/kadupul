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
 * The answer to "where would this series be stored, and who writes there".
 * Storage details are present only when the status is resolved. A resolved
 * binding can still be owned by the legacy poller or be in conflict; a
 * writer must check permitsModelDrivenWrites() and refuse otherwise.
 */
final readonly class BindingResolution
{
    /**
     * Builds a resolution; the storage details must be present exactly when the status is resolved.
     *
     * @param string $reason why the status is what it is, for logs and operators
     * @param string|null $bindingId opaque binding id; set only when resolved
     * @param WriterOwnership|null $ownership who writes the field; set only when resolved
     * @param string|null $rrdDataSourceName field name inside the stored series; set only when resolved
     * @throws \InvalidArgumentException when the storage details are present without a resolved status, or missing from a resolved one
     */
    public function __construct(
        public ResolutionStatus $status,
        public int $deviceId,
        public MetricId $metric,
        public ResourceId $resource,
        public string $protocol,
        public string $reason,
        public ?string $bindingId = null,
        public ?string $packName = null,
        public ?string $packVersion = null,
        public ?WriterOwnership $ownership = null,
        public ?string $rrdDataSourceName = null,
        public ?RrdDataSourceType $rrdDataSourceType = null,
        public ?StorageReference $storage = null,
    ) {
        $complete = $bindingId !== null && $ownership !== null && $rrdDataSourceName !== null && $rrdDataSourceType !== null && $storage !== null;
        if (($status === ResolutionStatus::Resolved) !== $complete) {
            throw new \InvalidArgumentException('A resolution carries ownership and storage exactly when it is resolved.');
        }
    }

    /** True when exactly one approved binding was found. */
    public function isResolved(): bool
    {
        return $this->status === ResolutionStatus::Resolved;
    }

    /** True only for a resolved binding whose field is owned by model-driven collection. */
    public function permitsModelDrivenWrites(): bool
    {
        return $this->isResolved() && $this->ownership === WriterOwnership::ModelDriven;
    }
}
