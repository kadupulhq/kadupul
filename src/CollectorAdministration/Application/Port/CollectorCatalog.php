<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\CollectorAdministration\Application\Port;

use Kadupul\CollectorAdministration\Application\ReadModel\CollectorPage;
use Kadupul\CollectorAdministration\Domain\CollectorListCriteria;

interface CollectorCatalog
{
    public function defaultPageSize(): int;

    public function list(CollectorListCriteria $criteria): CollectorPage;
}
