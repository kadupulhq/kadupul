<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/*
 * Runs the real import_xml_data(), the Data Template and Data Input Method
 * importers and the transaction helpers against tables built from cacti.sql,
 * so a child hash that does not parse can be checked against what the
 * database keeps afterwards.
 */

namespace ImportParentRollbackDatabase;

use PDO;
use PDOException;

require_once dirname(__DIR__) . '/Helpers/PhpSource.php';

$root = dirname(__DIR__, 2);

foreach (array(
    '/lib/import.php' => array(
        'import_xml_data', 'xml_to_data_template', 'xml_to_data_input_method', 'xml_detect_ignorable_hash_cache',
        'compare_data', 'resolve_hash_to_id', 'parse_xml_hash', 'check_hash_type', 'check_hash_version',
        'xml_character_decode', 'import_is_base64_encoded',
    ),
    '/lib/database.php' => array('db_begin_transaction', 'db_commit_transaction', 'db_rollback_transaction'),
    '/lib/utility.php'  => array('update_replication_crc'),
) as $path => $names) {
    $source = file_get_contents($root . $path);

    foreach ($names as $name) {
        eval('namespace ' . __NAMESPACE__ . '; ' . \test_php_function_source($source, $name)); // nosemgrep: php.lang.security.eval-use.eval-use
    }
}

$arrays = file_get_contents($root . '/include/global_arrays.php');
preg_match('/^\$cacti_version_codes = (array\(.*?^\));\n/ms', $arrays, $versions);
preg_match('/^\$hash_type_codes = (array\(.*?^\));\n/ms', $arrays, $types);

// test-only eval of array literals read from this repository
$GLOBALS['import_rollback_codes'] = array(
    'versions' => eval('return ' . $versions[1] . ';'), // nosemgrep: php.lang.security.eval-use.eval-use
    'types'    => eval('return ' . $types[1] . ';'), // nosemgrep: php.lang.security.eval-use.eval-use
);

foreach (array(
    'CACTI_VERSION'          => trim(file_get_contents($root . '/include/cacti_version')),
    'POLLER_VERBOSITY_LOW'    => 2,
    'POLLER_VERBOSITY_MEDIUM' => 3,
    'POLLER_VERBOSITY_HIGH'   => 4,
) as $constant => $value) {
    if (!defined($constant)) {
        define($constant, $value);
    }
}

const WRITTEN = array(
    'data_template', 'data_template_data', 'data_template_rrd', 'data_input_data',
    'poller_data_template_field_mappings', 'data_input', 'data_input_fields', 'settings',
);

/* every table the hash cache query reads, plus the ones the two importers write */
const TABLES = array(
    'graph_templates', 'graph_templates_item', 'graph_template_input', 'data_template', 'data_template_data',
    'data_template_rrd', 'data_input_data', 'host_template', 'data_input', 'data_input_fields', 'snmp_query',
    'snmp_query_graph', 'snmp_query_graph_sv', 'snmp_query_graph_rrd_sv', 'graph_templates_gprint', 'cdef',
    'cdef_items', 'vdef', 'vdef_items', 'data_source_profiles', 'data_source_profiles_rra',
    'poller_data_template_field_mappings', 'settings',
);

function pdo()
{
    return $GLOBALS['database_sessions']['import:3306:rollback'];
}

function query($sql, $params = array())
{
    $statement = pdo()->prepare($sql);
    $statement->execute($params);

    return $statement;
}

function db_fetch_cell_prepared($sql, $params = array())
{
    return query($sql, $params)->fetchColumn();
}

function db_fetch_cell($sql)
{
    return db_fetch_cell_prepared($sql);
}

function db_fetch_row_prepared($sql, $params = array())
{
    $row = query($sql, $params)->fetch(PDO::FETCH_ASSOC);

    return $row === false ? array() : $row;
}

