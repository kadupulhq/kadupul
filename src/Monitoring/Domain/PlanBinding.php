<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Monitoring\Domain;

use Kadupul\Telemetry\Contract\ResourceId;

/**
 * The logical binding a plan metric refers to. A collector sees only the
 * opaque id and the resource; where and how the series is stored stays on
 * the control plane.
 */
final readonly class PlanBinding
{
    public function __construct(public string $bindingId, public ResourceId $resource)
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D', $bindingId) !== 1) {
            throw new InvalidCollectionPlan(sprintf('Binding id "%s" is not an opaque binding id.', $bindingId));
        }
    }

    /** @return list<string> */
    public function canonical(): array
    {
        return [$this->bindingId, $this->resource->kind, $this->resource->identity];
    }
}
