<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Application\Command;

/** The realm a maintenance action needs: the one the web page for the same action requires. */
enum MaintenanceRealm
{
    /** Realm 15, Settings/Utilities: utilities.php (include/global_arrays.php:1299). */
    case Utilities;
    /** Realm 26, Installation/Upgrades: install.php and step_json.php (include/global_arrays.php:1284-1285). */
    case Upgrade;
}
