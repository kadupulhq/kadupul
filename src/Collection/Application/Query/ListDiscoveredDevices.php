<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Collection\Application\Query;

use Kadupul\Collection\Application\Port\DiscoveredDeviceCatalog;
use Kadupul\Collection\Application\ReadModel\DiscoveredDevicePage;
use Kadupul\Collection\Domain\DiscoveredDeviceCriteria;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;

final readonly class ListDiscoveredDevices
{
    public function __construct(private ConsoleAccess $access, private DiscoveredDeviceCatalog $devices) {}

    public function __invoke(DiscoveredDeviceCriteria $criteria): DiscoveredDevicePage
    {
        $actor = $this->access->consoleActor();
        if ($actor === null) {
            throw new AutomationAccessDenied(true);
        }
        if (!$this->access->canManageAutomation($actor)) {
            throw new AutomationAccessDenied(false);
        }
        return $this->devices->list($criteria);
    }
}