function db_fetch_assoc($sql)
{
    return query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

function db_execute_prepared($sql, $params = array())
{
    query($sql, $params);

    return true;
}

function db_execute($sql)
{
    return db_execute_prepared($sql);
}

/* REPLACE keeps the row sql_save() would leave: an insert for id 0, the same row otherwise */
function sql_save($save, $table, $key_cols = 'id', $autoinc = true)
{
    $columns = array_keys($save);

    query(
        'REPLACE INTO `' . $table . '` (`' . implode('`, `', $columns) . '`) VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')',
        array_values($save)
    );

    $GLOBALS['import_rollback_saves'][] = $table;

    return ($autoinc && empty($save['id'])) ? (int) pdo()->lastInsertId() : (isset($save['id']) ? $save['id'] : true);
}

function xml2array($data)
{
    return $GLOBALS['import_rollback_xml'];
}

function cacti_input_string_is_safe($input_string)
{
    return !preg_match('/[;&|`$]/', $input_string);
}

function generate_data_input_field_sequences($input_string, $data_input_id) {}

function repair_system_data_input_methods($step = 'import')
{
    $GLOBALS['import_rollback_repairs']++;
}

function api_plugin_hook_function($name, $parameters = '')
{
    return $parameters;
}

function cacti_log($message, $output = false, $environ = 'CMDPHP', $level = '') {}

function cacti_sizeof($array)
{
    return is_array($array) ? count($array) : 0;
}

function cacti_count($array)
{
    return is_array($array) ? count($array) : 0;
}

function cacti_version_compare($version1, $version2, $operator = '>')
{
    return version_compare($version1, $version2, $operator);
}

function import(array $xml)
{
    $GLOBALS['import_rollback_xml'] = $xml;

    $data = '<cacti/>';

    return import_xml_data($data, false, 1);
}

/** @return array<string, array<int, array<string, mixed>>> */
function written()
{
    $rows = array();

    foreach (WRITTEN as $table) {
        $rows[$table] = query('SELECT * FROM `' . $table . '` ORDER BY 1')->fetchAll(PDO::FETCH_ASSOC);
    }

    return $rows;
}

function nothing_written()
{
    return array_fill_keys(WRITTEN, array());
}

function item($name)
{
    return array('data_source_name' => $name, 'rrd_minimum' => '0', 'rrd_maximum' => 'U', 'data_source_type_id' => '1', 'rrd_heartbeat' => '600', 'data_input_field_id' => '0');
}

function field($name)
{
    return array('name' => ucfirst($name), 'data_name' => $name, 'input_output' => 'in', 'update_rra' => '', 'sequence' => '1', 'type_code' => '', 'regexp_match' => '', 'allow_nulls' => '');
}

function template(array $items)
{
    return array('hash_010103' . md5('rollback template') => array(
        'name'  => 'Rollback Template',
        'ds'    => array('name' => '|host_description| - Rollback', 'data_source_path' => '', 'data_input_id' => '0', 'rrd_step' => '300', 'active' => 'on'),
        'items' => $items,
        'data'  => array(),
    ));
}

function method(array $fields)
{
    return array('hash_030103' . md5('rollback method') => array(
        'name'         => 'Rollback Method',
        'type_id'      => '1',
        'input_string' => '/usr/bin/true <host>',
        'fields'       => $fields,
    ));
}

