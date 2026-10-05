<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

final class SpikeControllerCoverageRegistration
{
    public const SOURCES = ['lib/auth.php', 'lib/graph_item_choices.php', 'tests/Helpers/PhpSource.php',
        'tests/Fixtures/rrd-process-coverage.php', 'tests/Helpers/NativeChildCoverageEvidence.php', 'lib/rrd.php',
        'src/Graphing/Infrastructure/Rrd/ProxyCipher.php', 'lib/dsdebug.php', 'lib/rrd_maintenance.php', 'lib/poller.php',
        'lib/boost.php', 'lib/api_data_source.php', 'lib/rrdcheck.php', 'lib/dsstats.php',
        'spikekill.php', 'lib/html_utility.php', 'include/vendor/csrf/csrf-magic.php',
        'include/vendor/csrf/csrf-conf.php', 'include/global_constants.php', 'composer.lock', 'tests/composer.lock', 'cacti.sql',
        'tests/Fixtures/spike-controller-native.php', 'tests/Helpers/SpikeControllerCoverageRegistration.php'];
    public const MARKERS = ['spike-controller-response-observed', 'persisted-graph-policy-observed', 'spike-processor-handoff-observed'];
    public const HITS = ['lib/auth.php', 'lib/html_utility.php', 'spikekill.php'];
}
