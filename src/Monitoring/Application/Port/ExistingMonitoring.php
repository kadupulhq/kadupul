<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Monitoring\Application\Port;

use Kadupul\Monitoring\Domain\MonitoredSeries;

/**
 * Read-only view of what is already collected for a device, including legacy
 * data queries and graph templates. It deliberately has no write method:
 * planning reports overlaps and never edits existing monitoring.
 *
 * Infrastructure\Legacy\LegacyExistingMonitoring reads the legacy tables.
 */
interface ExistingMonitoring
{
    /** @return list<MonitoredSeries> */
    public function monitoredSeries(int $deviceId): array;
}