beforeEach(function () {
    if (!getenv('KADUPUL_TEST_MYSQL_DSN')) {
        $this->markTestSkipped('KADUPUL_TEST_MYSQL_DSN is required for database contracts');
    }

    $schema = file_get_contents(dirname(__DIR__, 2) . '/cacti.sql');

    $GLOBALS['database_hostname'] = 'import';
    $GLOBALS['database_port']     = '3306';
    $GLOBALS['database_default']  = 'rollback';
    $GLOBALS['database_sessions'] = array('import:3306:rollback' => new PDO(
        getenv('KADUPUL_TEST_MYSQL_DSN'),
        getenv('KADUPUL_TEST_MYSQL_USER') ?: 'root',
        getenv('KADUPUL_TEST_MYSQL_PASSWORD') ?: '',
        array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION)
    ));

    /* the modes db_connect_real() removes, so a blank numeric column is stored as 0 as it is in production */
    $modes = array_diff(
        explode(',', (string) db_fetch_cell('SELECT @@sql_mode')),
        array('STRICT_TRANS_TABLES', 'STRICT_ALL_TABLES', 'TRADITIONAL', 'NO_ZERO_DATE', 'NO_ZERO_IN_DATE', 'ONLY_FULL_GROUP_BY', 'NO_AUTO_VALUE_ON_ZERO')
    );
    query('SET SESSION sql_mode = ?', array(implode(',', $modes)));

    foreach (TABLES as $table) {
        expect(preg_match('/^CREATE TABLE `?' . $table . '`? \(.*?^\)[^;]*;/ms', $schema, $create))->toBe(1);
        query('DROP TABLE IF EXISTS `' . $table . '`');
        query($create[0]);
    }

    query("INSERT INTO data_source_profiles (id, hash, name, step, heartbeat, `default`) VALUES (1, ?, 'Rollback', 300, 600, 'on')", array(md5('rollback profile')));

    $libraryDir = sys_get_temp_dir() . '/import-parent-rollback-lib';

    if (!is_dir($libraryDir)) {
        mkdir($libraryDir, 0700, true);
    }

    /* import_xml_data() includes lib/xml.php; xml2array() is defined above */
    file_put_contents($libraryDir . '/xml.php', "<?php\n");

    $GLOBALS['config']                         = array('library_path' => $libraryDir);
    $GLOBALS['hash_type_codes']                = $GLOBALS['import_rollback_codes']['types'];
    $GLOBALS['cacti_version_codes']            = $GLOBALS['import_rollback_codes']['versions'];
    $GLOBALS['struct_data_source']             = array_fill_keys(array('name', 'data_source_path', 'data_input_id', 'data_source_profile_id', 'rrd_step', 'active'), array());
    $GLOBALS['struct_data_source_item']        = array_fill_keys(array('data_source_name', 'rrd_minimum', 'rrd_maximum', 'data_source_type_id', 'rrd_heartbeat', 'data_input_field_id'), array());
    $GLOBALS['fields_data_input_edit']         = array_fill_keys(array('name', 'type_id', 'input_string'), array());
    $GLOBALS['fields_data_input_field_edit']   = array_fill_keys(array('name', 'data_name', 'input_output', 'update_rra', 'sequence', 'type_code', 'regexp_match', 'allow_nulls'), array());
    $GLOBALS['fields_data_input_field_edit_1'] = array();
    $GLOBALS['preview_only']                   = false;
    $GLOBALS['import_debug_info']              = array();
    $GLOBALS['import_messages']                = array();
    $GLOBALS['legacy_template']                = false;
    $GLOBALS['ignorable_hashes']               = array();
    $GLOBALS['import_rollback_saves']          = array();
    $GLOBALS['import_rollback_repairs']        = 0;
});

afterEach(function () {
    if (!isset($GLOBALS['database_sessions']['import:3306:rollback'])) {
        return;
    }

    if (pdo()->inTransaction()) {
        pdo()->rollBack();
    }

    foreach (TABLES as $table) {
        query('DROP TABLE IF EXISTS `' . $table . '`');
    }

    unset($GLOBALS['database_sessions']);
});

test('every table the two importers write uses a transactional engine', function () {
    $engines = query('SELECT table_name, engine FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN (' . implode(', ', array_fill(0, count(WRITTEN), '?')) . ')', WRITTEN)->fetchAll(PDO::FETCH_KEY_PAIR);

    expect($engines)->toHaveCount(count(WRITTEN));
    expect(array_unique(array_values($engines)))->toBe(array('InnoDB'));
});

test('a data template whose first item hash does not parse leaves no rows', function () {
    expect(import(template(array('item_not_a_hash' => item('first')))))->toBeFalse();

    expect($GLOBALS['import_rollback_saves'])->toBe(array('data_template', 'data_template_data'));
    expect($GLOBALS['import_messages'])->toContain(7);
    expect(written())->toBe(nothing_written());
});

