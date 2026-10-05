<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

// The parent owns this schema; every SQL statement runs through the production
// database helpers on that same connection. No collector connection is opened.
$root = $argv[1];
if ($coverageDirectory = getenv('KADUPUL_OUTPUT_TYPE_COVERAGE')) {
    require_once $root . '/tests/Helpers/NativeChildCoverageEvidence.php';
    $GLOBALS['nativeChildCoverageSnapshot'] = NativeChildCoverageEvidence::snapshot($root, 'tests/Fixtures/poller-output-type-native.php', 'query-output-types-v1', [
        'lib/utility.php', 'lib/database.php', 'lib/api_poller.php', 'lib/data_query.php', 'lib/xml.php', 'lib/functions.php',
        'lib/rrd.php', 'src/Graphing/Infrastructure/Rrd/ProxyCipher.php', 'lib/dsdebug.php', 'lib/rrd_maintenance.php', 'lib/poller.php', 'lib/boost.php', 'lib/api_data_source.php', 'lib/rrdcheck.php', 'lib/dsstats.php',
        'src/Inventory/Infrastructure/Legacy/PollerCacheBufferWrite.php', 'src/Inventory/Infrastructure/Legacy/QueuedCollectorPurge.php',
        'src/Platform/Infrastructure/Legacy/NativeReferenceWriteTransactionRunner.php', 'src/Platform/Infrastructure/Legacy/LegacyReferenceWriteTransaction.php',
        'cacti.sql', 'resource/snmp_queries/interface.xml', 'tests/Helpers/NativeChildCoverageEvidence.php', 'tests/Fixtures/rrd-process-coverage.php',
    ]);
    define('POLLER_OUTPUT_TYPE_TEST_COVERAGE', true);
    define('RRD_TEST_COVERAGE_DIRECTORY', $coverageDirectory);
    require $root . '/tests/Fixtures/rrd-process-coverage.php';
}
require $root . '/include/vendor/autoload.php';
require $root . '/include/global_constants.php';
require $root . '/tests/Helpers/PhpSource.php';
require $root . '/lib/database.php';

class OutputTypeStatement extends PDOStatement
{
    protected function __construct() {}

    public function execute(?array $params = null): bool
    {
        if (str_contains($this->queryString, 'sqgr.snmp_field_name')) {
            $GLOBALS['output_queries'][] = [$this->queryString, $params];
        }
        return parent::execute($params);
    }
}

