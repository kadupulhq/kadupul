<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

function runWhitelistProbe($test, $program, array $arguments = array())
{
    $root = dirname(__DIR__, 3);
    $dir = sys_get_temp_dir() . '/whitelist-test-' . bin2hex(random_bytes(8));
    mkdir($dir . '/include', 0700, true);
    mkdir($dir . '/lib', 0700);
    $files = array('include/auth.php', 'lib/api_data_source.php', 'lib/poller.php', 'lib/template.php', 'lib/utility.php');
    foreach ($files as $file) {
        file_put_contents($dir . '/' . $file, '<?php');
    }
    $coverage = $test->getTestResultObject()->getCodeCoverage();
    if ($coverage !== null) {
        $program = 'define("WHITELIST_EXEC_TEST_COVERAGE",true);'
            . 'define("RRD_TEST_COVERAGE_DIRECTORY",' . var_export($dir, true) . ');'
            . 'require ' . var_export($root . '/tests/Fixtures/rrd-process-coverage.php', true) . ';' . $program;
    }
    try {
        $process = proc_open(
            array_merge(array(PHP_BINARY, '-d', 'pcov.directory=' . $root,
                '-d', 'pcov.exclude=~/(include/vendor|tests)/~', '-r', $program, $root), $arguments),
            array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
            $pipes,
            $dir
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start whitelist probe');
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0 || $stderr !== '') {
            throw new RuntimeException($stderr . $stdout);
        }
        if ($coverage !== null) {
            foreach (glob($dir . '/*.coverage') as $report) {
                $coverage->merge(unserialize(file_get_contents($report)));
            }
        }
        return $stdout;
    } finally {
        foreach ($files as $file) {
            unlink($dir . '/' . $file);
        }
        foreach (glob($dir . '/*.coverage') as $report) {
            unlink($report);
        }
        rmdir($dir . '/include');
        rmdir($dir . '/lib');
        rmdir($dir);
    }
}

test('whitelist controller requires CSRF intent and passes separate command arguments', function ($method, $token, $id, $action, $expected, $failure = false) {
    $program = <<<'PHP'
function csrf_startup() {
    csrf_conf('rewrite', false);
    csrf_conf('defer', true);
    csrf_conf('auto-session', false);
    csrf_conf('secret', 'isolated-whitelist-secret');
}
require $argv[1] . '/include/vendor/csrf/csrf-magic.php';
require $argv[1] . '/include/global_constants.php';
require $argv[1] . '/lib/html_utility.php';
require $argv[1] . '/lib/html.php';
function __($text) { return $text; }
function cacti_sizeof($value) { return is_array($value) ? count($value) : 0; }
function read_config_option($name) { return '/configured php/bin/php'; }
function die_html_input_error(...$args) { http_response_code(400); exit; }
function cacti_exec($binary, $arguments, &$output, $timeout) {
    $expected = array('-q', '/configured path/cli/input_whitelist.php', '--update', '--push', '--id=7');
    if ($binary !== '/configured php/bin/php' || $arguments !== $expected || $timeout !== false) throw new RuntimeException('Incorrect argv or timeout');
    $output = $GLOBALS['failure'] === 'empty' ? array() : array('<script>output</script>');
    return $GLOBALS['failure'] ? 7 : 0;
}
function raise_message($id, $message, $level) { echo json_encode(array($message, $level === MESSAGE_LEVEL_ERROR ? 'error' : ($level === MESSAGE_LEVEL_INFO ? 'info' : 'unknown'))); }
function top_header() { exit; }
$config = array('base_path' => '/configured path');
session_id('whitelist-test');
$_SESSION = array('sess_user_id' => 42);
$_SERVER['REQUEST_METHOD'] = $argv[2];
$_REQUEST = array('action' => json_decode($argv[5], true), 'id' => json_decode($argv[4], true));
$_POST = $argv[2] === 'POST' ? $_REQUEST : array();
if ($argv[3] === 'valid') $_POST['__csrf_magic'] = csrf_get_tokens();
if ($argv[3] === 'query') $_GET['__csrf_magic'] = $_REQUEST['__csrf_magic'] = csrf_get_tokens();
if ($argv[3] === 'array') $_POST['__csrf_magic'] = array('bad');
if ($argv[3] === 'forged') $_POST['__csrf_magic'] = 'sid:forged,1';
$failure = json_decode($argv[6], true);
register_shutdown_function(function () { echo 'STATUS:' . (http_response_code() ?: 200); });
require $argv[1] . '/data_input.php';
PHP;
    $output = runWhitelistProbe($this, $program, array($method, $token, json_encode($id), json_encode($action), json_encode($failure)));
    if ($expected === 200) {
        expect($output)->toEndWith('STATUS:200');
        $message = json_decode(substr($output, 0, -10), true, 512, JSON_THROW_ON_ERROR);
        expect($message[0])->toBe($failure === 'empty' ? 'Unexpected error occurred' : '&lt;script&gt;output&lt;/script&gt;');
        expect($message[1])->toBe($failure ? 'error' : 'info');
    } else {
        expect($output)->toBe('STATUS:' . $expected);
    }
})->with(array(
    array('GET', 'missing', '7', 'whitelist_update', 405),
    array('HEAD', 'missing', '7', 'whitelist_update', 405),
    array('PUT', 'valid', '7', 'whitelist_update', 405),
    array('POST', 'missing', '7', 'whitelist_update', 403),
    array('POST', 'query', '7', 'whitelist_update', 403),
    array('POST', 'array', '7', 'whitelist_update', 403),
    array('POST', 'forged', '7', 'whitelist_update', 403),
    array('GET', 'missing', '7', array('whitelist_update'), 400),
    array('POST', 'valid', '7', array('whitelist_update'), 400),
    array('POST', 'valid', '7; echo injected', 'whitelist_update', 400),
    array('POST', 'valid', array('7'), 'whitelist_update', 400),
    array('POST', 'valid', '', 'whitelist_update', 400),
    array('POST', 'valid', null, 'whitelist_update', 400),
    array('POST', 'valid', '0', 'whitelist_update', 400),
    array('POST', 'valid', '-1', 'whitelist_update', 400),
    array('POST', 'valid', '7', 'whitelist_update', 200, 'empty'),
    array('POST', 'valid', '7', 'whitelist_update', 200),
    array('POST', 'valid', '7', 'whitelist_update', 200, true),
));

