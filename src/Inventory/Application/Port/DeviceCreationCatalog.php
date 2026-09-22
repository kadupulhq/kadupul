<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Application\Port;

use Kadupul\Inventory\Application\Query\DeviceCreationChoices;

interface DeviceCreationCatalog
{
    public function choices(): DeviceCreationChoices;
}
