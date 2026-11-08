<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2026 The Kadupul project and contributors                 |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 +-------------------------------------------------------------------------+
*/

declare(strict_types=1);

if (PHP_SAPI !== 'cli' || !getenv('KADUPUL_TEST_MYSQL_DSN')) exit(64);
$support = dirname(__DIR__, 2);
$root = getenv('LTS_REVIEW_SOURCE_ROOT') ?: $support;
$scenario = $argv[1] ?? '';
$directory = sys_get_temp_dir() . '/subsystem-test-' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
$config = ['base_path' => $root, 'library_path' => $root . '/lib', 'poller_id' => 1, 'cacti_server_os' => 'unix', 'poller_output_spool' => $directory . '/spool'];
$messages = $logs = $options = [];
$_SESSION = [];
function cacti_log($message, $output = false, $environment = 'CMDPHP', $level = '') { $GLOBALS['logs'][] = $message; }
function cacti_debug_backtrace($entry = '', $html = false, $record = true, $limit = 0, $skip = 0) { return ''; }
function raise_message($key, $message = '', $level = '') { $GLOBALS['messages'][] = $key; }
function is_error_message() { return count($GLOBALS['messages']) > 0; }
function read_config_option($name, $force = false) { return $GLOBALS['options'][$name] ?? ''; }
function set_config_option($name, $value) { $GLOBALS['options'][$name] = $value; }
function __($message, ...$args) { return $args ? vsprintf($message, $args) : $message; }
function api_plugin_hook_function($name, $value = null) { return $value; }
function snmpagent_api_device_new($save) {}
function automation_execute_device_create_tree($id) {}
function kill_session_var($name) { unset($_SESSION[$name]); }
function get_request_var($name) { return 'save'; }
function isset_request_var($name) { return false; }
function set_default_action() {}
function debug_level($host, $level) { return $level; }

require $root . '/include/global_constants.php';
require $root . '/tests/Helpers/PhpSource.php';
$source = file_get_contents($root . '/lib/functions.php');
foreach (['cacti_sizeof', 'cacti_count', 'clean_up_lines', 'cacti_is_sensitive_key', 'form_input_validate', 'array_rekey'] as $name) {
	eval(test_php_function_source($source, $name));
}
if (getenv('LTS_COVERAGE_DIRECTORY') && function_exists('pcov\\start')) {
	\pcov\start();
	register_shutdown_function(static function (): void {
		\pcov\stop();
		file_put_contents(getenv('LTS_COVERAGE_DIRECTORY') . '/' . getmypid() . '.json', json_encode(\pcov\collect(), JSON_THROW_ON_ERROR));
	});
}
require $root . '/lib/database.php';
require $support . '/lib/database_atomic.php';
require $root . '/lib/utility.php';
require $root . '/lib/api_device.php';
require $root . '/lib/poller.php';
require $support . '/lib/poller_output_spool.php';
mkdir($directory . '/include');
file_put_contents($directory . '/include/auth.php', '<?php /* CLI fixture only; no web server. */');
$cwd = getcwd();
chdir($directory);
require $root . '/sites.php';
chdir($cwd);

