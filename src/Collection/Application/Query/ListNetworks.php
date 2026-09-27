<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Collection\Application\Query;

use Kadupul\Collection\Application\Port\NetworkCatalog;
use Kadupul\Collection\Application\ReadModel\NetworkPage;
use Kadupul\Collection\Domain\NetworkListCriteria;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;

final readonly class ListNetworks
{
    public function __construct(private ConsoleAccess $access, private NetworkCatalog $networks) {}

    public function __invoke(NetworkListCriteria $criteria): NetworkPage
    {
        $actor = $this->access->consoleActor();
        if ($actor === null) {
            throw new AutomationAccessDenied(true);
        }
        if (!$this->access->canManageAutomation($actor)) {
            throw new AutomationAccessDenied(false);
        }
        return $this->networks->list($criteria);
    }
}
