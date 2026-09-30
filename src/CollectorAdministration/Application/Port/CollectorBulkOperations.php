<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\CollectorAdministration\Application\Port;

use Kadupul\CollectorAdministration\Domain\CollectorBulkAction;
use Kadupul\CollectorAdministration\Domain\CollectorSelection;

interface CollectorBulkOperations
{
    /** @return list<array{id:int,name:string,dbhost:string}> */
    public function find(CollectorSelection $selection): array;

    /** @return array{successful:list<int>,failed:list<int>} */
    public function execute(int $actorId, CollectorBulkAction $action, CollectorSelection $selection): array;
}
