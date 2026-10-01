<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

if (getenv('HELPER_UNION_HTTP_COVERAGE') === '1') {
    define('RRD_TEST_COVERAGE_DIRECTORY', getenv('HELPER_UNION_HTTP_DIRECTORY'));
    define('RRD_TEST_CLI_COVERAGE_COPY', RRD_TEST_COVERAGE_DIRECTORY . '/endpoint.php');
    define('RRD_TEST_CLI_COVERAGE_SOURCE', dirname(__DIR__, 2) . '/lib/csp_report_endpoint.php');
    require __DIR__ . '/rrd-process-coverage.php';
}