test('a data template whose later item hash does not parse removes the items already saved', function () {
    $items = array(
        'hash_080103' . md5('rollback item one') => item('one'),
        'hash_080103' . md5('rollback item two') => item('two'),
        'item_not_a_hash'                        => item('three'),
    );

    expect(import(template($items)))->toBeFalse();

    expect($GLOBALS['import_rollback_saves'])->toBe(array('data_template', 'data_template_data', 'data_template_rrd', 'data_template_rrd'));
    expect(written())->toBe(nothing_written());
});

test('a valid data template is saved with its items', function () {
    $items = array(
        'hash_080103' . md5('rollback item one') => item('one'),
        'hash_080103' . md5('rollback item two') => item('two'),
    );

    expect(import(template($items)))->toBeArray();

    $rows = written();

    expect(array_column($rows['data_template'], 'hash'))->toBe(array(md5('rollback template')));
    expect(array_column($rows['data_template_data'], 'name'))->toBe(array('|host_description| - Rollback'));
    expect(array_column($rows['data_template_rrd'], 'data_source_name'))->toBe(array('one', 'two'));
    expect(array_unique(array_column($rows['data_template_rrd'], 'data_template_id')))->toBe(array($rows['data_template'][0]['id']));
    expect($GLOBALS['import_rollback_repairs'])->toBe(1);
    expect(pdo()->inTransaction())->toBeFalse();
});

test('a data input method whose first field hash does not parse leaves no rows', function () {
    expect(import(method(array('field_not_a_hash' => field('first')))))->toBeFalse();

    expect($GLOBALS['import_rollback_saves'])->toBe(array('data_input'));
    expect(written())->toBe(nothing_written());
});

test('a data input method whose later field hash does not parse removes the fields already saved', function () {
    $fields = array(
        'hash_070103' . md5('rollback field one') => field('one'),
        'hash_070103' . md5('rollback field two') => field('two'),
        'field_not_a_hash'                        => field('three'),
    );

    expect(import(method($fields)))->toBeFalse();

    expect($GLOBALS['import_rollback_saves'])->toBe(array('data_input', 'data_input_fields', 'data_input_fields'));
    expect(written())->toBe(nothing_written());
});

test('a valid data input method is saved with its fields', function () {
    $fields = array(
        'hash_070103' . md5('rollback field one') => field('one'),
        'hash_070103' . md5('rollback field two') => field('two'),
    );

    expect(import(method($fields)))->toBeArray();

    $rows = written();

    expect(array_column($rows['data_input'], 'hash'))->toBe(array(md5('rollback method')));
    expect(array_column($rows['data_input_fields'], 'data_name'))->toBe(array('one', 'two'));
    expect(array_column($rows['settings'], 'name'))->toBe(array('poller_replicate_data_input_crc', 'poller_replicate_data_input_fields_crc'));
    expect($GLOBALS['import_rollback_repairs'])->toBe(1);
    expect(pdo()->inTransaction())->toBeFalse();
});

/* PDO transactions do not nest: inside a caller's transaction the import stops before it writes, and the caller decides */
test('an import inside a transaction the caller rolls back writes nothing', function () {
    pdo()->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);

    expect(db_begin_transaction())->toBeTrue();
    query("INSERT INTO settings (name, value) VALUES ('outer', 'kept until rollback')");

    $items = array('hash_080103' . md5('rollback item one') => item('one'));

    expect(fn() => import(template($items)))->toThrow(PDOException::class, 'There is already an active transaction');
    expect($GLOBALS['import_rollback_saves'])->toBe(array());

    expect(db_rollback_transaction())->toBeTrue();
    expect(written())->toBe(nothing_written());
});

test('an import that fails inside a transaction the caller commits leaves only the caller rows', function () {
    pdo()->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);

    expect(db_begin_transaction())->toBeTrue();
    query("INSERT INTO settings (name, value) VALUES ('outer', 'committed')");

    expect(fn() => import(template(array('item_not_a_hash' => item('first')))))->toThrow(PDOException::class, 'There is already an active transaction');
    expect($GLOBALS['import_rollback_saves'])->toBe(array());

    expect(db_commit_transaction())->toBeTrue();

    $expected = nothing_written();
    $expected['settings'] = array(array('name' => 'outer', 'value' => 'committed'));

    expect(written())->toBe($expected);
});
