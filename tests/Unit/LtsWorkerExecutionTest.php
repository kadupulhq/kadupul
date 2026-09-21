<?php
/* Regression coverage executes the actual controllers with auth/database/worker
 * boundaries stubbed. Existing CSRF suites verify token validation separately. */

test('LTS worker controllers preserve lock and invocation contracts', function ($controller, $failure, $busy, $expected) {
	$root = dirname(__DIR__, 2);
	$dir = sys_get_temp_dir() . '/lts-worker-' . bin2hex(random_bytes(8));
	mkdir($dir . '/include', 0700, true);
	mkdir($dir . '/lib', 0700);
	$files = array('include/auth.php');
	foreach (array('api_automation', 'api_data_source', 'api_device', 'api_graph', 'api_tree', 'data_query', 'html_tree', 'ping', 'poller', 'reports', 'snmp', 'template', 'utility') as $name) {
		$files[] = 'lib/' . $name . '.php';
	}
	foreach ($files as $file) file_put_contents($dir . '/' . $file, '<?php');
	$program = <<<'PHP'
require $argv[1] . '/include/global_constants.php';
require $argv[1] . '/lib/html_utility.php';
require $argv[1] . '/lib/html.php';
function __($text, ...$args) { return $args ? vsprintf($text, $args) : $text; }
function cacti_sizeof($value) { return is_array($value) ? count($value) : 0; }
function api_plugin_hook_function($name, $value) { return $value; }
function api_plugin_hook($name) {}
function csrf_require_post($strict) {
    if (!$strict || $_SERVER['REQUEST_METHOD'] !== 'POST') throw new RuntimeException('Changed POST boundary');
}
function read_config_option($name) { return '/configured php/bin/php'; }
function cacti_exec($binary, $args, &$output, $timeout) {
    $expected = $GLOBALS['controller'] === 'host.php'
        ? array('-q', '/configured path/cli/poller_reindex_hosts.php', '--qid=all', '--id=7')
        : array('-q', '/configured path/cli/input_whitelist.php', '--update', '--push', '--id=7');
    if ($binary !== '/configured php/bin/php' || $args !== $expected || $timeout !== null) throw new RuntimeException('Wrong worker contract');
    $GLOBALS['events'][] = 'exec';
    $output = array('<script>worker output</script>');
    return $GLOBALS['failure'];
}
function db_fetch_cell_prepared($sql, $params) {
    if (strpos($sql, 'GET_LOCK') !== false) {
        if ($params !== array('host.reindex.7')) throw new RuntimeException('Wrong lock');
        $GLOBALS['events'][] = 'lock';
        return $GLOBALS['busy'] ? 0 : 1;
    }
    if ($GLOBALS['failure'] || $params !== array(7)) throw new RuntimeException('Count queried after failure');
    $GLOBALS['events'][] = 'count';
    return 12;
}
function db_execute_prepared($sql, $params) {
    if ($sql !== 'DO RELEASE_LOCK(?)' || $params !== array('host.reindex.7')) throw new RuntimeException('Wrong release');
    echo '|released';
}
function raise_message($id, $message, $level) {
    if (strpos($message, '<script>') !== false) throw new RuntimeException('Unescaped worker output');
    if ($GLOBALS['controller'] === 'data_input.php' && strpos($message, '&lt;script&gt;') === false) throw new RuntimeException('Lost output');
    $GLOBALS['events'][] = $level === MESSAGE_LEVEL_ERROR ? 'error' : ($level === MESSAGE_LEVEL_WARN ? 'busy' : 'success');
}
function top_header() { exit; }
$controller = $argv[2];
$failure = (int) $argv[3];
$busy = (bool) $argv[4];
$events = array();
$config = array('base_path' => '/configured path');
$_SERVER['REQUEST_METHOD'] = 'POST';
$_REQUEST = array('action' => $controller === 'host.php' ? 'reindex' : 'whitelist_update', 'id' => '7', 'host_id' => '7');
register_shutdown_function(function () { echo json_encode($GLOBALS['events']); });
require $argv[1] . '/' . $controller;
PHP;
	try {
		$process = proc_open(array(PHP_BINARY, '-r', $program, $root, $controller, (string) $failure, (string) $busy), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, $dir);
		if (!is_resource($process)) throw new RuntimeException('Unable to start controller probe');
		$stdout = stream_get_contents($pipes[1]);
		$stderr = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		$status = proc_close($process);
		if ($status !== 0 || $stderr !== '') throw new RuntimeException($stderr . $stdout);
		$parts = explode('|', $stdout);
		expect(json_decode($parts[0], true))->toBe($expected);
		expect($parts[1] ?? null)->toBe($controller === 'host.php' && !$busy ? 'released' : null);
	} finally {
		foreach ($files as $file) unlink($dir . '/' . $file);
		rmdir($dir . '/include');
		rmdir($dir . '/lib');
		rmdir($dir);
	}
})->with(array(
	'host success' => array('host.php', 0, false, array('lock', 'exec', 'count', 'success')),
	'host failure' => array('host.php', 7, false, array('lock', 'exec', 'error')),
	'host busy' => array('host.php', 0, true, array('lock', 'busy')),
	'whitelist success' => array('data_input.php', 0, false, array('exec', 'success')),
	'whitelist failure' => array('data_input.php', 7, false, array('exec', 'error')),
));
