<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

final class RrdOneShotCoverageRegistration
{
    public const PRODUCER = 'tests/Fixtures/rrd-characterization.php';
    public const SOURCES = [
        'tests/Helpers/RrdOneShotCoverageRegistration.php',
        'tests/Helpers/NativeChildCoverageEvidence.php', 'tests/Helpers/RrdCharacterization.php',
        'tests/Fixtures/rrd-process-coverage.php', 'tests/Fixtures/rrd-oneshot-close-delay.php',
        'tests/Unit/Core/Rrd/RrdOneShotIoTest.php', 'lib/rrd.php', 'lib/rrd_maintenance.php',
        'src/Graphing/Infrastructure/Rrd/OneShotRrdtool.php',
        'src/Graphing/Infrastructure/Legacy/LegacyRrdWebContext.php',
        'include/global_constants.php', 'composer.lock', 'tests/composer.lock',
    ];
    public const HITS = ['lib/rrd.php', 'src/Graphing/Infrastructure/Rrd/OneShotRrdtool.php'];
    public const MARKERS = ['one-shot-scenario-calls-returned'];
}
