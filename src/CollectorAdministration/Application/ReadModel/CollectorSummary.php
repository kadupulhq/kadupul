<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\CollectorAdministration\Application\ReadModel;

final readonly class CollectorSummary
{
    public function __construct(
        public int $id,
        public string $name,
        public string $hostname,
        public string $status,
        public int $processes,
        public int $threads,
        public float $pollingTime,
        public float $averageTime,
        public float $maximumTime,
        public int $hosts,
        public int $snmp,
        public int $scripts,
        public int $servers,
        public string $lastUpdate,
        public string $lastStatus,
        public ?string $lastSync
    ) {}
}
