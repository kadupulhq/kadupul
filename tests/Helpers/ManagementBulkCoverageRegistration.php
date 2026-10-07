<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

final class ManagementBulkCoverageRegistration
{
    public const SOURCES = ['lib/auth.php', 'lib/graph_item_choices.php', 'tests/Helpers/PhpSource.php',
        'tests/Fixtures/rrd-process-coverage.php', 'tests/Helpers/NativeChildCoverageEvidence.php', 'lib/rrd.php',
        'src/Graphing/Infrastructure/Rrd/ProxyCipher.php', 'lib/dsdebug.php', 'lib/rrd_maintenance.php', 'lib/poller.php',
        'lib/boost.php', 'lib/api_data_source.php', 'lib/rrdcheck.php', 'lib/dsstats.php',
        'graphs.php', 'data_sources.php', 'host.php', 'lib/functions.php', 'include/global_constants.php',
        'tests/Fixtures/management-bulk-native.php', 'tests/Helpers/ManagementBulkCoverageRegistration.php',
        'tests/Unit/Security/Auth/AuthPolicyNativeCoverageTest.php', 'composer.lock', 'tests/composer.lock', 'cacti.sql'];
    public const MARKERS = ['native-policy-operation-returned', 'policy-session-observed', 'management-batch-observed', 'management-handoff-observed'];
}
