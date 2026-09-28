<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Collection\Application\Port;

use Kadupul\Collection\Application\ReadModel\AutomationTreeRulePage;
use Kadupul\Collection\Domain\AutomationTreeRuleCriteria;

interface AutomationTreeRuleCatalog
{
    public function list(AutomationTreeRuleCriteria $criteria): AutomationTreeRulePage;
}
