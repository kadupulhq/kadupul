<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

final class GraphDataRemovalCoverageRegistration
{
    public const SOURCES = array('lib/auth.php', 'lib/graph_item_choices.php', 'tests/Helpers/PhpSource.php',
        'tests/Fixtures/rrd-process-coverage.php', 'tests/Helpers/NativeChildCoverageEvidence.php', 'lib/rrd.php',
        'src/Graphing/Infrastructure/Rrd/ProxyCipher.php', 'lib/dsdebug.php', 'lib/rrd_maintenance.php', 'lib/poller.php',
        'lib/boost.php', 'lib/api_data_source.php', 'lib/rrdcheck.php', 'lib/dsstats.php',
        'lib/graph_data_removal.php', 'lib/api_graph.php', 'graphs.php', 'data_sources.php',
        'src/Platform/Infrastructure/Legacy/LegacyReferenceWriteTransaction.php', 'tests/Fixtures/graph-data-removal-native.php',
        'tests/Helpers/GraphDataRemovalCoverageRegistration.php', 'include/global_constants.php', 'cacti.sql',
        'composer.lock', 'tests/composer.lock', 'lib/html_form.php', 'lib/graph_template_input.php');
    public const MARKERS = array('native-policy-operation-returned', 'policy-session-observed', 'cascade-controller-state-observed');
    public const HITS = array('lib/auth.php', 'lib/graph_data_removal.php');
}
