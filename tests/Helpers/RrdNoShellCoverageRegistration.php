<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

final class RrdNoShellCoverageRegistration
{
    public const PRODUCER = 'tests/Unit/Core/Rrd/RrdNoShellNativeTest.php';
    public const SOURCES = [
        'tests/Helpers/RrdNoShellCoverageRegistration.php',
        'tests/Helpers/NativeChildCoverageEvidence.php',
        'tests/Fixtures/rrd-process-coverage.php',
        'lib/rrd.php', 'lib/rrd_maintenance.php', 'include/global_constants.php',
        'lib/dsdebug.php', 'lib/poller.php', 'lib/boost.php',
        'lib/api_data_source.php', 'lib/rrdcheck.php', 'lib/dsstats.php',
        'src/Graphing/Infrastructure/Legacy/LegacyRrdWebContext.php',
        'src/Graphing/Infrastructure/Rrd/LocalRrdtool.php',
        'src/Graphing/Infrastructure/Rrd/OneShotRrdtool.php',
        'src/Graphing/Infrastructure/Rrd/ProxyCipher.php',
        'src/Platform/Infrastructure/Legacy/LegacyComponentAutoloader.php',
        'composer.lock', 'tests/composer.lock',
    ];
    public const MARKERS = ['no-shell-scenario-returned'];

    /** @return list<string> */
    public static function requiredHits(string $mode): array
    {
        return ['lib/rrd.php', ...(str_contains($mode, 'context') ? ['src/Graphing/Infrastructure/Legacy/LegacyRrdWebContext.php'] : [])];
    }
}
