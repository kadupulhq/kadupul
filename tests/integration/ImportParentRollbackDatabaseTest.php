<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/*
 * Runs the real import_xml_data(), the Data Template and Data Input Method
 * importers and the transaction helpers against tables built from cacti.sql,
 * so a child hash that does not parse can be checked against what the
 * database keeps afterwards. Compatibility repair cases also exercise the real
 * repair helper, XML parser, and signed-package reader.
 */

namespace ImportParentRollbackDatabase;

use PDO;
use PDOException;

require_once dirname(__DIR__) . '/Helpers/PhpSource.php';

$root = dirname(__DIR__, 2);

foreach (array(
    '/lib/import.php' => array(
        'import_xml_data', 'xml_to_data_template', 'xml_to_data_input_method', 'xml_to_data_query', 'xml_detect_ignorable_hash_cache',
        'compare_data', 'resolve_hash_to_id', 'parse_xml_hash', 'check_hash_type', 'check_hash_version',
        'xml_character_decode', 'import_invalid_data_source_item', 'import_validate_data_source_item', 'import_is_base64_encoded', 'import_package', 'import_read_package_data',
        'import_package_get_public_key', 'import_package_get_details', 'is_cacti_public_key', 'xml_to_array',
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
    'POLLER_VERBOSITY_DEVDBG' => 5,
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
    'snmp_query_graph_rrd',
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

function db_fetch_assoc_prepared($sql, $params = array())
{
    return query($sql, $params)->fetchAll(PDO::FETCH_ASSOC);
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

$xmlSource = file_get_contents($root . '/lib/xml.php');
foreach (array('xml2array', 'get_children') as $name) {
    eval('namespace ImportParentRollbackDatabase\\Parser; ' . \test_php_function_source($xmlSource, $name));
}
eval('namespace ImportParentRollbackDatabase\\Parser; function cacti_count($values) { return count($values); }');

function xml2array($data, bool $strict = false)
{
    return isset($GLOBALS['import_rollback_xml'])
        ? $GLOBALS['import_rollback_xml']
        : Parser\xml2array($data, $strict);
}

function cacti_input_string_is_safe($input_string)
{
    return !preg_match('/[;&|`$]/', $input_string);
}

function generate_data_input_field_sequences($input_string, $data_input_id) {}

$repairSource = \test_php_function_source(file_get_contents($root . '/lib/functions.php'), 'repair_system_data_input_methods');
eval('namespace ' . __NAMESPACE__ . '; ' . str_replace('function repair_system_data_input_methods(', 'function repair_legacy_fields(', $repairSource));

function repair_system_data_input_methods($step = 'import')
{
    $GLOBALS['import_rollback_repairs']++;
    repair_legacy_fields($step);
}

function api_plugin_hook_function($name, $parameters = '')
{
    return $parameters;
}

function cacti_log($message, $output = false, $environ = 'CMDPHP', $level = '')
{
    $GLOBALS['import_rollback_logs'][] = $message;
}

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
        $rows[$table] = query('SELECT * FROM `' . $table . '` ORDER BY 1, 2')->fetchAll(PDO::FETCH_ASSOC);
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
    unset($GLOBALS['import_rollback_xml']);
    $GLOBALS['preview_only']                   = false;
    $GLOBALS['import_debug_info']              = array();
    $GLOBALS['import_messages']                = array();
    $GLOBALS['legacy_template']                = false;
    $GLOBALS['ignorable_hashes']               = array();
    $GLOBALS['import_rollback_saves']          = array();
    $GLOBALS['import_rollback_repairs']        = 0;
    $GLOBALS['import_rollback_logs']           = array();
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

// Test-only trust anchor; package and per-file signatures use real OpenSSL verification.
function get_public_key_sha256(): string
{
    return trim($GLOBALS['import_preview_public_key']);
}

function get_public_key_sha1(): string
{
    return '';
}

function get_public_key_kadupul(): string
{
    return '';
}

function __($message, ...$args): string
{
    return $args ? vsprintf($message, $args) : $message;
}

function legacy_port_xml(): string
{
    $method = '3eb92bb845b9660a7445cf9740726522';
    $good = 'fc64b99742ec417cc424dbf8c7692d36';
    $bad = str_repeat('a', 32);
    query("INSERT INTO data_input (id, hash, name, input_string, type_id) VALUES (1, ?, 'Get SNMP Data', '', 2)", array($method));
    foreach (array(40 => $good, 255 => $bad) as $id => $hash) {
        query("INSERT INTO data_input_fields (id, hash, data_input_id, name, data_name, input_output, update_rra, sequence, type_code, regexp_match, allow_nulls)
            VALUES (?, ?, 1, 'SNMP Port', 'snmp_port', 'in', '', 0, 'snmp_port', '', '')", array($id, $hash));
    }
    // Both repair branches: preserve the canonical value on collision, reassign a lone legacy mapping.
    query("INSERT INTO data_input_data (data_input_field_id, data_template_data_id, t_value, value)
        VALUES (40, 1, '', '161'), (255, 1, '', '162'), (255, 2, '', '1161')");
    query("INSERT INTO data_template_rrd (id, data_input_field_id, data_source_name) VALUES (1, 255, 'port')");

    return '<cacti><hash_030103' . $method . '><name>Get SNMP Data</name><type_id>2</type_id><input_string></input_string><fields>'
        . '<hash_070103' . $bad . '><name>SNMP Port</name><data_name>snmp_port</data_name><input_output>in</input_output>'
        . '<update_rra></update_rra><sequence>0</sequence><type_code>snmp_port</type_code><regexp_match></regexp_match><allow_nulls></allow_nulls>'
        . '</hash_070103' . $bad . '></fields></hash_030103' . $method . '></cacti>';
}

function signed_preview_package(string $xml): string
{
    $key = openssl_pkey_new(array('private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA));
    if ($key === false || !openssl_sign($xml, $signature, $key, OPENSSL_ALGO_SHA256)) {
        throw new \RuntimeException('Cannot sign fixture template.');
    }
    $GLOBALS['import_preview_public_key'] = openssl_pkey_get_details($key)['key'];
    $unsigned = "<package>\n<info><name>Preview repair fixture</name></info>\n<publickey>"
        . base64_encode($GLOBALS['import_preview_public_key']) . "</publickey>\n<files><file><name>template.xml</name><data>"
        . base64_encode($xml) . '</data><filesignature>' . base64_encode($signature) . "</filesignature></file></files>\n   <signature></signature>\n</package>\n";
    if (!openssl_sign($unsigned, $packageSignature, $key, OPENSSL_ALGO_SHA256)) {
        throw new \RuntimeException('Cannot sign fixture package.');
    }
    $package = str_replace('<signature></signature>', '<signature>' . base64_encode($packageSignature) . '</signature>', $unsigned);
    $path = tempnam(sys_get_temp_dir(), 'kadupul-preview-repair-');
    if ($path === false || file_put_contents($path, gzencode($package)) === false) {
        throw new \RuntimeException('Cannot write fixture package.');
    }

    return $path;
}

test('legacy field and mapping repairs run only on real imports', function (bool $package, bool $preview) {
    $xml = legacy_port_xml();
    $before = written();
    $GLOBALS['preview_only'] = $preview;
    $path = null;
    try {
        if ($package) {
            $path = signed_preview_package($xml);
            $result = import_package($path, preview: $preview, limitex: false);
            expect($result)->toBeArray(implode(PHP_EOL, $GLOBALS['import_rollback_logs']));
            $result = $result[0];
        } else {
            $result = import_xml_data($xml, false, 1);
        }
        expect($result)->toBeArray();
        $after = written();
        if ($preview) {
            expect($after)->toBe($before);
            expect($GLOBALS['import_rollback_repairs'])->toBe(0);
            expect($result['data_input_method'][0]['result'])->toBe('preview');
            expect($result['data_input_method'][0]['differences'])->toContain(
                'Legacy system input fields and mappings will be checked for repairs only when the template is imported.'
            );
        } else {
            expect($GLOBALS['import_rollback_repairs'])->toBe(1);
            expect($result['data_input_method'][0]['result'])->toBe('success');
            expect(array_column($after['data_input_fields'], 'id'))->toBe(array(40));
            expect(array_column($after['data_input_data'], 'data_input_field_id'))->toBe(array(40, 40));
            expect(array_column($after['data_input_data'], 'value'))->toBe(array('161', '1161'));
            expect(array_column($after['data_template_rrd'], 'data_input_field_id'))->toBe(array(40));
        }
    } finally {
        if ($path !== null) {
            unlink($path);
        }
        unset($GLOBALS['import_preview_public_key']);
    }
})->with(array(
    'plain preview' => array(false, true),
    'signed package preview' => array(true, true),
    'plain import' => array(false, false),
    'signed package import' => array(true, false),
));

test('a data template preview also defers system input field repairs', function () {
    legacy_port_xml();
    $before = written();
    $GLOBALS['preview_only'] = true;
    $result = import(template(array('hash_080103' . md5('preview item') => item('preview'))));

    expect($result)->toBeArray();
    expect(written())->toBe($before);
    expect($GLOBALS['import_rollback_repairs'])->toBe(0);
    expect($result['data_template'][0]['differences'])->toContain(
        'Legacy system input fields and mappings will be checked for repairs only when the template is imported.'
    );
});

test('the shipped interface query reimports without duplicate identities and follows suggested-value replacement policy', function (bool $replace) {
    $root = dirname(__DIR__, 2);
    $package = simplexml_load_string(gzdecode(file_get_contents($root . '/install/templates/Generic_SNMP_Device.xml.gz')));
    expect($package)->not->toBeFalse();
    $template = null;
    foreach ($package->files->file as $file) {
        if ((string) $file->name === 'Generic_SNMP_Device.xml') {
            $template = base64_decode((string) $file->data, true);
        }
    }
    expect($template)->toBeString();
    $objects = Parser\xml2array($template, true);
    $queries = array_filter($objects, static fn($object) => ($object['xml_path'] ?? '') === '<path_cacti>/resource/snmp_queries/interface.xml');
    expect($queries)->toHaveCount(1);
    $fullHash = array_key_first($queries);
    $hash = parse_xml_hash($fullHash)['hash'];
    $definition = $queries[$fullHash];

    // Native dependency resolution receives the package's existing identities.
    $cache = array();
    $next = 100;
    $remember = static function ($value) use (&$remember, &$cache, &$next): void {
        if (is_array($value)) {
            foreach ($value as $key => $child) {
                $remember($key);
                $remember($child);
            }
        } elseif (is_string($value) && preg_match('/^hash_[a-f0-9]{38}$/D', $value)) {
            $parsed = parse_xml_hash($value);
            expect($parsed)->toBeArray();
            $cache[$parsed['type']][$parsed['hash']] ??= ++$next;
        }
    };
    $remember($objects);
    unset($cache['data_query'][$hash]);
    $GLOBALS['config']['base_path'] = $root;
    $GLOBALS['fields_data_query_edit'] = array_fill_keys(array('name', 'description', 'xml_path', 'data_input_id'), array());
    $GLOBALS['fields_data_query_item_edit'] = array_fill_keys(array('name', 'graph_template_id'), array());
    $files = array();
    expect(xml_to_data_query($hash, $definition, $cache, $files))->toBeArray();
    expect($files[$root . '/resource/snmp_queries/interface.xml'])->toBe('found');
    expect($GLOBALS['import_debug_info']['result'])->toBe('success');
    $tables = array('snmp_query', 'snmp_query_graph', 'snmp_query_graph_rrd', 'snmp_query_graph_sv', 'snmp_query_graph_rrd_sv');
    $snapshot = static function () use ($tables): array {
        $rows = array();
        foreach ($tables as $table) {
            $rows[$table] = query('SELECT * FROM `' . $table . '` ORDER BY 1, 2')->fetchAll(PDO::FETCH_ASSOC);
        }
        return $rows;
    };
    $first = $snapshot();
    expect($first['snmp_query'])->toHaveCount(1);
    expect($first['snmp_query_graph'])->toHaveCount(count($definition['graphs']));
    expect($first['snmp_query_graph_rrd'])->not->toBeEmpty();
    expect(xml_to_data_query($hash, $definition, $cache, $files))->toBeArray();
    expect($snapshot())->toBe($first);

    $graph = (int) $first['snmp_query_graph'][0]['id'];
    $custom = md5('operator additional interface title');
    query('INSERT INTO snmp_query_graph_sv (hash, snmp_query_graph_id, sequence, field_name, text) VALUES (?, ?, 999, ?, ?)', array($custom, $graph, 'title', 'Operator interface label'));
    expect(xml_to_data_query($hash, $definition, $cache, $files, $replace))->toBeArray();
    expect((int) query('SELECT COUNT(*) FROM snmp_query_graph_sv WHERE hash = ?', array($custom))->fetchColumn())->toBe($replace ? 0 : 1);
    expect(query('SELECT id FROM snmp_query WHERE hash = ?', array($hash))->fetchColumn())->toBe($first['snmp_query'][0]['id']);
    expect(query('SELECT id FROM snmp_query_graph ORDER BY id')->fetchAll(PDO::FETCH_COLUMN))->toBe(array_column($first['snmp_query_graph'], 'id'));
})->with(array(false, true));
