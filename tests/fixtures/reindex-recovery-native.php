<?php
declare(strict_types=1);
// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

if (PHP_SAPI !== 'cli') { exit(1); }
$root = dirname(__DIR__, 2);
require $root . '/lib/data_query.php';
require $root . '/lib/data_query_runtime.php';
require $root . '/lib/database.php';
require $root . '/tests/Helpers/PhpSource.php';
eval(test_php_function_source(file_get_contents($root . '/lib/functions.php'), 'clean_up_lines'));
eval(test_php_function_source(file_get_contents($root . '/lib/functions.php'), 'array_rekey'));

function cacti_sizeof($value) { return is_array($value) ? count($value) : false; }
function cacti_count($value) { return count($value); }
function cacti_log(...$arguments) { $GLOBALS['fixture_logs'][] = $arguments[0]; }
function debug_log_insert(...$arguments) {}
function __($message, ...$arguments) { return $arguments ? vsprintf($message, $arguments) : $message; }
function __esc($message, ...$arguments) { return __($message, ...$arguments); }
function read_config_option($name) { return ''; }
function cacti_path_is_within($path, $base) { return str_starts_with(realpath($path), realpath($base) . DIRECTORY_SEPARATOR); }

final class ReindexFixtureConnection extends PDO {
	public string $failure = '';
	public function prepare(string $query, array $options = array()): PDOStatement|false {
        if (str_contains($query, 'RELEASE_LOCK') && $this->failure === 'unlock-exception') {
            throw new RuntimeException('Injected release failure');
        }
        if (str_contains($query, 'RELEASE_LOCK') && $this->failure === 'unlock-refused') {
            return parent::prepare('SELECT 0 WHERE ? IS NOT NULL', $options);
        }
        return parent::prepare($query, $options);
    }
    public function commit(): bool {
		if ($this->failure === 'commit-false') { return false; }
		if ($this->failure === 'commit') { throw new RuntimeException('Injected commit failure'); }
		return parent::commit();
	}
	public function beginTransaction(): bool {
		if ($this->failure === 'begin') { return false; }
		return parent::beginTransaction();
	}
	public function exec(string $statement): int|false {
		if ($this->failure === 'release' && str_starts_with($statement, 'RELEASE SAVEPOINT')) {
			$this->failure = '';
			throw new RuntimeException('Injected savepoint release failure');
		}
		return parent::exec($statement);
	}
}

