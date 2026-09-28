<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Collection\Application\Port;

use Kadupul\Collection\Application\ReadModel\AutomationGraphRulePage;
use Kadupul\Collection\Domain\AutomationGraphRuleCriteria;

interface AutomationGraphRuleCatalog
{
    public function list(AutomationGraphRuleCriteria $criteria): AutomationGraphRulePage;
}
