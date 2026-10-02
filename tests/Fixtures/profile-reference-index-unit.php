<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

$root = dirname(__DIR__, 2);
$case = $argv[1];
$directory = $argv[2];
$copy = $directory . '/integrity.php';
copy($root . '/lib/data_source_profile_integrity.php', $copy);
if (isset($argv[3])) {
    define('RRD_TEST_COVERAGE_DIRECTORY', $directory);
    define('RRD_TEST_CLI_COVERAGE_COPY', $copy);
    define('RRD_TEST_CLI_COVERAGE_SOURCE', $root . '/lib/data_source_profile_integrity.php');
    require __DIR__ . '/rrd-process-coverage.php';
}
$connection = new PDO('sqlite::memory:');
$queries = 0;
function db_fetch_assoc_prepared($sql, $params, $log = true, $connection = false)
{
    // MySQL/MariaDB metadata is the explicit boundary; production validation is unmodified.
    if (!str_contains($sql, 'information_schema.STATISTICS')
        || !str_contains($sql, 'SEQ_IN_INDEX = 1')
        || $params !== ['data_template_data', 'data_source_profile_id']
        || !$log || $connection !== $GLOBALS['connection']) {
        throw new RuntimeException('Index metadata query lost collector ownership or scope');
    }
    $GLOBALS['queries']++;
    $row = ['SEQ_IN_INDEX' => '1', 'COLUMN_NAME' => 'data_source_profile_id', 'SUB_PART' => null, 'NON_UNIQUE' => '1'];
    switch ($GLOBALS['case']) {
        case 'query-failure': return false;
        case 'missing': return [];
        case 'multiple': return [$row, $row];
        case 'mysql': $row['IS_VISIBLE'] = 'YES';
            break;
        case 'mariadb': $row['IGNORED'] = 'NO';
            break;
        case 'wrong-column': $row['COLUMN_NAME'] = 'id';
            break;
        case 'wrong-sequence': $row['SEQ_IN_INDEX'] = '2';
            break;
        case 'missing-prefix': unset($row['SUB_PART']);
            break;
        case 'prefix': $row['SUB_PART'] = 4;
            break;
        case 'unique': $row['NON_UNIQUE'] = '0';
            break;
        case 'invisible': $row['IS_VISIBLE'] = 'NO';
            break;
        case 'ignored': $row['IGNORED'] = 'YES';
            break;
    }
    return [$row];
}
require $copy;
$result = data_source_profile_reference_index_available($connection);
file_put_contents($directory . '/result.json', json_encode(['result' => $result, 'queries' => $queries], JSON_THROW_ON_ERROR));