$scenario = $argv[1] ?? '';
$database_hostname = getenv('BOOST_DB_HOST') ?: '127.0.0.1';
$database_port = getenv('BOOST_DB_PORT') ?: '3306';
$database_default = getenv('BOOST_DB_NAME') ?: 'cacti_boost_contract';
if (!in_array($database_hostname, array('127.0.0.1', 'localhost'), true) || $database_default !== 'cacti_boost_contract') {
	throw new RuntimeException('Use the isolated reindex contract database.');
}
$dsn = "mysql:host=$database_hostname;port=$database_port;dbname=$database_default;charset=utf8mb4";
$connection = new ReindexFixtureConnection($dsn, getenv('BOOST_DB_USER') ?: 'root', getenv('BOOST_DB_PASSWORD') ?: '', array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
$connection->exec("SET SESSION sql_mode='STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION'");
$database_sessions = array("$database_hostname:$database_port:$database_default" => $connection);
$config = array('library_path' => $root . '/lib', 'base_path' => $root, 'poller_id' => 1);
$database_total_queries = 0;
$checks = 0;
$created = array();
$definitions = array();
$xml_file = null;
define('HOST_DOWN', 1);
define('DATA_INPUT_TYPE_SNMP_QUERY', 3);
define('DATA_INPUT_TYPE_SCRIPT_QUERY', 4);
define('DATA_INPUT_TYPE_QUERY_SCRIPT_SERVER', 6);
define('SQL_NO_CACHE', '');
define('POLLER_VERBOSITY_DEBUG', 5);
function check(bool $condition, string $message): void {
	$GLOBALS['checks']++;
	if (!$condition) { throw new RuntimeException($message . ' ' . ($GLOBALS['database_last_error'] ?? '') . ' ' . json_encode($GLOBALS['fixture_logs'] ?? array())); }
}
function value(string $sql) { return $GLOBALS['connection']->query($sql)->fetchColumn(); }
function seed_cache(): void {
	$GLOBALS['connection']->exec("INSERT INTO host_snmp_cache(host_id,snmp_query_id,field_name,field_value,snmp_index,oid)
		VALUES(1,1,'ifName','old','old','oid'),(2,1,'ifName','other','old','oid')");
}
function seed_sources(bool $reverse = false): void {
	$c = $GLOBALS['connection'];
	$c->exec("INSERT INTO host_snmp_query(host_id,snmp_query_id,sort_field) VALUES(1,1,'ifDescr')");
	$c->exec("INSERT INTO data_input_fields(id,data_input_id,data_name) VALUES(1,1,'index_type'),(2,1,'index_value')");
	$c->exec("INSERT INTO data_local(id,host_id,snmp_query_id,snmp_index) VALUES(1,1,1,'1'),(2,1,1,'2')");
	$c->exec('INSERT INTO data_template_data(id,local_data_id,data_input_id) VALUES(1,1,1),(2,2,1)');
	$forced = $reverse ? 2 : 1;
	$ordinary = $reverse ? 1 : 2;
	$c->exec("INSERT INTO data_input_data(data_input_field_id,data_template_data_id,value) VALUES
		(1,$forced,'ifName'),(2,$forced,'name$forced'),(1,$ordinary,'ifDescr'),(2,$ordinary,'desc$ordinary')");
	$c->exec("INSERT INTO host_snmp_cache(host_id,snmp_query_id,field_name,field_value,snmp_index,oid) VALUES
		(1,1,'ifName','name1','11','oid'),(1,1,'ifName','name2','22','oid'),
		(1,1,'ifDescr','desc1','11','oid'),(1,1,'ifDescr','desc2','22','oid')");
	$c->exec("INSERT INTO graph_local(id,host_id,snmp_query_id,snmp_index) VALUES(1,1,1,'1'),(2,1,1,'2')");
	$c->exec('INSERT INTO data_template_rrd(id,local_data_id) VALUES(1,1),(2,2)');
	$c->exec('INSERT INTO graph_templates_item(id,local_graph_id,task_item_id) VALUES(1,1,1),(2,2,2)');
	$GLOBALS['data_query_xml_arrays'][1] = array('index_order' => 'ifName:ifDescr', 'index_order_type' => 'alphanumeric',
		'fields' => array('ifName' => array('direction' => 'input'), 'ifDescr' => array('direction' => 'input')));
}
$coverage_file = getenv('REINDEX_COVERAGE_FILE');
if ($coverage_file) {
	if (!function_exists('xdebug_start_code_coverage')) { throw new RuntimeException('Coverage driver missing'); }
	xdebug_start_code_coverage(XDEBUG_CC_UNUSED | XDEBUG_CC_DEAD_CODE);
}
try {
	$schema = file_get_contents($root . '/cacti.sql');
	foreach (array('host','host_snmp_cache','host_snmp_query','data_local','data_input_data','data_template_data','data_input_fields','graph_local','graph_templates_item','data_template_rrd','snmp_query','data_input','poller_item') as $table) {
		check(preg_match('/CREATE TABLE `?' . $table . '`? \(.*?\) ENGINE=InnoDB[^;]*;/s', $schema, $match) === 1, 'Shipped DDL exists');
		$definitions[$table] = $match[0];
		$connection->exec($match[0]);
		$created[] = $table;
	}
	$connection->exec("INSERT INTO host(id,hostname,description) VALUES(1,'fixture.invalid','fixture'),(2,'other.invalid','other')");
	if (str_starts_with($scenario, 'wrapper-')) {
        if ($scenario === 'wrapper-down') { $connection->exec('UPDATE host SET status=1 WHERE id=1'); }
        if ($scenario === 'wrapper-disabled') { $connection->exec("UPDATE host SET disabled='on' WHERE id=1"); }
        if ($scenario === 'wrapper-missing') { $connection->exec('DELETE FROM host WHERE id=1'); }
        check(run_data_query(1,1) === ($scenario !== 'wrapper-missing'), 'Public wrapper preserves skipped and missing host outcomes');
        check(empty($database_fail_fast), 'Public wrapper restores database policy');
    } elseif ($scenario === 'guard-missing-connection') {
        $saved = $database_sessions; $database_sessions = array();
        try {
            check(data_query_serialized(1,1, static fn() => true) === false, 'Missing serialization connection fails');
            check(data_query_transaction(1,array('host'), static fn() => true) === false, 'Missing mutation connection fails');
        } finally { $database_sessions = $saved; }
    } elseif ($scenario === 'guard-no-database') {
        $key = "$database_hostname:$database_port:$database_default";
        $database_sessions[$key] = new PDO("mysql:host=$database_hostname;port=$database_port", getenv('BOOST_DB_USER') ?: 'root', getenv('BOOST_DB_PASSWORD') ?: '');
        try { check(data_query_serialized(1,1, static fn() => true) === false, 'Missing schema refuses lock ownership'); }
        finally { $database_sessions[$key] = $connection; }
    } elseif ($scenario === 'guard-unknown-table') {
        check(data_query_transaction(1,array('unexpected_table'), static fn() => true) === false, 'Unknown table refused');
        check(!$connection->inTransaction(), 'Refused mutation leaves no transaction');
    } elseif (str_starts_with($scenario, 'unlock-')) {
        $connection->failure = $scenario;
        check(data_query_serialized(1,1, static fn() => true) === false, 'Unconfirmed release cannot report success');
        check(empty($database_fail_fast), 'Release failure restores database policy');
        $connection->failure = '';
        $name = 'reindex.' . substr(hash('sha256', $database_default . ':1:1'), 0, 48);
        $connection->prepare('SELECT RELEASE_LOCK(?)')->execute(array($name));
        check(data_query_serialized(1,1, static fn() => true), 'Next operation can complete');
    } elseif (str_starts_with($scenario, 'lock-')) {
		$calls = 0;
		$other = new PDO($dsn, getenv('BOOST_DB_USER') ?: 'root', getenv('BOOST_DB_PASSWORD') ?: '', array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
		check(data_query_serialized(1, 1, function () use (&$calls, $other, $scenario) {
			$calls++;
			check(data_query_serialized(1, 1, function () { throw new RuntimeException('Recursive work must not run'); }) === false, 'Same connection cannot recursively reindex');
			$key = $GLOBALS['database_hostname'] . ':' . $GLOBALS['database_port'] . ':' . $GLOBALS['database_default'];
			$original = $GLOBALS['database_sessions'][$key];
			$GLOBALS['database_sessions'][$key] = $other;
			try {
				check(data_query_serialized(1, 1, function () { throw new RuntimeException('Contending work must not run'); }) === false, 'Second connection excluded');
				check(data_query_serialized(1, 2, static fn() => true), 'Independent query admitted');
			} finally { $GLOBALS['database_sessions'][$key] = $original; }
			if ($scenario === 'lock-exception') { throw new RuntimeException('Fixture work failed'); }
			return $scenario !== 'lock-false';
		}) === ($scenario === 'lock-success'), 'Serialized work preserves its outcome');
		check($calls === 1, 'Only owner ran');
		check(data_query_serialized(1, 1, static fn() => true), 'Ownership released after all outcomes');
		check(empty($database_fail_fast), 'Database policy restored');
	} elseif (str_starts_with($scenario, 'pipeline-')) {
		require __DIR__ . '/reindex-pipeline-boundary.php';
		seed_sources();
		$connection->exec("INSERT INTO data_input(id,type_id) VALUES(1,3)");
		$connection->exec("INSERT INTO snmp_query(id,name,data_input_id) VALUES(1,'fixture',1)");
		$connection->exec("INSERT INTO poller_item(local_data_id,rrd_name,arg1) VALUES(1,'traffic','oid.1'),(2,'traffic','oid.2')");
		$pipeline_builds = 0; $pipeline_effects = array();
		if ($scenario === 'pipeline-cache-failure') { $connection->exec("ALTER TABLE poller_item ADD CONSTRAINT reject_new_oid CHECK(arg1 <> 'oid.22')"); }
		check(data_query_serialized(1, 1, static fn() => \ReindexPipelineFixture\run_data_query_work(1,1)) === ($scenario === 'pipeline-success'), 'Pipeline reports failed publication');
		if ($scenario !== 'pipeline-success') {
			check($pipeline_effects === array(), 'No completion hooks after failure');
			check(value("SELECT arg1 FROM poller_item WHERE local_data_id=2") === 'oid.2', 'Previous cache remains');
			check(value('SELECT snmp_index FROM data_local WHERE id=2') === '22', 'Fixture reaches post-remap publication');
			if ($scenario === 'pipeline-cache-failure') { $connection->exec('ALTER TABLE poller_item DROP CONSTRAINT reject_new_oid'); }
			$original_scenario = $scenario; $scenario = 'pipeline-success'; $pipeline_builds = 0;
			check(data_query_serialized(1, 1, static fn() => \ReindexPipelineFixture\run_data_query_work(1,1)) === true, 'Retry succeeds without a new index change');
			check($pipeline_builds === 2, 'Retry rebuilds both sources');
			$scenario = $original_scenario;
		}
		check(value("SELECT arg1 FROM poller_item WHERE local_data_id=2") === 'oid.22', 'Published cache matches persisted index');
		check($pipeline_effects === array('automation','run_data_query'), 'Completion hooks run once after confirmed success');
	} elseif (str_starts_with($scenario, 'cache-')) {
		seed_cache();
		$records = array("(1,1,'ifName','new','new','oid',1)");
		$empty = array();
		$borrowed = in_array($scenario, array('cache-borrowed-success','cache-borrowed-failure','cache-release'), true);
		$failed = in_array($scenario, array('cache-first-failure','cache-late-failure','cache-borrowed-failure','cache-commit','cache-release','cache-begin','cache-missing-host','cache-myisam','cache-temporary','cache-commit-false','cache-mark-failure','cache-delete-failure','cache-preserve-failure','cache-silent-failure'), true);
		if (in_array($scenario, array('cache-first-failure','cache-late-failure','cache-borrowed-failure','cache-silent-failure'), true)) {
			$connection->exec("ALTER TABLE host_snmp_cache ADD CONSTRAINT reject_new CHECK(field_value <> 'new')");
		}

        if ($scenario === 'cache-silent-failure') { $connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT); }
        if ($scenario === 'cache-commit-false') { $connection->failure = 'commit-false'; }
        if (in_array($scenario, array('cache-mark-failure','cache-delete-failure','cache-preserve-failure'), true)) {
            $event = $scenario === 'cache-delete-failure' ? 'DELETE' : 'UPDATE';
            $condition = $scenario === 'cache-preserve-failure' ? "OLD.host_id=1 AND NEW.present=1" : 'OLD.host_id=1';
            $connection->exec("CREATE TRIGGER reject_cache BEFORE $event ON host_snmp_cache FOR EACH ROW BEGIN IF $condition THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture'; END IF; END");
            if ($scenario === 'cache-preserve-failure') { $empty = array('ifName'); }
        }
		if ($scenario === 'cache-late-failure') {
			$records = array();
			for ($i = 0; $i < 600; $i++) { $records[] = "(1,1,'ifName','" . str_repeat('x', 500) . "','$i','oid',1)"; }
			$records[] = "(1,1,'ifName','new','new','oid',1)";
		}
		if ($scenario === 'cache-empty-types') { $empty = array('ifName'); }
		if ($scenario === 'cache-empty') { $records = array(); }
		if ($scenario === 'cache-missing-host') { $connection->exec('DELETE FROM host WHERE id=1'); }
		if ($scenario === 'cache-myisam') { $connection->exec('ALTER TABLE host_snmp_cache CONVERT TO CHARACTER SET latin1, ENGINE=MyISAM'); }
		if ($scenario === 'cache-temporary') { $connection->exec(str_replace('CREATE TABLE', 'CREATE TEMPORARY TABLE', $definitions['host_snmp_cache'])); }
		if ($borrowed) {
			$connection->beginTransaction();
			$connection->exec("UPDATE host SET description='caller work' WHERE id=2");
		}
		if (in_array($scenario, array('cache-commit','cache-release','cache-begin'), true)) { $connection->failure = substr($scenario, 6); }
		check(data_query_update_host_cache_from_buffer(1, 1, $records, $empty) === !$failed, 'Cache replacement outcome');
		$connection->failure = '';
		if ($scenario === 'cache-temporary') { $connection->exec('DROP TEMPORARY TABLE host_snmp_cache'); }
		check((int)value('SELECT COUNT(*) FROM host_snmp_cache WHERE host_id=2') === 1, 'Other host retained');
		check((int)value("SELECT COUNT(*) FROM host_snmp_cache WHERE host_id=1 AND snmp_index='old'") === (int)($failed || $scenario === 'cache-empty-types'), 'Old cache preserved exactly when required');
		if ($borrowed) {
			check($connection->inTransaction(), 'Caller still owns its transaction');
			check(value('SELECT description FROM host WHERE id=2') === 'caller work', 'Earlier caller work retained');
			$connection->rollBack();
			check(value('SELECT description FROM host WHERE id=2') === 'other', 'Caller can roll back');
		}
		if (in_array($scenario, array('cache-first-failure','cache-late-failure','cache-borrowed-failure','cache-silent-failure'), true)) {
			$connection->exec('ALTER TABLE host_snmp_cache DROP CONSTRAINT reject_new');
			check(data_query_update_host_cache_from_buffer(1, 1, $records, $empty) === true, 'Replacement retry succeeds');
			check((int)value('SELECT COUNT(*) FROM host_snmp_cache WHERE host_id=1') === count($records), 'Retry publishes every row');
		}
	} elseif ($scenario === 'graphs-batch' || $scenario === 'graphs-shared') {
        seed_sources();
        if ($scenario === 'graphs-shared') {
            $connection->exec('INSERT INTO graph_templates_item(id,local_graph_id,task_item_id) VALUES(3,1,2)');
            check(data_query_remap_indexes(array(2,1)), 'Shared graph remap succeeds');
            check(value('SELECT snmp_index FROM graph_local WHERE id=1') === '1', 'Last supplied source wins');
            check(data_query_remap_indexes(array(1,2)), 'Reversed graph remap succeeds');
            check(value('SELECT snmp_index FROM graph_local WHERE id=1') === '2', 'Supplied ordering preserved');
        } else {
            $connection->beginTransaction();
            for ($id=3; $id<=1001; $id++) {
                $connection->exec("INSERT INTO data_local(id,host_id,snmp_query_id,snmp_index) VALUES($id,1,1,'$id')");
                $connection->exec("INSERT INTO graph_local(id,host_id,snmp_query_id,snmp_index) VALUES($id,1,1,'old')");
                $connection->exec("INSERT INTO data_template_rrd(id,local_data_id) VALUES($id,$id)");
                $connection->exec("INSERT INTO graph_templates_item(id,local_graph_id,task_item_id) VALUES($id,$id,$id)");
            }
            $connection->commit();
            $before = $database_total_queries; $started = hrtime(true);
            $baseline = getenv('REINDEX_GRAPH_BASELINE');
            if ($baseline) {
                $source = file_get_contents($baseline);
                check(is_string($source), 'Baseline source readable');
                eval('namespace ReindexGraphBaseline;' . test_php_function_source($source, 'data_query_remap_indexes'));
                \ReindexGraphBaseline\data_query_remap_indexes(range(1,1001));
            } else {
                check(data_query_remap_indexes(range(1,1001)), 'Batched graph remap succeeds');
                check($database_total_queries - $before === 6, '1001 sources use three reads and three writes');
            }
            $GLOBALS['benchmark'] = array('queries' => $database_total_queries - $before, 'elapsed_ms' => (hrtime(true)-$started)/1e6);
            check((int)value('SELECT COUNT(*) FROM graph_local WHERE BINARY snmp_index=BINARY CAST(id AS CHAR)') === 1001, 'Every graph mapped');
            $before = $database_total_queries;
            check(data_query_remap_indexes(array()), 'Empty remap succeeds');
            check($database_total_queries === $before, 'Empty remap issues no queries');
        }
    } elseif (str_starts_with($scenario, 'mapping-')) {
        seed_sources();
        $definition = $data_query_xml_arrays[1];
        if ($scenario === 'mapping-no-order') { unset($definition['index_order']); }
        if ($scenario === 'mapping-empty') { $connection->exec('DELETE FROM data_local'); }
        if ($scenario === 'mapping-recovered') { $connection->exec("UPDATE data_local SET snmp_index='22', orphan=1 WHERE id=2"); }
        if (in_array($scenario, array('mapping-orphan','mapping-transient'), true)) {
            $connection->exec("DELETE FROM host_snmp_cache WHERE snmp_index='22'");
            if ($scenario === 'mapping-transient') { $definition['index_transient'] = 'true'; }
        }
        $result = data_query_reindex_local(1,1,$definition);
        check(is_array($result), 'Mapping edge case completes');
        if ($scenario === 'mapping-empty') { check($result[4] === array(), 'Empty selection stays empty'); }
        elseif ($scenario === 'mapping-recovered') {
            check((int)value('SELECT orphan FROM data_local WHERE id=2') === 0, 'Unchanged recovered index clears orphan');
            check($result[3] === array(2=>2), 'Recovered source queued for propagation');
        } elseif (in_array($scenario, array('mapping-orphan','mapping-transient'), true)) {
            check((int)value('SELECT orphan FROM data_local WHERE id=2') === (int)($scenario === 'mapping-orphan'), 'Transient policy controls orphan marking');
            check(value('SELECT value FROM data_input_data WHERE data_template_data_id=2 AND data_input_field_id=2') === 'desc2', 'Missing source keeps its value for recovery');
        } else {
            check(value('SELECT snmp_index FROM data_local WHERE id=2') === '22', 'Source index mapped without explicit order');
        }
    } elseif (str_starts_with($scenario, 'remap-')) {
		seed_sources($scenario === 'remap-reverse');
		$failures = array('remap-index-failure' => array('data_local', "snmp_index <> '22'"),
			'remap-value-failure' => array('data_input_data', "value <> 'name2'"),
			'remap-type-failure' => array('data_input_data', "data_template_data_id <> 2 OR data_input_field_id <> 1 OR value <> 'ifName'"),
			'remap-graph-failure' => array('graph_local', "snmp_index <> '22'"));
		if (isset($failures[$scenario])) { list($table, $expression) = $failures[$scenario]; $connection->exec("ALTER TABLE $table ADD CONSTRAINT reject_remap CHECK($expression)"); }
		$definition = $data_query_xml_arrays[1];
		$result = data_query_reindex_local(1, 1, $definition);
		if (isset($failures[$scenario])) {
			check($result === false, 'Remap failure propagated');
			check(value('SELECT sort_field FROM host_snmp_query WHERE host_id=1') === 'ifDescr', 'Sort selection rolled back');
			check(value('SELECT snmp_index FROM data_local WHERE id=1') === '1', 'Earlier source change rolled back');
			check(value('SELECT snmp_index FROM graph_local WHERE id=1') === '1', 'Graph state retained');
			check(value('SELECT value FROM data_input_data WHERE data_template_data_id=2 AND data_input_field_id=1') === 'ifDescr', 'Type/value pair retained');
			$connection->exec("ALTER TABLE $table DROP CONSTRAINT reject_remap");
			$result = data_query_reindex_local(1, 1, $definition);
		}
		check(is_array($result), 'Remap succeeds');
		check(value('SELECT snmp_index FROM data_local WHERE id=2') === '22', 'Index remapped');
		check(value('SELECT snmp_index FROM graph_local WHERE id=2') === '22', 'Graph remapped');
		check(value('SELECT value FROM data_input_data WHERE data_template_data_id=2 AND data_input_field_id=1') === 'ifName', 'Per-source type correct');
		check(value('SELECT value FROM data_input_data WHERE data_template_data_id=2 AND data_input_field_id=2') === 'name2', 'Per-source value correct');
		$again = data_query_reindex_local(1, 1, $definition);
		check(is_array($again) && $again[1] === array(), 'Repeated remap is idempotent');
	} elseif (str_starts_with($scenario, 'policy-')) {
		$type = substr($scenario, 7);
		$connection->exec("INSERT INTO host_snmp_cache(host_id,snmp_query_id,field_name,field_value,snmp_index,oid) VALUES
			(1,1,'names','left','1','oid'),(1,1,'names','right','2','oid'),(1,1,'numbers','100','1','oid'),(1,1,'numbers','200','2','oid')");
		$data_query_xml_arrays[1] = array('index_order_type' => $type, 'index_order' => 'names:numbers',
			'fields' => array('names' => array('direction' => 'input'), 'numbers' => array('direction' => 'input')));
		$expected = $type === 'numeric' ? array('numbers') : ($type === 'alphanumeric' ? array('names','numbers') : array('names'));
		check(get_ordered_index_type_list(1, 1) === $expected, 'Type policy filters whole candidates');
        $data_query_xml_arrays[1]['oid_index'] = 'oid';
        $data_query_xml_arrays[1]['fields']['names']['oid'] = 'oid';
        $data_query_xml_arrays[1]['fields']['numbers']['oid'] = 'oid';
        check(get_ordered_index_type_list(1, 1) === $expected, 'OID shortcut cannot bypass type policy');
		$data_query_xml_arrays[1]['index_order'] = $type === 'numeric' ? 'names' : 'numbers';
		if ($type !== 'alphanumeric') { check(get_ordered_index_type_list(1, 1) === array(), 'Singleton cannot bypass type policy'); }
	} elseif ($scenario === 'xml-source-index') {
		$xml_file = tempnam($root . '/resource/snmp_queries', 'reindex-test-');
		check(is_string($xml_file), 'Owned XML file created');
		file_put_contents($xml_file, '<query><index_order_type>numeric</index_order_type><fields><ifDescr><direction>input</direction><source>value</source></ifDescr><ifIndex><direction>input</direction><source>index</source></ifIndex></fields></query>');
		$stmt = $connection->prepare('INSERT INTO snmp_query(id,name,xml_path) VALUES(1,?,?)'); $stmt->execute(array('fixture',$xml_file));
		check(get_data_query_array(1)['index_order'] === 'ifIndex', 'Actual XML loader normalizes index source');
		check(get_data_query_array(1)['index_order'] === 'ifIndex', 'Cached definition keeps normalization');
		check(get_best_data_query_index_type(1, 1) === 'ifIndex', 'Consumer chooses source index');
	} else { throw new RuntimeException('Unknown reindex scenario'); }
} finally {
	$connection->failure = '';
	if ($connection->inTransaction()) { $connection->rollBack(); }
	foreach (array_reverse($created) as $table) { $connection->exec('DROP TABLE `' . $table . '`'); }
	if (is_string($xml_file)) { unlink($xml_file); }
}
if ($coverage_file) {
	$coverage = xdebug_get_code_coverage();
	xdebug_stop_code_coverage();
	file_put_contents($coverage_file, json_encode(array('scenario' => $scenario, 'source_sha256' => hash_file('sha256', $root . '/lib/data_query.php'), 'runtime_sha256' => hash_file('sha256', $root . '/lib/data_query_runtime.php'), 'coverage' => $coverage), JSON_THROW_ON_ERROR));
}
print json_encode(array('scenario' => $scenario, 'checks' => $checks, 'php' => PHP_VERSION, 'database' => $connection->getAttribute(PDO::ATTR_SERVER_VERSION), 'benchmark' => $GLOBALS['benchmark'] ?? null), JSON_THROW_ON_ERROR) . PHP_EOL;
