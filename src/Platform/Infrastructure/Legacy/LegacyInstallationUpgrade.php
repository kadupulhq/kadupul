<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Legacy;

use Kadupul\Platform\Application\Port\InstallationUpgrade;
use Kadupul\Platform\Application\ReadModel\UpgradeOutput;

/**
 * bin/legacy-audit-upgrade.php, which holds upgrade_database() moved out of
 * cli/audit_database.php. AuditDatabase checked the operator before calling
 * this; the worker checks nothing itself.
 */
final readonly class LegacyInstallationUpgrade implements InstallationUpgrade
{
    private const string WORKER = 'legacy-audit-upgrade.php';
    private const string MARKER = 'KADUPUL_UPGRADE_RESULT';

    public function __construct(private LegacyWorkerProcess $worker) {}

    #[\Override]
    public function run(): UpgradeOutput
    {
        // No timeout, deliberately: an upgrade stopped half way leaves the
        // schema between two versions, and the script's exec call never
        // stopped one. The run can block for as long as a plugin's upgrade
        // takes, and the operator's only way out is to interrupt it.
        $result = $this->worker->run(self::WORKER, self::MARKER, [], null);

        return new UpgradeOutput($result['output'], $result['errors'], $result['ok']);
    }
}
