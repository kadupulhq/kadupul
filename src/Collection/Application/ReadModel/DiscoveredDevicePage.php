<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Collection\Application\ReadModel;

final readonly class DiscoveredDevicePage
{
    /** @param list<DiscoveredDeviceSummary> $devices @param list<DiscoveryNetworkChoice> $networks @param list<string> $operatingSystems */
    public function __construct(
        public array $devices,
        public array $networks,
        public array $operatingSystems,
        public bool $hasNext
    ) {}
}