test('native argv execution preserves metacharacters and reports exit status', function ($exit, $timeout) {
    $program = <<<'PHP'
require $argv[1] . '/lib/functions.php';
$payload = 'spaces ; & | $(echo injected) `echo injected` "quotes"';
$output = array();
$timeout = json_decode($argv[3], true);
$result = cacti_exec(PHP_BINARY, array('-r', 'echo $argv[1]; exit((int) $argv[2]);', $payload, $argv[2]), $output, $timeout);
$string = cacti_exec_string(PHP_BINARY, array('-r', 'echo $argv[1]; exit((int) $argv[2]);', $payload, $argv[2]), $timeout);
echo json_encode(array($result, $output, $string));
PHP;
    $result = json_decode(runWhitelistProbe($this, $program, array((string) $exit, json_encode($timeout))), true, 512, JSON_THROW_ON_ERROR);
    $payload = 'spaces ; & | $(echo injected) `echo injected` "quotes"';
    expect($result)->toBe(array($exit, array($payload), $exit === 0 ? $payload : false));
})->with(array(0, 7))->with(array(5, false));

test('native argv execution terminates a child at its deadline', function () {
    $program = <<<'PHP'
require $argv[1] . '/lib/functions.php';
$config = array('is_web' => false, 'base_path' => getcwd(), 'config_options_array' => array(
    'selective_debug' => '', 'client_timezone_support' => '', 'log_destination' => '0', 'path_cactilog' => ''));
$output = array();
$start = microtime(true);
$status = cacti_exec(PHP_BINARY, array('-r', 'sleep(5); echo "not terminated";'), $output, 1);
echo json_encode(array($status, $output, microtime(true) - $start));
PHP;
    $result = json_decode(runWhitelistProbe($this, $program), true, 512, JSON_THROW_ON_ERROR);
    expect($result[0])->toBe(1);
    expect($result[1])->toBe(array());
    expect($result[2])->toBeGreaterThanOrEqual(1);
    expect($result[2])->toBeLessThan(4);
});
