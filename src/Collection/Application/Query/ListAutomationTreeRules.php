<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Collection\Application\Query;

use Kadupul\Collection\Application\Port\AutomationTreeRuleCatalog;
use Kadupul\Collection\Application\ReadModel\AutomationTreeRulePage;
use Kadupul\Collection\Domain\AutomationTreeRuleCriteria;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;

final readonly class ListAutomationTreeRules
{
    public function __construct(private ConsoleAccess $access, private AutomationTreeRuleCatalog $rules) {}

    public function __invoke(AutomationTreeRuleCriteria $criteria): AutomationTreeRulePage
    {
        $actor = $this->access->consoleActor();
        if ($actor === null) {
            throw new AutomationAccessDenied(true);
        }
        if (!$this->access->canManageAutomation($actor)) {
            throw new AutomationAccessDenied(false);
        }
        return $this->rules->list($criteria);
    }
}
