<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\CollectorAdministration\Application\Query;

use Kadupul\CollectorAdministration\Application\Port\CollectorCatalog;
use Kadupul\CollectorAdministration\Application\ReadModel\CollectorPage;
use Kadupul\CollectorAdministration\Domain\CollectorListCriteria;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;

final readonly class ListCollectors
{
    public function __construct(private ConsoleAccess $access, private CollectorCatalog $collectors) {}

    public function __invoke(CollectorListCriteria $criteria): CollectorPage
    {
        $actor = $this->access->consoleActor();
        if ($actor === null) {
            throw new CollectorAccessDenied(true);
        }
        if (!$this->access->canManageDevices($actor)) {
            throw new CollectorAccessDenied(false);
        }

        return $this->collectors->list($criteria);
    }
}
