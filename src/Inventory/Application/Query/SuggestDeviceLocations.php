<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Application\Query;

use Kadupul\Inventory\Application\Port\DeviceLocations;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;

/**
 * Query that suggests device locations matching a search term.
 */
final readonly class SuggestDeviceLocations
{
    /**
     * Binds the query to console access and the device locations port.
     */
    public function __construct(private ConsoleAccess $access, private DeviceLocations $locations) {}
    /**
     * Returns the locations that contain the term.
     *
     * @param string $term Text to look for, at most 200 bytes of valid UTF-8.
     * @return array List of string.
     * @throws \InvalidArgumentException When the term is too long or not valid text.
     * @throws InventoryAccessDenied When nobody is signed in or the user may not manage devices.
     */
    public function __invoke(string $term): array
    {
        $actor = $this->access->consoleActor();
        if ($actor === null || !$this->access->canManageDevices($actor)) {
            throw new InventoryAccessDenied($actor === null);
        }
        if (strlen($term) > 200 || !mb_check_encoding($term, 'UTF-8') || str_contains($term, "\0")) {
            throw new \InvalidArgumentException('Invalid location search.');
        }
        return $this->locations->matching($actor->id, $term);
    }
}
