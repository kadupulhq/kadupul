<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Collection\Application\Query;

use Kadupul\Collection\Application\Port\AutomationGraphRuleCatalog;
use Kadupul\Collection\Application\ReadModel\AutomationGraphRulePage;
use Kadupul\Collection\Domain\AutomationGraphRuleCriteria;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;

final readonly class ListAutomationGraphRules
{
    public function __construct(private ConsoleAccess $access, private AutomationGraphRuleCatalog $rules) {}

    public function __invoke(AutomationGraphRuleCriteria $criteria): AutomationGraphRulePage
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
