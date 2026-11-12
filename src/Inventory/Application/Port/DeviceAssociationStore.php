<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Application\Port;

use Kadupul\Inventory\Domain\DeviceAssociations;
use Kadupul\Inventory\Domain\DeviceAssociationChange;

/**
 * Reads and changes the graph and data query associations of a device.
 */
interface DeviceAssociationStore
{
    /**
     * Loads the device's associations of one kind.
     *
     * @param string $kind Either "graph" or "query".
     * @return ?DeviceAssociations Null when the device does not exist or is not visible to the actor.
     */
    public function findVisible(int $actorId, int $id, string $kind): ?DeviceAssociations;
    /**
     * Returns the configured default reindex method (0 to 3) for new data query associations.
     */
    public function defaultReindexMethod(): int;
    /**
     * Lists the graph templates or data queries that can be associated.
     *
     * @param string $kind Either "graph" or "query".
     * @param int $snmpVersion The device's SNMP version; 0 excludes data queries that need SNMP.
     * @return array Names keyed by id.
     */
    public function available(string $kind, int $snmpVersion): array;
    /**
     * Applies a change that has already been checked against the loaded associations.
     *
     * @param string $revision Token from the associations when they were loaded.
     */
    public function change(int $actorId, int $id, DeviceAssociationChange $change, string $revision): void;
}
