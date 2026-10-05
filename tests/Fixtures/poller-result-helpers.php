<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

$root = $argv[1];
if ($argv[3] !== '') {
    require_once $root . '/tests/Helpers/NativeChildCoverageEvidence.php';
    $GLOBALS['nativeChildCoverageSnapshot'] = NativeChildCoverageEvidence::snapshot($root, 'tests/Fixtures/poller-result-helpers.php', 'field-lists-v1', [
        'lib/functions.php', 'lib/path_helpers.php', 'include/global_constants.php',
        'lib/rrd.php', 'src/Graphing/Infrastructure/Rrd/ProxyCipher.php', 'lib/dsdebug.php', 'lib/rrd_maintenance.php', 'lib/poller.php', 'lib/boost.php', 'lib/api_data_source.php', 'lib/rrdcheck.php', 'lib/dsstats.php',
        'tests/Helpers/NativeChildCoverageEvidence.php', 'tests/Fixtures/rrd-process-coverage.php',
    ]);
    define('POLLER_RESULT_HELPERS_TEST_COVERAGE', true);
    define('RRD_TEST_COVERAGE_DIRECTORY', $argv[3]);
    require $root . '/tests/Fixtures/rrd-process-coverage.php';
}
require $root . '/include/global_constants.php';
require $root . '/lib/functions.php';
$config = ['is_web' => false, 'config_options_array' => ['data_source_trace' => '']];
$output = [];
foreach (json_decode($argv[2], true, 512, JSON_THROW_ON_ERROR) as $input) {
    $result = $input;
    $valid = prepare_validate_result($result);
    $output[] = [$result, $valid];
}
$GLOBALS['nativeChildCoverageMarkers'] = ['field-lists-completed'];
echo json_encode($output, JSON_THROW_ON_ERROR);
