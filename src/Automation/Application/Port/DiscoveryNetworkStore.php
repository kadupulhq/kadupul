<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Automation\Application\Port;

use Kadupul\Automation\Domain\DiscoveryNetwork;
use Kadupul\Automation\Domain\DiscoveryNetworkChange;

interface DiscoveryNetworkStore
{
    /**
     * The legacy defaults for a new network, from the global settings.
     *
     * @return array<string, bool|int|string|list<int>>
     */
    public function defaults(): array;

    /**
     * The collectors, sites and SNMP Option Sets a network can use, by ID.
     *
     * @return array{pollers: array<int, string>, sites: array<int, string>, snmp: array<int, string>}
     */
    public function choices(): array;

    public function find(int $id): ?DiscoveryNetwork;

    /**
     * Creates the network when $id is null; otherwise replaces the General
     * settings when the stored row still matches $revision.
     *
     * @throws \Kadupul\Automation\Domain\DiscoveryNetworkRejected when a referenced collector, site or SNMP Option Set no longer exists
     * @throws \InvalidArgumentException when the network is missing or changed since $revision
     */
    public function save(int $actorId, ?int $id, DiscoveryNetworkChange $change, ?string $revision): int;
}
