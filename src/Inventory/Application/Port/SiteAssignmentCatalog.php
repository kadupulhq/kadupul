<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Application\Port;

/**
 * Lists the sites a device can be assigned to.
 */
interface SiteAssignmentCatalog
{
    /** @return array<int, string> */
    public function sites(): array;
}
