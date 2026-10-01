<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$root = dirname(__DIR__, 2);
$directory = $argv[2];
$source = $root . '/lib/data_source_profile_integrity.php';
$copy = $directory . '/profile-integrity.php';
copy($source, $copy);
if (isset($argv[3])) {
    define('RRD_TEST_COVERAGE_DIRECTORY', $directory);
    define('RRD_TEST_CLI_COVERAGE_COPY', $copy);
    define('RRD_TEST_CLI_COVERAGE_SOURCE', $source);
    require __DIR__ . '/rrd-process-coverage.php';
}
require $copy;
function db_fetch_assoc_prepared($sql, $params = [])
{
    if (str_contains($sql, 'information_schema.TABLES')) {
        return $GLOBALS['engines'];
    }
    return $GLOBALS['rows'];
}
$definitions = data_source_profile_reference_triggers();
$engines = [['TABLE_NAME' => 'data_source_profiles', 'ENGINE' => 'InnoDB'], ['TABLE_NAME' => 'data_template_data', 'ENGINE' => 'InnoDB']];
$schema = file_get_contents($root . '/cacti.sql');
$fresh = true;
$rows = [];
foreach ($definitions as $name => $definition) {
    $fresh = $fresh && str_contains($schema, $definition['sql'] . '$$');
    $rows[] = ['TRIGGER_NAME' => $name, 'ACTION_TIMING' => $definition['timing'], 'EVENT_MANIPULATION' => $definition['event'], 'ACTION_STATEMENT' => $definition['body']];
}
$engines[] = ['TABLE_NAME' => 'data_source_profiles_rra', 'ENGINE' => 'InnoDB'];
$engines[] = ['TABLE_NAME' => 'data_source_profiles_cf', 'ENGINE' => 'InnoDB'];
$valid = data_source_profile_reference_guards_available();
$baseline = $rows;
$refusals = [];
foreach ([null, [], [$rows[0]], [[], $rows[1]]] as $invalid) {
    $rows = $invalid;
    $refusals[] = !data_source_profile_reference_guards_available();
}
foreach (['TRIGGER_NAME' => 'unknown', 'ACTION_TIMING' => 'BEFORE', 'EVENT_MANIPULATION' => 'DELETE', 'ACTION_STATEMENT' => 'BEGIN END'] as $key => $value) {
    $rows = $baseline;
    $rows[0][$key] = $value;
    $refusals[] = !data_source_profile_reference_guards_available();
}
$invalidIdentifiers = [];
$engineRefusals = [];
foreach ([null, [], [['TABLE_NAME' => 'data_source_profiles', 'ENGINE' => 'MyISAM'], ['TABLE_NAME' => 'data_template_data', 'ENGINE' => 'InnoDB']]] as $invalid) {
    $engines = $invalid;
    $engineRefusals[] = !data_source_profile_reference_guards_available();
}
foreach (['', 'unsafe.name', str_repeat('x', 52)] as $identifier) {
    try {
        data_source_profile_reference_triggers($identifier);
        $invalidIdentifiers[] = false;
    } catch (InvalidArgumentException $error) {
        $invalidIdentifiers[] = true;
    }
}
file_put_contents($directory . '/result.json', json_encode(['fresh' => $fresh, 'valid' => $valid, 'refusals' => $refusals, 'engineRefusals' => $engineRefusals, 'invalidIdentifiers' => $invalidIdentifiers], JSON_THROW_ON_ERROR));
