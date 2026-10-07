<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

final class ProxyBootstrapCoverageRegistration
{
    public const SOURCES = ['include/global.php', 'include/runtime.php', 'include/cacti_version', 'lib/functions.php',
        'include/global_constants.php', 'composer.lock', 'tests/composer.lock', 'tests/Helpers/NativeChildCoverageEvidence.php',
        'tests/Unit/Security/ProxyConfigBootstrapNativeTest.php', 'tests/Fixtures/rrd-process-coverage.php', 'tests/Helpers/ProxyBootstrapCoverageRegistration.php',
        'lib/rrd.php', 'src/Graphing/Infrastructure/Rrd/ProxyCipher.php', 'lib/dsdebug.php', 'lib/rrd_maintenance.php',
        'lib/poller.php', 'lib/boost.php', 'lib/api_data_source.php', 'lib/rrdcheck.php', 'lib/dsstats.php'];
    public const MARKERS = ['actual-proxy-config-bootstrap-readback'];
    public const HITS = ['include/global.php', 'lib/functions.php'];
}
