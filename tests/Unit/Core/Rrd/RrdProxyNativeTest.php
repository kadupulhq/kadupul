<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

test('production proxy routing requires an acknowledgement over a real socket', function ($response, $expected, $reason, $flag = 'RRDTOOL_OUTPUT_BOOLEAN') {
    if (!function_exists('socket_create_pair')) {
        $this->markTestSkipped('The sockets extension is required.');
    }
    $root = dirname(__DIR__, 4);
    $dir = sys_get_temp_dir() . '/rrd-proxy-native-' . bin2hex(random_bytes(8));
    mkdir($dir, 0700);
    $coverage = $this->getTestResultObject()->getCodeCoverage();
    $bootstrap = '<?php ';
    if ($coverage !== null) {
        $bootstrap .= 'define("RRD_TEST_COVERAGE_DIRECTORY",__DIR__);require ' . var_export($root . '/tests/Fixtures/rrd-process-coverage.php', true) . ';';
    }
    $bootstrap .= '$root=' . var_export($root, true) . ';$response=' . var_export($response, true) . ';$flag=' . var_export($flag, true) . ';';
    $bootstrap .= <<<'SOURCE'
$config = array('rra_path'=>'/fixture');
require $root.'/include/global_constants.php';
require $root.'/include/vendor/autoload.php';
require $root.'/tests/Helpers/RrdProxyFrames.php';
function cacti_log(...$args) {}
function read_config_option($key) { return $key === 'storage_location' ? 1 : ($key === 'rsa_private_key' ? $GLOBALS['proxy_key'] : ''); }
// Exercise the encrypted protocol on an in-process socket pair.
require $root.'/lib/rrd.php';
$proxy_key = rrd_proxy_test_key();
$public_key = rrd_proxy_test_public_key($proxy_key);
if (!socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $sockets)) { exit(2); }
foreach ($sockets as $socket) {
    socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, array('sec'=>2,'usec'=>0));
}
if ($response !== null) {
    $packet = encrypt($response, $public_key)."_EOP_\r\n_EOT_\r\n";
    if (socket_write($sockets[1], $packet) !== strlen($packet)) { exit(3); }
}
socket_shutdown($sockets[1], 1);
$stale =& rrdtool_last_rejection();
$stale = 'stale rejection from previous command';
$result = rrdtool_execute('update /fixture/sample.rrd 1700000060:42', false, constant($flag), array($sockets[0], $public_key));
$command = rrd_proxy_test_plaintext(socket_read($sockets[1], 4096, PHP_BINARY_READ));
echo json_encode(array(is_string($result) ? base64_encode($result) : $result, rrdtool_last_rejection(), $command));
socket_close($sockets[0]);
socket_close($sockets[1]);
SOURCE;
    file_put_contents($dir . '/probe.php', $bootstrap);
    try {
        $process = proc_open(array(PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'pcov.directory=' . $root, '-d', 'pcov.exclude=~/(include/vendor|tests)/~', $dir . '/probe.php'), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($process), $error . $output);
        expect($error)->toBe('');
        expect(json_decode($output, true, 512, JSON_THROW_ON_ERROR))->toBe(array(is_string($expected) ? base64_encode($expected) : $expected, $reason, "update ./sample.rrd 1700000060:42_EOT_\r\n"));
        if ($coverage !== null) {
            $reports = glob($dir . '/*.coverage');
            expect($reports)->toHaveCount(1);
            $coverage->merge(unserialize(file_get_contents($reports[0])));
        }
    } finally {
        foreach (glob($dir . '/*') as $file) {
            unlink($file);
        }
        rmdir($dir);
    }
})->with('native proxy reply outcomes');

dataset('native proxy reply outcomes', function () {
    $cases = array(
        array("OK u:0.01 s:0.02 r:0.03\n", true, null),
        array("OK\r\n", true, null),
        array(null, null, null),
        array("invalid OK u:0.00", null, null),
        array("ERROR: unknown DS name 'missing'\n", false, "unknown DS name 'missing'"),
        array("ERROR: Permission denied\r\n", false, 'Permission denied'),
        array("ERROR: failed\nOK u:0 s:0 r:0\n", false, 'failed'),
        array("ERROR: expected OK u:0\n", false, 'expected OK u:0'),
    );
    foreach (['RRDTOOL_OUTPUT_STDOUT', 'RRDTOOL_OUTPUT_GRAPH_DATA'] as $flag) {
        foreach ([
            'failure' => ["ERROR: Permission denied\n", false, 'Permission denied'],
            'error mentioning OK' => ["ERROR: expected OK u:0\n", false, 'expected OK u:0'],
            'error with acknowledgement' => ["ERROR: failed\nOK u:0 s:0 r:0\n", false, 'failed'],
            'missing reply' => [null, false, null],
            'incomplete trailer' => ["payload\nOK u:", false, null],
            'no trailer' => ["partial payload\n", false, null],
            'timed success' => ["payload\nOK u:0.01 s:0.02 r:0.03\n", 'payload', null],
            'bare success' => ["OK\r\n", '', null],
            'framed without newline' => ["payload\nOK u:0.00 s:0.00 r:0.00", 'payload', null],
            'binary with internal marker' => ["\x89PNG\0OK u:inside\0data\nOK u:0.00 s:0.00 r:0.00\n", "\x89PNG\0OK u:inside\0data", null],
            'ERROR in text payload' => ["<text>ERROR: label</text>\nOK u:0 s:0 r:0\n", '<text>ERROR: label</text>', null],
        ] as $name => $outcome) {
            $cases[$flag . ' ' . $name] = [...$outcome, $flag];
        }
    }
    return $cases;
});