parse_str(str_replace(';', '&', substr(getenv('KADUPUL_TEST_MYSQL_DSN'), 6)), $dsn);
$database_hostname = $dsn['host'];
$database_port = $dsn['port'] ?? '3306';
$database_default = 'subsystem_' . bin2hex(random_bytes(6));
$admin = new PDO('mysql:host=' . $database_hostname . ';port=' . $database_port, getenv('KADUPUL_TEST_MYSQL_USER') ?: 'root', getenv('KADUPUL_TEST_MYSQL_PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$admin->exec("CREATE DATABASE `$database_default` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$db = new PDO('mysql:host=' . $database_hostname . ';port=' . $database_port . ';dbname=' . $database_default . ';charset=utf8mb4', getenv('KADUPUL_TEST_MYSQL_USER') ?: 'root', getenv('KADUPUL_TEST_MYSQL_PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$database_sessions = ["$database_hostname:$database_port:$database_default" => $db];
$db->exec("SET SESSION sql_mode='STRICT_ALL_TABLES'");
$result = [];
$destination_database = null;
try {
	$sql = file_get_contents($root . '/cacti.sql');
	foreach (['sites', 'host', 'data_local', 'data_template_data', 'data_input', 'data_input_data', 'data_input_fields', 'poller_item', 'poller_output', 'poller_output_boost', 'settings', 'host_snmp_query', 'poller_reindex', 'graph_local', 'poller'] as $table) {
		if (!preg_match('/CREATE TABLE (?:`?' . $table . '`?)\s*\([\s\S]*?;/', $sql, $match)) throw new RuntimeException('Missing shipped table ' . $table);
		$db->exec($match[0]);
	}
	$db->exec("INSERT INTO sites (id,name) VALUES (7,'Site'),(8,'Other')");
	$db->exec("INSERT INTO host (id,description,hostname,site_id,poller_id) VALUES (7,'Device','example.invalid',7,1)");
	$db->exec("INSERT INTO data_local (id,host_id) VALUES (7,7),(8,7)");
	$db->exec("INSERT INTO data_template_data (id,local_data_id,data_input_id) VALUES (7,7,0),(8,8,0)");
	$db->exec("INSERT INTO poller_item (local_data_id,poller_id,host_id,rrd_name,hostname,present) VALUES (7,1,7,'value','old',1),(8,1,7,'value','old',1)");
	$before = $db->query('SELECT * FROM poller_item ORDER BY local_data_id')->fetchAll(PDO::FETCH_ASSOC);
	$tuple = "(7,1,7,0,'new','public',2,500,'','','','','','','',161,'value','/unused/test.rrd',1,300,0,'1.3.6','','',1)";

	if (str_starts_with($scenario, 'cache-')) {
		$items = [$tuple];
		if ($scenario === 'cache-empty') $items = [];
		if ($scenario === 'cache-update-fail') $db->exec("CREATE TRIGGER refuse_cache BEFORE UPDATE ON poller_item FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture refusal'");
		if ($scenario === 'cache-insert-fail') $db->exec("CREATE TRIGGER refuse_cache BEFORE INSERT ON poller_item FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture refusal'");
		if ($scenario === 'cache-delete-fail') $db->exec("CREATE TRIGGER refuse_cache BEFORE DELETE ON poller_item FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture refusal'");
		if ($scenario === 'cache-engine') $db->exec('ALTER TABLE poller_item ENGINE=MyISAM');
		if ($scenario === 'cache-shadow') $db->exec('CREATE TEMPORARY TABLE poller_item (id INT) ENGINE=InnoDB');
		if ($scenario === 'cache-borrowed') {
			$db->beginTransaction();
			$db->exec("UPDATE sites SET name='caller' WHERE id=8");
			$db->exec("CREATE TRIGGER refuse_cache BEFORE INSERT ON poller_item FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture refusal'");
			// DDL commits; begin caller work after installing the failure trigger.
			$db->beginTransaction();
			$db->exec("UPDATE sites SET name='caller pending' WHERE id=8");
		}
		if ($scenario === 'cache-source-fail') $db->exec('RENAME TABLE data_template_data TO missing_source');
		$result['ok'] = in_array($scenario, ['cache-source-fail', 'cache-empty-host'], true) ? push_out_host(7) : poller_update_poller_cache_from_buffer([7,8], $items, 1);
		if ($scenario === 'cache-shadow') $db->exec('DROP TEMPORARY TABLE poller_item');
		$after = $db->query('SELECT * FROM poller_item ORDER BY local_data_id')->fetchAll(PDO::FETCH_ASSOC);
		$result += ['retained' => $before === $after, 'rows' => count($after), 'hostname' => $after[0]['hostname'] ?? null,
			'borrowed' => $db->inTransaction(), 'caller' => $db->query('SELECT name FROM sites WHERE id=8')->fetchColumn()];
	} elseif (str_starts_with($scenario, 'site-')) {
		if ($scenario === 'site-update-fail') $db->exec("CREATE TRIGGER refuse_site BEFORE UPDATE ON host FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture refusal'");
		if ($scenario === 'site-delete-fail') $db->exec("CREATE TRIGGER refuse_site BEFORE DELETE ON sites FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture refusal'");
		if ($scenario === 'site-engine') $db->exec('ALTER TABLE host ENGINE=MyISAM');
		if ($scenario === 'site-borrowed') {
			$db->exec("CREATE TRIGGER refuse_site BEFORE UPDATE ON host FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture refusal'");
			$db->beginTransaction();
			$db->exec("UPDATE sites SET name='caller' WHERE id=8");
		}
		$result['ok'] = sites_delete($scenario === 'site-empty' ? [] : [7]);
		$result += ['sites' => (int) $db->query('SELECT COUNT(*) FROM sites WHERE id=7')->fetchColumn(), 'site_id' => (int) $db->query('SELECT site_id FROM host WHERE id=7')->fetchColumn(),
			'borrowed' => $db->inTransaction(), 'caller' => $db->query('SELECT name FROM sites WHERE id=8')->fetchColumn()];
	} elseif (str_starts_with($scenario, 'duplicate-')) {
		$name = match ($scenario) { 'duplicate-long' => str_repeat('s',100), 'duplicate-unicode' => str_repeat('界',96), default => 'Site' };
		$db->prepare('UPDATE sites SET name=? WHERE id=7')->execute([$name]);
		$pattern = match ($scenario) { 'duplicate-array' => [], 'duplicate-blank' => ' ', 'duplicate-invalid' => "\xff", default => '<site> (1)' };
		$result['ok'] = duplicate_site($scenario === 'duplicate-missing' ? [8,999] : [7], $pattern);
		$result['names'] = $db->query('SELECT name FROM sites ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
	} elseif (str_starts_with($scenario, 'device-')) {
		if ($scenario === 'device-success') {
			$db->exec('DELETE FROM data_template_data');
			$db->exec('DELETE FROM data_local');
		}
		$description = $scenario === 'device-invalid' ? '' : 'Device';
		$ping = $scenario === 'device-late-invalid' ? 'invalid' : 1;
		if ($scenario === 'device-save-fail') $db->exec("CREATE TRIGGER refuse_device BEFORE UPDATE ON host FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture refusal'");
		$result['ok'] = api_device_save(7,0,$description,'example.invalid','public',2,'','',161,500,'on',2,$ping,23,400,1,'','','','','','',5,1,1,7);
		$result['disabled'] = $db->query('SELECT disabled FROM host WHERE id=7')->fetchColumn();
		$result['cache_retained'] = $before === $db->query('SELECT * FROM poller_item ORDER BY local_data_id')->fetchAll(PDO::FETCH_ASSOC);
	} elseif (str_starts_with($scenario, 'output-destination-')) {
		$name = $database_default . '_destination';
		$admin->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
		$destination_database = $name;
		$destination = new PDO('mysql:host=' . $database_hostname . ';port=' . $database_port . ';dbname=' . $name . ';charset=utf8mb4',
			getenv('KADUPUL_TEST_MYSQL_USER') ?: 'root', getenv('KADUPUL_TEST_MYSQL_PASSWORD') ?: '',
			[PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => $scenario === 'output-destination-emulated']);
		$destination->exec("SET SESSION sql_mode='STRICT_ALL_TABLES,NO_BACKSLASH_ESCAPES'");
		foreach (['poller_output', 'poller_output_boost'] as $table) {
			if (!preg_match('/CREATE TABLE (?:`?' . $table . '`?)\s*\([\s\S]*?;/', $sql, $match)) throw new RuntimeException('Missing shipped table ' . $table);
			$destination->exec($match[0]);
		}
		$rows = [[7, "o'brien\\界", "label:'temp'\\café 界"], [8, 'zero', '0']];
		$spool = poller_output_spool(1);
		$result['queued'] = poller_output_enqueue($spool, $rows, true);
		$result['ok'] = poller_output_replay($spool, $destination);
		$result['emulated'] = (bool) $destination->getAttribute(PDO::ATTR_EMULATE_PREPARES);
		$result['pending'] = (int) $spool->querySingle('SELECT COUNT(*) FROM batches');
		foreach (['poller_output', 'poller_output_boost'] as $table) {
			$result[$table] = $destination->query('SELECT local_data_id,rrd_name,output FROM ' . $table . ' ORDER BY local_data_id')->fetchAll(PDO::FETCH_NUM);
			$result['default_' . $table] = (int) $db->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
		}
		$spool->close();
		$spool = null;
	} elseif (str_starts_with($scenario, 'output-')) {
		$spool = poller_output_spool(1);
		$redirect = $scenario !== 'output-plain';
		$rows = [[7,'value','42'],[8,'value','0']];
		if (in_array($scenario, ['output-truncation', 'output-truncation-native'], true)) {
			$db->exec("SET SESSION sql_mode=''");
			$db->setAttribute(PDO::ATTR_EMULATE_PREPARES, $scenario === 'output-truncation');
			$rows[1][1] = str_repeat('x', 30);
		}
		$result['queued'] = poller_output_enqueue($spool, $rows, $redirect);
		if ($scenario === 'output-borrowed') {
			$db->beginTransaction();
			$db->exec("UPDATE sites SET name='caller pending' WHERE id=8");
		}
		if ($scenario === 'output-delayed') $db->exec("CREATE TRIGGER delay_output BEFORE INSERT ON poller_output_boost FOR EACH ROW DO SLEEP(1)");
		if (in_array($scenario, ['output-first-fail', 'output-retry'], true)) $db->exec("CREATE TRIGGER refuse_output BEFORE INSERT ON poller_output FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture refusal'");
		if ($scenario === 'output-second-fail') $db->exec("CREATE TRIGGER refuse_output BEFORE INSERT ON poller_output_boost FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture refusal'");
		if ($scenario === 'output-idempotent') $db->exec("INSERT INTO poller_output SELECT 7,'value',FROM_UNIXTIME(" . $spool->querySingle('SELECT time FROM batches') . "),'42'");
		$result['ok'] = poller_output_replay($spool, $db);
		$result['emulated'] = (bool) $db->getAttribute(PDO::ATTR_EMULATE_PREPARES);
		if ($scenario === 'output-borrowed') {
			$result['borrowed'] = $db->inTransaction();
			$result['caller'] = $db->query('SELECT name FROM sites WHERE id=8')->fetchColumn();
		}
		$result['pending'] = (int) $spool->querySingle('SELECT COUNT(*) FROM batches');
		$result['rows'] = (int) $db->query('SELECT COUNT(*) FROM poller_output')->fetchColumn();
		$result['boost'] = (int) $db->query('SELECT COUNT(*) FROM poller_output_boost')->fetchColumn();
		if ($scenario === 'output-retry') {
			$db->exec('DROP TRIGGER refuse_output');
			$result['retry'] = poller_output_replay($spool, $db);
			$result['retry_rows'] = (int) $db->query('SELECT COUNT(*) FROM poller_output')->fetchColumn();
			$result['retry_pending'] = (int) $spool->querySingle('SELECT COUNT(*) FROM batches');
		}
		$result['times_match'] = (int) $db->query('SELECT COUNT(*) FROM poller_output p JOIN poller_output_boost b ON p.local_data_id=b.local_data_id AND p.time=b.time')->fetchColumn();
		$spool->close();
		$spool = null;
	} elseif (str_starts_with($scenario, 'script-')) {
		$code = match ($scenario) { 'script-fail' => 'print "42\\n"; exit(7);', 'script-empty' => 'exit(0);', 'script-large' => 'print "42\\n"; print str_repeat("x", 1048576);', default => 'print "0\\n";' };
		$result['value'] = trim(exec_poll(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code)));
	} else {
		throw new RuntimeException('Unknown scenario ' . $scenario);
	}
} finally {
	if ($db->inTransaction()) $db->rollBack();
	if ($destination_database !== null) $admin->exec("DROP DATABASE `$destination_database`");
	$admin->exec("DROP DATABASE `$database_default`");
	foreach (glob($directory . '/spool/*') as $file) unlink($file);
	if (is_dir($directory . '/spool')) rmdir($directory . '/spool');
	unlink($directory . '/include/auth.php');
	rmdir($directory . '/include');
	rmdir($directory);
}
echo json_encode($result, JSON_THROW_ON_ERROR), PHP_EOL;
