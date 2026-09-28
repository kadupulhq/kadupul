<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Collection\Application\ReadModel;

final readonly class DiscoveredDeviceSummary
{
    public function __construct(
        public int $id,
        public string $hostname,
        public string $ip,
        public string $systemName,
        public string $location,
        public string $contact,
        public string $description,
        public string $operatingSystem,
        public int $uptimeSeconds,
        public bool $snmpUp,
        public bool $up,
        public string $lastCheck
    ) {}
}
