<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Collection\Application\Query;

use Kadupul\Collection\Application\Port\AutomationTemplateCatalog;
use Kadupul\Collection\Application\ReadModel\AutomationTemplatePage;
use Kadupul\Collection\Domain\AutomationTemplateCriteria;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;

final readonly class ListAutomationTemplates
{
    public function __construct(private ConsoleAccess $access, private AutomationTemplateCatalog $templates) {}

    public function __invoke(AutomationTemplateCriteria $criteria): AutomationTemplatePage
    {
        $actor = $this->access->consoleActor();
        if ($actor === null) {
            throw new AutomationAccessDenied(true);
        }
        if (!$this->access->canManageAutomation($actor)) {
            throw new AutomationAccessDenied(false);
        }
        return $this->templates->list($criteria);
    }
}
