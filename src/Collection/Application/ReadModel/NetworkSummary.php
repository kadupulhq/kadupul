<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Collection\Application\ReadModel;

final readonly class NetworkSummary
{
    public function __construct(
        public int $id,
        public string $name,
        public string $collector,
        public string $schedule,
        public int $totalIps,
        public string $status,
        public string $progress,
        public int $upHosts,
        public int $snmpHosts,
        public int $threads,
        public float $lastRuntime,
        public ?string $nextStart,
        public ?string $lastStarted
    ) {}
}
