<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\CollectorAdministration\Application\Command;

use Kadupul\CollectorAdministration\Application\Port\CollectorBulkOperations;
use Kadupul\CollectorAdministration\Application\Query\CollectorAccessDenied;
use Kadupul\CollectorAdministration\Domain\CollectorBulkAction;
use Kadupul\CollectorAdministration\Domain\CollectorSelection;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;

final readonly class ExecuteCollectorBulkAction
{
    public function __construct(private ConsoleAccess $access, private CollectorBulkOperations $operations) {}

    public function __invoke(CollectorBulkAction $action, CollectorSelection $selection): array
    {
        $actor = $this->access->consoleActor();
        if ($actor === null) {
            throw new CollectorAccessDenied(true);
        }
        if (!$this->access->canManageDevices($actor)) {
            throw new CollectorAccessDenied(false);
        }
        if ($action->protectsPrimary() && in_array(1, $selection->ids, true)) {
            throw new \InvalidArgumentException('The primary data collector cannot be changed by this action.');
        }
        return $this->operations->execute($actor->id, $action, $selection);
    }
}
