<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

if (getenv('PACKAGE_XML_COVERAGE_DIRECTORY')) {
    define('RRD_TEST_COVERAGE_DIRECTORY', getenv('PACKAGE_XML_COVERAGE_DIRECTORY'));
    define('PACKAGE_XML_TEST_COVERAGE', true);
    require __DIR__ . '/rrd-process-coverage.php';
}
define('POLLER_VERBOSITY_LOW', 2);
define('CACTI_VERSION', '1.3.0');
$logs = array();
function cacti_log($message, ...$args)
{
    $GLOBALS['logs'][] = $message;
}
function __($message, ...$args)
{
    return $args ? vsprintf($message, $args) : $message;
}
function cacti_sizeof($value)
{
    return is_array($value) ? count($value) : 0;
}
function cacti_version_compare($left, $right, $operator)
{
    return version_compare($left, $right, $operator);
}
function html_escape($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES);
}
function db_fetch_cell_prepared($sql, $params)
{
    $statement = $GLOBALS['db']->prepare($sql);
    $statement->execute($params);
    return $statement->fetchColumn();
}
function db_fetch_row_prepared($sql, $params)
{
    return array();
}
function db_execute_prepared($sql, $params)
{
    $statement = $GLOBALS['db']->prepare($sql);
    return $statement->execute($params);
}
function sql_save($save, $table)
{
    return 7;
}
function api_plugin_hook_function($name, &$params)
{
    $params['prepend'] .= '<custom>';
}
require dirname(__DIR__, 2) . '/lib/import.php';
$mode = $argv[1];
if ($mode === 'xml') {
    $result = import_package_get_details($argv[2]);
} else {
    $db = new PDO('sqlite::memory:', null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
    $types = array('graph_template' => array('graph_templates', 'name'), 'data_template' => array('data_template', 'name'), 'data_template_item' => array('data_template_rrd', 'data_source_name'), 'host_template' => array('host_template', 'name'), 'data_input_method' => array('data_input', 'name'), 'data_input_field' => array('data_input_fields', 'name'), 'data_query' => array('snmp_query', 'name'), 'gprint_preset' => array('graph_templates_gprint', 'name'), 'cdef' => array('cdef', 'name'), 'vdef' => array('vdef', 'name'), 'data_source_profile' => array('data_source_profile', 'name'));
    $hash_type_codes = $hash_type_names = array();
    $cacti_version_codes = array('1.3.0' => '0101');
    $hash = str_repeat('a', 32);
    foreach ($types as $type => $lookup) {
        list($table, $column) = $lookup;
        $db->exec("CREATE TABLE $table(id INTEGER, hash TEXT, $column TEXT)");
        $stmt = $db->prepare("INSERT INTO $table VALUES (1,?,?)");
        $stmt->execute(array($hash, '<' . $type . '>'));
        $hash_type_codes[$type] = sprintf('%02x', count($hash_type_codes) + 1);
        $hash_type_names[$type] = $type;
    }
    if ($mode === 'names') {
        $result = array();
        foreach ($types as $type => $lookup) {
            $encoded = 'hash_' . $hash_type_codes[$type] . '0101' . $hash;
            $result[$type] = array(hash_to_friendly_name($encoded, false), hash_to_friendly_name($encoded, true));
        }
        $hash_type_codes['round_robin_archive'] = '20';
        $hash_type_names['round_robin_archive'] = 'RRA';
        $hash_type_codes['custom'] = '21';
        $result['archive'] = hash_to_friendly_name('hash_200101' . $hash, false);
        $result['invalid'] = hash_to_friendly_name('invalid', false);
        $result['custom'] = hash_to_friendly_name('hash_210101' . $hash, false);
    } else {
        $db->exec('CREATE TABLE host_template_graph(host_template_id INTEGER,graph_template_id INTEGER); CREATE TABLE host_template_snmp_query(host_template_id INTEGER,snmp_query_id INTEGER); DELETE FROM host_template');
        $fields_host_template_edit = array();
        $preview_only = $mode === 'preview';
        $graph = 'hash_' . $hash_type_codes['graph_template'] . '0101' . $hash;
        $query = 'hash_' . $hash_type_codes['data_query'] . '0101' . $hash;
        $xml = array('name' => 'Fixture', 'graph_templates' => $mode === 'invalid' ? 'invalid' : $graph, 'data_queries' => $query);
        $cache = array('graph_template' => array($hash => 11), 'data_query' => array($hash => 12));
        if ($mode === 'uncached') {
            $cache = array();
        }
        if ($mode === 'empty') {
            $xml['graph_templates'] = $xml['data_queries'] = '';
        }
        $data = array();
        $returned = xml_to_host_template($hash, $xml, $cache, $data);
        $result = array('returned' => $returned !== false, 'graphs' => $db->query('SELECT * FROM host_template_graph')->fetchAll(PDO::FETCH_NUM), 'queries' => $db->query('SELECT * FROM host_template_snmp_query')->fetchAll(PDO::FETCH_NUM));
    }
}
echo json_encode(array('result' => $result, 'logs' => $logs), JSON_THROW_ON_ERROR);
