<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Application\Port;

use Kadupul\Platform\Application\ReadModel\UpgradeOutput;

interface InstallationUpgrade
{
    /** upgrade_database() as audit_database.php ran it: cli/upgrade_database.php, then every plugin's upgrade. */
    public function run(): UpgradeOutput;
}
