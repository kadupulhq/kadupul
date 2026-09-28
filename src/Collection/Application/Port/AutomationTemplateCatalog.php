<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Collection\Application\Port;

use Kadupul\Collection\Application\ReadModel\AutomationTemplatePage;
use Kadupul\Collection\Domain\AutomationTemplateCriteria;

interface AutomationTemplateCatalog
{
    public function list(AutomationTemplateCriteria $criteria): AutomationTemplatePage;
}
