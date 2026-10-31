<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

final class RrdWindowsCoverageRegistration
{
    public const PRODUCER = 'tests/integration/rrd_windows_oneshot.php';
    public const SCENARIO = 'windows-oneshot-21-checks';
    public const SOURCES = [
        'tests/Helpers/RrdWindowsCoverageRegistration.php',
        'tests/Helpers/NativeChildCoverageEvidence.php',
        'tests/Unit/Core/Rrd/RrdWindowsOneShotTest.php',
        'lib/rrd.php', 'lib/graph_fonts.php', 'include/global_constants.php',
        'src/Graphing/Infrastructure/Rrd/WindowsOneShotRrdtool.php',
        'src/Graphing/Infrastructure/Legacy/LegacyRrdWebContext.php',
        'composer.lock', 'tests/composer.lock',
    ];
    public const HITS = [
        'lib/rrd.php', 'src/Graphing/Infrastructure/Rrd/WindowsOneShotRrdtool.php',
        'src/Graphing/Infrastructure/Legacy/LegacyRrdWebContext.php',
    ];
    public const MARKERS = ['windows-oneshot-checks-completed'];
}