$db = new PDO(getenv('KADUPUL_OUTPUT_TYPE_DSN'), getenv('KADUPUL_OUTPUT_TYPE_USER'), getenv('KADUPUL_OUTPUT_TYPE_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->setAttribute(PDO::ATTR_STATEMENT_CLASS, [OutputTypeStatement::class]);
$db->exec("SET SESSION sql_mode = ''");
$database_hostname = 'owned';
$database_port = 1;
$database_default = 'fixture';
$database_sessions = ['owned:1:fixture' => $db];
$database_total_queries = 0;
$config = ['is_web' => false, 'poller_id' => 1, 'base_path' => $root, 'library_path' => $root . '/lib',
    'cacti_server_os' => 'unix', 'rra_path' => '/owned',
    'config_options_array' => ['poller_interval' => 300, 'process_leveling' => '', 'path_snmpget' => '', 'path_php_binary' => PHP_BINARY]];
$functions = file_get_contents($root . '/lib/functions.php');
if (!is_string($functions)) {
    throw new RuntimeException('Unable to read production helpers');
}
foreach (['cacti_sizeof', 'cacti_count', 'clean_up_lines', 'array_rekey', 'read_config_option',
    'get_data_source_item_name', 'get_data_source_path', 'clean_up_path'] as $function) {
    eval(test_php_function_source($functions, $function));
}
function cacti_log($message, $output = false, $subsystem = 'SYSTEM', $level = ''): void
{
    $GLOBALS['warnings'][] = [$message, $subsystem];
}
require $root . '/lib/utility.php';
require $root . '/lib/xml.php';
require $root . '/lib/data_query.php';

$schema = file_get_contents($root . '/cacti.sql');
if (!is_string($schema)) {
    throw new RuntimeException('Unable to read production schema');
}
foreach (['data_input', 'data_input_fields', 'data_input_data', 'data_template_data', 'data_template_rrd', 'snmp_query_graph_rrd'] as $table) {
    if (preg_match('/CREATE TABLE `?' . $table . '`? \((.*?)\) ENGINE=.*?;/s', $schema, $match) !== 1) {
        throw new RuntimeException('Production table definition missing');
    }
    $db->exec($match[0]);
}
$db->exec("UPDATE host SET poller_id=1,snmp_version=2,snmp_community='fixture',snmp_username='',snmp_password='',snmp_auth_protocol='',snmp_priv_passphrase='',snmp_priv_protocol='',snmp_context='',snmp_engine_id='',snmp_port=161,snmp_timeout=500 WHERE id=7");
$db->exec("UPDATE data_local SET snmp_query_id=123,snmp_index='5',data_template_id=17 WHERE id=11");
$db->exec("INSERT INTO data_input(id,type_id) VALUES(1,3)");
$db->exec("INSERT INTO data_template_data(id,local_data_id,data_template_id,data_input_id,active,rrd_step,data_source_path) VALUES(20,0,17,1,'on',300,'/owned/template.rrd'),(21,11,17,1,'on',300,'/owned/fixture.rrd')");
$db->exec("INSERT INTO data_input_fields(id,data_input_id,type_code) VALUES(1,1,'index_type'),(2,1,'index_value'),(3,1,'output_type')");
$db->exec("INSERT INTO data_input_data(data_input_field_id,data_template_data_id,value) VALUES(1,21,'ifIndex'),(2,21,'5'),(3,21,'13')");
$db->exec("INSERT INTO data_template_rrd(id,local_data_template_rrd_id,local_data_id,data_template_id,data_source_name) VALUES(31,1,11,17,'traffic_in'),(32,2,11,17,'traffic_out')");
$db->exec("INSERT INTO snmp_query_graph_rrd(snmp_query_graph_id,data_template_id,data_template_rrd_id,snmp_field_name) VALUES(13,17,1,'ifInOctets'),(14,17,2,'ifOutOctets')");
$xml = file_get_contents($root . '/resource/snmp_queries/interface.xml');
if (!is_string($xml)) {
    throw new RuntimeException('Production query XML missing');
}
// Use the real parsed producer format through the existing validated query cache.
$data_query_xml_arrays = [123 => xml2array($xml)];
$warnings = [];
$result = ['selected' => [], 'committed' => [], 'queries' => [], 'parameters' => []];
foreach (['13', 'empty', 'missing', '1 OR 1=1', ' 13', '-1', '1e3'] as $case) {
    $db->exec('DELETE FROM data_input_data WHERE data_input_field_id=3');
    if ($case !== 'missing') {
        $query = $db->prepare('INSERT INTO data_input_data(data_input_field_id,data_template_data_id,value) VALUES(3,21,?)');
        $query->execute([$case === 'empty' ? '' : $case]);
    }
    $data = $db->query('SELECT * FROM data_local WHERE id=11')->fetch(PDO::FETCH_ASSOC);
    $output_queries = [];
    $items = update_poller_cache($data);
    $result['queries'][$case] = count($output_queries);
    if ($output_queries !== []) {
        $result['parameters'][$case] = $output_queries[0][1];
    }
    $db->exec('DELETE FROM poller_item');
    poller_update_poller_cache_from_buffer([11], $items, 1);
    $result['selected'][$case] = $db->query('SELECT rrd_name FROM poller_item ORDER BY rrd_name')->fetchAll(PDO::FETCH_COLUMN);
    $db->exec('DELETE FROM poller_item');
    $db->exec("INSERT INTO poller_item(local_data_id,poller_id,host_id,rrd_name,arg1,present) VALUES(11,1,7,'stale','stale',1)");
    update_poller_cache($data, true);
    $result['committed'][$case] = $db->query('SELECT rrd_name FROM poller_item ORDER BY rrd_name')->fetchAll(PDO::FETCH_COLUMN);
}
$result['warnings'] = $warnings;
$GLOBALS['nativeChildCoverageMarkers'] = ['selection-valid', 'selection-empty', 'selection-missing', 'malformed-refused', 'commit-cleanup', 'operator-warning'];
echo json_encode($result, JSON_THROW_ON_ERROR);
