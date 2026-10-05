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
CREATE TABLE data_template_data(id INTEGER PRIMARY KEY,local_data_id INTEGER,data_template_id INTEGER,data_input_id INTEGER,name TEXT,active TEXT,name_cache TEXT DEFAULT 'Original cache');
CREATE TABLE data_template_rrd(id INTEGER PRIMARY KEY,local_data_id INTEGER,data_template_id INTEGER DEFAULT 0,rrd_maximum TEXT,rrd_minimum TEXT,rrd_heartbeat INTEGER,data_source_type_id INTEGER,data_source_name TEXT);
INSERT INTO data_local VALUES(21,12,0),(22,13,0),(23,0,0);
INSERT INTO data_template_data(id,local_data_id,data_template_id,data_input_id,name,active) VALUES(41,21,0,0,'Allowed source','on'),(42,22,0,0,'Denied source','on'),(43,23,0,0,'Non-device source','on');
INSERT INTO data_template_rrd VALUES(31,21,0,'100','0',600,1,'allowed'),(32,22,0,'100','0',600,1,'denied'),(33,99,0,'100','0',600,1,'orphan');
INSERT INTO graph_templates_item VALUES(51,5,0,31),(52,5,0,32);");
function db_fetch_insert_id()
{
    return (int) $GLOBALS['db']->lastInsertId();
}
function api_data_source_disable($id)
{
    $GLOBALS['events'][] = ['leaf-port', __FUNCTION__, (int) $id];
    db_execute_prepared("UPDATE data_template_data SET active='' WHERE local_data_id=?", [$id]);
}
function api_data_source_enable($id)
{
    $GLOBALS['events'][] = ['leaf-port', __FUNCTION__, (int) $id];
    db_execute_prepared("UPDATE data_template_data SET active='on' WHERE local_data_id=?", [$id]);
}
// Explicit API leaf ports: controller policy and argument handoff are physical;
// these SQLite effects do not claim production API/collector execution.
function api_data_source_change_host($ids, $host)
{
    $GLOBALS['events'][] = ['leaf-port', __FUNCTION__, array_map('intval', $ids), (int) $host];
    foreach ($ids as $id) db_execute_prepared('UPDATE data_local SET host_id=? WHERE id=?', [$host,$id]);
}
function api_reapply_suggested_data_source_data($id)
{
    $GLOBALS['events'][] = ['leaf-port', __FUNCTION__, (int) $id];
    db_execute_prepared("UPDATE data_template_data SET name='Suggested source' WHERE local_data_id=?", [$id]);
}
function update_data_source_title_cache($id)
{
    $GLOBALS['events'][] = ['leaf-port', __FUNCTION__, (int) $id];
    db_execute_prepared('UPDATE data_template_data SET name_cache=name WHERE local_data_id=?', [$id]);
}
function snmpagent_data_source_action_bottom($args)
{
    $GLOBALS['events'][] = ['snmpagent-bottom', $args];
}
function cacti_count($value)
{
    return is_countable($value) ? count($value) : 0;
}
function get_data_source_title($id)
{
    return db_fetch_cell_prepared('SELECT name FROM data_template_data WHERE local_data_id=?', [$id]);
}
if (($scenario['fields']['action'] ?? '') === 'ds_enable' || (($scenario['fields']['action'] ?? '') === 'actions' && (int) ($scenario['fields']['drp_action'] ?? 0) === 6)) $db->exec("UPDATE data_template_data SET active=''");
if ($scenario['native_dropdown'] ?? false) {
    // Bootstrap field substitution is a fixture port; labels have no substitution tokens.
    define('VALID_HOST_FIELDS', '(hostname|host_id)');
    foreach (['form_dropdown' => 'lib/html_form.php', 'html_create_list' => 'lib/html.php', 'null_out_substitutions' => 'lib/variables.php', 'escape_page_action' => 'lib/functions.php'] as $name => $file) eval(test_php_function_source(file_get_contents($root . '/' . $file), $name));
    $db->sqliteCreateFunction('CONCAT_WS', static fn($separator, ...$values) => implode($separator, array_filter($values, static fn($value) => $value !== null)));
    $db->exec("CREATE TABLE graph_tree(id INTEGER PRIMARY KEY,name TEXT);
        INSERT INTO graph_local(id,host_id,graph_template_id) VALUES(101,12,5);
        INSERT INTO graph_templates_graph(id,local_graph_id,graph_template_id,title_cache) VALUES(201,101,5,'Allowed graph');
        INSERT INTO user_auth_perms VALUES(42,1,101),(42,3,14);
        INSERT INTO host(id,description,hostname,disabled) VALUES(14,'D allowed enabled device','enabled','');");
}
function __n($one, $many, $count)
{
    return $count === 1 ? $one : $many;
}
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
    $state['filtered_selection'] = get_nfilter_request_var('selected_items');
    foreach (array_keys($initial) as $table) $state['persisted'][$table] = $db->query('SELECT * FROM ' . $table . ' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    file_put_contents($directory . '/state.json', json_encode($state, JSON_THROW_ON_ERROR));
    if ($state['fatal'] === null) $GLOBALS['nativeChildCoverageMarkers'] = ['data-source-outcome-observed', 'data-source-persisted-state-observed'];
});
