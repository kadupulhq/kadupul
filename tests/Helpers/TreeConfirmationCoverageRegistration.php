<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

final class TreeConfirmationCoverageRegistration
{
    public const SOURCES = array(
        'composer.lock',
        'tests/composer.lock',
        'tools/dependencies/legacy-files.json',
        'include/vendor/csrf/csrf-magic.php',
        'tests/Fixtures/rrd-process-coverage.php',
        'tests/Helpers/NativeChildCoverageEvidence.php',
        'tests/Helpers/PhpSource.php',
        'tests/Helpers/TreeConfirmationCoverageRegistration.php',
        'tests/Fixtures/tree-confirmation-native-router.php',
        'tests/Unit/Security/Auth/TreeConfirmationAuthorizationTest.php',
        'tree.php',
        'lib/auth.php',
        'lib/html_utility.php',
        'include/global_constants.php',
        'lib/functions.php',
        'lib/html.php',
        'lib/database.php',
        'lib/api_tree.php',
        'lib/html_tree.php',
        'lib/data_query.php',
        'lib/rrd.php',
        'src/Graphing/Infrastructure/Rrd/ProxyCipher.php',
        'lib/dsdebug.php',
        'lib/rrd_maintenance.php',
        'lib/poller.php',
        'lib/boost.php',
        'lib/api_data_source.php',
        'lib/rrdcheck.php',
        'lib/dsstats.php',
    );
    public const MARKERS = array('tree-controller-state-readback', 'tree-controller-output-readback');
    public const HITS = array('tree.php', 'lib/auth.php');
}
