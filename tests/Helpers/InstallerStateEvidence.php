<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

final class InstallerStateEvidence
{
    public const PRODUCER = 'tests/Fixtures/installer-state-native.php';
    public const MARKERS = ['persisted-state-observed', 'step-contract-observed'];

    /** @return list<string> */
    public static function sources(): array
    {
        $sources = [
            'tests/Unit/Installer/InstallerStateRaceTest.php',
            'tests/Helpers/InstallerStateEvidence.php',
            'tests/Helpers/NativeChildCoverageEvidence.php',
            'tests/Helpers/PhpSource.php',
            'tests/composer.lock', 'composer.lock', 'tests/phpunit-coverage.xml',
            'include/cacti_version', 'cacti.sql', 'lib/installer.php',
            'lib/functions.php', 'lib/path_helpers.php', 'lib/api_automation.php', 'lib/rrd_maintenance.php',
            'lib/poller.php', 'install/functions.php', 'install/install.js',
            'install/step_json.php', 'install/background.php',
        ];
        // The pending font-settings branch adds this functions.php dependency.
        // Register it when present; an actual missing require still fails loading.
        if (is_file(dirname(__DIR__, 2) . '/lib/graph_fonts.php')) {
            $sources[] = 'lib/graph_fonts.php';
        }

        return $sources;
    }
}
