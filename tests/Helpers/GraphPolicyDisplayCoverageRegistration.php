<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

final class GraphPolicyDisplayCoverageRegistration
{
    public const SOURCES = ['lib/auth.php', 'lib/graph_item_choices.php', 'tests/Helpers/PhpSource.php',
        'tests/Fixtures/rrd-process-coverage.php', 'tests/Helpers/NativeChildCoverageEvidence.php', 'lib/rrd.php',
        'src/Graphing/Infrastructure/Rrd/ProxyCipher.php', 'lib/dsdebug.php', 'lib/rrd_maintenance.php', 'lib/poller.php',
        'lib/boost.php', 'lib/api_data_source.php', 'lib/rrdcheck.php', 'lib/dsstats.php',
        'tests/Fixtures/graph-policy-display-native.php', 'tests/Helpers/GraphPolicyDisplayCoverageRegistration.php',
        'tests/Unit/Security/Auth/GraphPolicyDisplaySqlParityTest.php', 'composer.lock', 'tests/composer.lock', 'cacti.sql'];
    public const MARKERS = ['native-policy-operation-returned', 'policy-session-observed', 'persisted-policy-display-compared'];
}
