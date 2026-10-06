<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Application\Query;

use Kadupul\Inventory\Application\Port\DeviceLocations;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;

final readonly class SuggestDeviceLocations
{
    public function __construct(private ConsoleAccess $access, private DeviceLocations $locations) {}
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
