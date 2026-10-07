<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

final class GraphCacheCoverageRegistration
{
    public const SOURCES = array('lib/auth.php', 'lib/graph_item_choices.php', 'tests/Helpers/PhpSource.php',
        'tests/Fixtures/rrd-process-coverage.php', 'tests/Helpers/NativeChildCoverageEvidence.php',
        'lib/rrd.php', 'src/Graphing/Infrastructure/Rrd/ProxyCipher.php', 'lib/dsdebug.php',
        'lib/rrd_maintenance.php', 'lib/poller.php', 'lib/boost.php', 'lib/api_data_source.php',
        'lib/rrdcheck.php', 'lib/dsstats.php', 'graph_image.php', 'lib/html_utility.php',
        'include/global_constants.php', 'tests/Fixtures/graph-cache-native.php',
        'tests/Helpers/GraphCacheCoverageRegistration.php');
}
