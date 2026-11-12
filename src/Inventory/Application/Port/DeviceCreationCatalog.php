<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Application\Port;

use Kadupul\Inventory\Application\Query\DeviceCreationChoices;

/**
 * Supplies the choices offered on the device creation form.
 */
interface DeviceCreationCatalog
{
    /**
     * Returns the available templates, sites, collectors and similar options.
     */
    public function choices(): DeviceCreationChoices;
}
