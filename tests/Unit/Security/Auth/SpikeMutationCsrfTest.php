<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

test('spike removal requires explicit POST intent before RRD access', function ($method, $token, $spikeMethod, $expected, $dryrun = false) {
    $root = dirname(__DIR__, 4);
    $dir = sys_get_temp_dir() . '/spike-csrf-' . bin2hex(random_bytes(8));
    mkdir($dir . '/include', 0700, true);
    mkdir($dir . '/lib', 0700);
    file_put_contents($dir . '/include/auth.php', '<?php');
    file_put_contents($dir . '/lib/spikekill.php', '<?php');
    $program = <<<'PHP'
function csrf_startup() {
    csrf_conf('rewrite', false);
    csrf_conf('defer', true);
    csrf_conf('auto-session', false);
    csrf_conf('secret', 'isolated-spike-test-secret');
}
require $argv[1] . '/include/vendor/csrf/csrf-magic.php';
require $argv[1] . '/lib/html_utility.php';
require $argv[1] . '/include/global_constants.php';
function __($text, ...$args) { return $args ? vsprintf($text, $args) : $text; }
function read_config_option($name) { return ''; }
function cacti_sizeof($value) { return is_array($value) ? count($value) : 0; }
function is_realm_allowed($realm) { return true; }
function db_fetch_assoc_prepared(...$args) { return array(array('local_data_id' => 2)); }
function get_data_source_path(...$args) { return '/isolated/fixture.rrd'; }
class spikekill {
    public $dryrun;
    public $html;
    public function __construct(...$args) {}
    public function remove_spikes() { echo $this->dryrun ? 'DRYRUN' : 'MUTATION'; exit; }
}
$config = array('base_path' => getcwd());
session_id('spike-csrf-test');
$_SESSION = array('sess_user_id' => 42);
$_SERVER['REQUEST_METHOD'] = $argv[2];
$_REQUEST = array('local_graph_id' => '2');
if ($argv[5] === 'true') $_REQUEST['dryrun'] = 'true';
$spikeMethod = json_decode($argv[4], true);
if ($spikeMethod !== null) $_REQUEST['method'] = $spikeMethod;
$_POST = $argv[2] === 'POST' ? $_REQUEST : array();
if ($argv[3] === 'valid') $_POST['__csrf_magic'] = csrf_get_tokens();
if ($argv[3] === 'query') $_GET['__csrf_magic'] = $_REQUEST['__csrf_magic'] = csrf_get_tokens();
if ($argv[3] === 'array') $_POST['__csrf_magic'] = array('bad');
if ($argv[3] === 'forged') $_POST['__csrf_magic'] = 'sid:forged,1';
register_shutdown_function(function () { echo 'STATUS:' . (http_response_code() ?: 200); });
require $argv[1] . '/spikekill.php';
PHP;
    $coverage = $this->getTestResultObject()->getCodeCoverage();
    if ($coverage !== null) {
        $program = 'define("SPIKE_CSRF_TEST_COVERAGE",true);'
            . 'define("RRD_TEST_COVERAGE_DIRECTORY",' . var_export($dir, true) . ');'
            . 'require ' . var_export($root . '/tests/Fixtures/rrd-process-coverage.php', true) . ';' . $program;
    }
    try {
        $process = proc_open(array(PHP_BINARY, '-d', 'pcov.directory=' . $root, '-d', 'pcov.exclude=~/(include/vendor|tests)/~', '-r', $program, $root, $method, $token, json_encode($spikeMethod), json_encode($dryrun)), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, $dir);
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start the isolated spike-removal request process.');
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0 || $stderr !== '') {
            throw new RuntimeException($stderr . $stdout);
        }
        expect($stdout)->toBe(($expected === 200 ? ($dryrun ? 'DRYRUN' : 'MUTATION') : '') . 'STATUS:' . $expected);
        if ($coverage !== null) {
            foreach (glob($dir . '/*.coverage') as $file) {
                $coverage->merge(unserialize(file_get_contents($file)));
            }
        }
    } finally {
        unlink($dir . '/include/auth.php');
        unlink($dir . '/lib/spikekill.php');
        rmdir($dir . '/include');
        rmdir($dir . '/lib');
        foreach (glob($dir . '/*.coverage') as $file) {
            unlink($file);
        }
        rmdir($dir);
    }
})->with(array(
    array('GET', 'missing', 'stddev', 405),
    array('GET', 'valid', 'stddev', 405),
    array('GET', 'missing', null, 405),
    array('HEAD', 'missing', 'stddev', 405),
    array('PUT', 'missing', 'stddev', 405),
    array('', 'missing', 'stddev', 405),
    array('POST', 'missing', 'stddev', 403),
    array('POST', 'query', 'stddev', 403),
    array('POST', 'array', 'stddev', 403),
    array('POST', 'forged', 'stddev', 403),
    array('POST', 'valid', array('stddev'), 400),
    array('POST', 'valid', 'stddev', 200),
    array('POST', 'valid', 'float', 200),
    array('POST', 'valid', 'variance', 200),
    array('POST', 'valid', 'fill', 200),
    array('POST', 'valid', null, 200),
    array('GET', 'missing', 'stddev', 405, true),
    array('POST', 'missing', 'stddev', 403, true),
    array('POST', 'valid', 'stddev', 200, true),
));
