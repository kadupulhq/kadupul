<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Application\Port;

use Kadupul\Inventory\Domain\DeviceSelection;

interface DeviceTemplateSynchronization
{
    public function synchronizeTemplates(int $actorId, DeviceSelection $selection): void;
}
