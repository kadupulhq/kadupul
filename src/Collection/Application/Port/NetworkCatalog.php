<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Collection\Application\Port;

use Kadupul\Collection\Application\ReadModel\NetworkPage;
use Kadupul\Collection\Domain\NetworkListCriteria;

interface NetworkCatalog
{
    public function list(NetworkListCriteria $criteria): NetworkPage;
}