test('realtime proxy exports preserve a cached graph until a nonempty render succeeds', function (string $reply, $expected) {
    require_once dirname(__DIR__, 3) . '/Helpers/RrdCharacterization.php';
    $items = [rrd_characterization_item(1, 'AREA', rrd_characterization_ds('traffic_in'))];
    $graph = ['graph_start' => 1700000000, 'graph_end' => 1700003600, 'export_realtime' => 'rra/realtime.png'];
    $result = rrd_characterization_proxy_run($this, [
        'files' => ['router_traffic_11.rrd'],
        'options' => ['realtime_cache_path' => 'rra'] + rrd_characterization_options(),
        'db' => rrd_characterization_graph_db(rrd_characterization_graph(), $items),
        'proxy_replies' => ['graph' => [$reply]],
        'proxy_call' => 2,
        'calls' => [
            ['fn' => 'file_put_contents', 'args' => ['rra/realtime.png', 'last known graph']],
            ['fn' => 'chmod', 'args' => ['rra/realtime.png', 0640]],
            ['fn' => 'rrdtool_function_graph', 'args' => [7, 0, $graph, false, [], 0], 'globals' => ['_SESSION' => ['sess_realtime_hash' => 'fixture']]],
            ['fn' => 'file_get_contents', 'args' => ['rra/realtime.png']],
            ['fn' => 'fileperms', 'args' => ['rra/realtime.png']],
        ],
    ], 3);
    expect($result['results'][2]['returned'])->toBe($expected);
    expect($result['results'][3]['returned'])->toBe($expected === false ? 'last known graph' : $expected);
    expect($result['results'][4]['returned'] & 0777)->toBe($expected === false ? 0640 : 0600);
    foreach ($result['results'] as $call) {
        expect($call['diagnostics'])->toBe([]);
    }
    expect(array_filter($result['received'], static fn($command) => str_starts_with($command, 'graph ')))->toHaveCount(1);
})->with([
    'failed render' => ["ERROR: missing RRD", false],
    'error followed by success marker' => ["ERROR: failed\nOK u:0 s:0 r:0", false],
    'empty successful render' => ['OK u:0 s:0 r:0', false],
    'successful render' => ["PNG new image\nOK u:0 s:0 r:0", 'PNG new image'],
]);

test('array commands reach the proxy as bare tokens, and unsafe arguments are not sent', function () {
    if (!function_exists('socket_create_pair')) {
        $this->markTestSkipped('The sockets extension is required.');
    }
    $root = dirname(__DIR__, 4);
    $dir = sys_get_temp_dir() . '/rrd-proxy-native-' . bin2hex(random_bytes(8));
    mkdir($dir, 0700);
    $coverage = $this->getTestResultObject()->getCodeCoverage();
    $bootstrap = '<?php ';
    if ($coverage !== null) {
        $bootstrap .= 'define("RRD_TEST_COVERAGE_DIRECTORY",__DIR__);require ' . var_export($root . '/tests/Fixtures/rrd-process-coverage.php', true) . ';';
    }
    $bootstrap .= '$root=' . var_export($root, true) . ';';
    $bootstrap .= <<<'SOURCE'
$config = array('rra_path'=>'/fixture');
require $root.'/include/global_constants.php';
require $root.'/include/vendor/autoload.php';
require $root.'/tests/Helpers/RrdProxyFrames.php';
function cacti_log(...$args) {}
function read_config_option($key) { return $key === 'storage_location' ? 1 : ($key === 'rsa_private_key' ? $GLOBALS['proxy_key'] : ''); }
require $root.'/lib/rrd.php';
$proxy_key = rrd_proxy_test_key();
$public_key = rrd_proxy_test_public_key($proxy_key);
if (!socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $sockets)) { exit(2); }
foreach ($sockets as $socket) {
    socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, array('sec'=>2,'usec'=>0));
}
$refused = array();
foreach (array("/fixture/it\0s.rrd", '/fixture/it s.rrd', "/fixture/it's.rrd", '') as $path) {
    $refused[] = rrdtool_execute(array('info', $path), false, RRDTOOL_OUTPUT_STDOUT, array($sockets[0], $public_key));
}
$packet = encrypt("info output\nOK u:0.00 s:0.00 r:0.00\n", $public_key)."_EOP_\r\n_EOT_\r\n";
if (socket_write($sockets[1], $packet) !== strlen($packet)) { exit(3); }
$output = rrdtool_execute(array('info', '/fixture/sample.rrd'), false, RRDTOOL_OUTPUT_STDOUT, array($sockets[0], $public_key));
$command = rrd_proxy_test_plaintext(socket_read($sockets[1], 4096, PHP_BINARY_READ));
echo json_encode(array($refused, $output, $command));
socket_close($sockets[0]);
socket_close($sockets[1]);
SOURCE;
    file_put_contents($dir . '/probe.php', $bootstrap);
    try {
        $process = proc_open(array(PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'pcov.directory=' . $root, '-d', 'pcov.exclude=~/(include/vendor|tests)/~', $dir . '/probe.php'), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($process), $error . $output);
        expect($error)->toBe('');
        // The only command read is the safe one, so the refused ones never reached the socket.
        expect(json_decode($output, true, 512, JSON_THROW_ON_ERROR))->toBe(array(array(false, false, false, false), 'info output', "info ./sample.rrd_EOT_\r\n"));
        if ($coverage !== null) {
            $reports = glob($dir . '/*.coverage');
            expect($reports)->toHaveCount(1);
            $coverage->merge(unserialize(file_get_contents($reports[0])));
        }
    } finally {
        foreach (glob($dir . '/*') as $file) {
            unlink($file);
        }
        rmdir($dir);
    }
});
