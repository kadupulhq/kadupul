<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

if (isset($argv[1])) {
    define('INPUT_STRING_VALIDATOR_TEST_COVERAGE', true);
    define('RRD_TEST_COVERAGE_DIRECTORY', $argv[1]);
    require __DIR__ . '/rrd-process-coverage.php';
}
// Use the production configuration reader with an already loaded default policy.
$config = ['is_web' => false, 'config_options_array' => ['allow_unsafe_metachars' => '']];
require dirname(__DIR__, 2) . '/lib/functions.php';
echo json_encode(cacti_input_string_is_safe(json_decode(stream_get_contents(STDIN), true, 16, JSON_THROW_ON_ERROR)), JSON_THROW_ON_ERROR);
