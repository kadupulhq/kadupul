<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Collection\Contract;

/**
 * Cross-context contract for invalidating cached polling entries when devices
 * or data sources change.
 */
interface PollerCacheInvalidator
{
    public function invalidateDevice(int $deviceId, ?int $collectorId = null): void;

    public function invalidateDataSource(int $localDataId, ?int $collectorId = null): void;

    public function invalidateCollector(int $collectorId): void;
}
