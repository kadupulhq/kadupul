<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

final class RrdObservedWindowCoverageRegistration
{
    public const PRODUCER = 'tests/Fixtures/rrd-characterization.php';
    public const SOURCES = [
        'tests/Helpers/RrdObservedWindowCoverageRegistration.php',
        'tests/Helpers/NativeChildCoverageEvidence.php', 'tests/Helpers/RrdCharacterization.php',
        'tests/Helpers/GraphXport.php', 'tests/Fixtures/rrd-process-coverage.php',
        'tests/Unit/Core/Rrd/RrdObservedWindowTest.php',
        'lib/rrd.php', 'lib/xml.php', 'lib/functions.php', 'lib/variables.php', 'lib/boost.php',
        'graph_xport.php', 'include/global_constants.php',
        'src/Graphing/Infrastructure/Rrd/OneShotRrdtool.php',
        'src/Graphing/Infrastructure/Rrd/PipeEncoder.php',
        'src/Graphing/Infrastructure/Rrd/UnrepresentableArgument.php',
        'src/Graphing/Infrastructure/Rrd/GraphItemConsolidationResolver.php',
        'src/Graphing/Infrastructure/Legacy/LegacyRrdWebContext.php',
        'src/Platform/Application/Port/Clock.php',
        'composer.lock', 'tests/composer.lock',
    ];
    public const HITS = ['lib/rrd.php'];
    public const MARKERS = ['observed-window-scenario-calls-returned'];
}
