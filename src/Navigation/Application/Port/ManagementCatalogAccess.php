<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Kadupul\Navigation\Application\Port;

use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\Navigation\Contract\Management\CatalogScreen;

interface ManagementCatalogAccess
{
    public function authorize(CatalogScreen $screen): Actor;
}
