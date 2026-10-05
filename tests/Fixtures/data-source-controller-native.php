<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

// Reuse the owned SQLite/bootstrap ports; execute the physical data-source controller.
$collectCoverage = getenv('DEVICE_GRAPH_CALLER_COVERAGE') === '1';
putenv('DEVICE_GRAPH_CALLER_COVERAGE=0');
require __DIR__ . '/device-graph-caller-native.php';
require_once $root . '/tests/Helpers/DataSourceControllerCoverageRegistration.php';
eval(test_php_function_source(file_get_contents($root . '/lib/functions.php'), 'sanitize_sql_column'));

$db->exec("ALTER TABLE data_local ADD COLUMN data_template_id INTEGER DEFAULT 0;
CREATE TABLE data_template(id INTEGER PRIMARY KEY,name TEXT);
CREATE TABLE data_template_data(id INTEGER PRIMARY KEY,local_data_id INTEGER,data_template_id INTEGER,data_input_id INTEGER,name TEXT,active TEXT);
CREATE TABLE data_template_rrd(id INTEGER PRIMARY KEY,local_data_id INTEGER,data_template_id INTEGER DEFAULT 0,rrd_maximum TEXT,rrd_minimum TEXT,rrd_heartbeat INTEGER,data_source_type_id INTEGER,data_source_name TEXT);
INSERT INTO data_local VALUES(21,12,0),(22,13,0);
INSERT INTO data_template_data VALUES(41,21,0,0,'Allowed source','on'),(42,22,0,0,'Denied source','on');
INSERT INTO data_template_rrd VALUES(31,21,0,'100','0',600,1,'allowed'),(32,22,0,'100','0',600,1,'denied'),(33,99,0,'100','0',600,1,'orphan');
INSERT INTO graph_templates_item VALUES(51,5,0,31),(52,5,0,32);");
function db_fetch_insert_id()
{
    return (int) $GLOBALS['db']->lastInsertId();
}
function api_data_source_disable($id)
{
    db_execute_prepared("UPDATE data_template_data SET active='' WHERE local_data_id=?", [$id]);
}
function api_data_source_enable($id)
{
    db_execute_prepared("UPDATE data_template_data SET active='on' WHERE local_data_id=?", [$id]);
}
function get_data_source_title($id)
{
    return db_fetch_cell_prepared('SELECT name FROM data_template_data WHERE local_data_id=?', [$id]);
}
if (($scenario['fields']['action'] ?? '') === 'ds_enable') $db->exec("UPDATE data_template_data SET active=''");
$initial = [];
foreach (['data_local','data_template_data','data_template_rrd','graph_templates_item'] as $table) $initial[$table] = $db->query('SELECT * FROM ' . $table . ' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
if ($collectCoverage) {
    $nativeChildCoverageSnapshot = NativeChildCoverageEvidence::snapshot($root, 'tests/Fixtures/data-source-controller-native.php', getenv('DEVICE_GRAPH_CALLER_SCENARIO'), DataSourceControllerCoverageRegistration::SOURCES);
    define('DATA_SOURCE_CONTROLLER_TEST_COVERAGE', true);
    define('RRD_TEST_COVERAGE_DIRECTORY', $directory);
    require $root . '/tests/Fixtures/rrd-process-coverage.php';
}
register_shutdown_function(static function () use ($directory, $db, $initial) {
    $state = json_decode(file_get_contents($directory . '/state.json'), true, 512, JSON_THROW_ON_ERROR);
    $state['initial'] = $initial;
    foreach (array_keys($initial) as $table) $state['persisted'][$table] = $db->query('SELECT * FROM ' . $table . ' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    file_put_contents($directory . '/state.json', json_encode($state, JSON_THROW_ON_ERROR));
    if ($state['fatal'] === null) $GLOBALS['nativeChildCoverageMarkers'] = ['data-source-outcome-observed', 'data-source-persisted-state-observed'];
});
