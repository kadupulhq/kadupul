<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Telemetry\Application;

use Kadupul\Telemetry\Contract\CollectionError;
use Kadupul\Telemetry\Contract\CollectionStatus;

final readonly class NormalizationReport
{
    /**
     * @param list<CounterInterval> $intervals
     * @param list<CollectionError> $errors
     */
    public function __construct(
        public array $intervals,
        public array $errors,
        public int $duplicatesDropped,
    ) {}

    public function status(): CollectionStatus
    {
        if ($this->errors === []) {
            return CollectionStatus::Succeeded;
        }

        return $this->intervals === [] ? CollectionStatus::Failed : CollectionStatus::Partial;
    }
}
