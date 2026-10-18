<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

final class RrdProxyDeadlineCoverageRegistration
{
    public const PRODUCER = 'tests/Fixtures/rrd-proxy-command-deadline.php';
    public const SOURCES = [
        'tests/Helpers/RrdProxyDeadlineCoverageRegistration.php',
        'tests/Helpers/NativeChildCoverageEvidence.php', 'tests/Helpers/RrdProxyFrames.php',
        'tests/Fixtures/rrd-process-coverage.php', 'tests/Unit/Core/Rrd/RrdProxyDeadlineNativeTest.php',
        'lib/rrd.php', 'src/Graphing/Infrastructure/Rrd/ProxyCipher.php',
        'include/global_constants.php', 'composer.lock', 'tests/composer.lock',
    ];
    public const HITS = ['lib/rrd.php', 'src/Graphing/Infrastructure/Rrd/ProxyCipher.php'];
    public const MARKERS = ['proxy-exchange-and-wire-readback-complete'];
}
