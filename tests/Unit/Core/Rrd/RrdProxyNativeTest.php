<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

test('production proxy routing requires an acknowledgement over a real socket', function ($response, $expected, $reason) {
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
    $bootstrap .= '$root=' . var_export($root, true) . ';$response=' . var_export($response, true) . ';';
    $bootstrap .= <<<'SOURCE'
$config = array('rra_path'=>'/fixture');
require $root.'/include/global_constants.php';
function cacti_log(...$args) {}
function read_config_option($key) { return $key === 'storage_location' ? 1 : ''; }
// Exercise the supported plaintext protocol on an in-process socket pair.
require $root.'/lib/rrd.php';
$encryption = false;
if (!socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $sockets)) { exit(2); }
foreach ($sockets as $socket) {
    socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, array('sec'=>2,'usec'=>0));
}
if ($response !== null) {
    $packet = $response."_EOP_\r\n_EOT_\r\n";
    if (socket_write($sockets[1], $packet) !== strlen($packet)) { exit(3); }
}
socket_shutdown($sockets[1], 1);
$stale =& rrdtool_last_rejection();
$stale = 'stale rejection from previous command';
$result = rrdtool_execute('update /fixture/sample.rrd 1700000060:42', false, RRDTOOL_OUTPUT_BOOLEAN, array($sockets[0], 'fixture-key'));
$command = socket_read($sockets[1], 4096, PHP_BINARY_READ);
echo json_encode(array($result, rrdtool_last_rejection(), $command));
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
        expect(json_decode($output, true, 512, JSON_THROW_ON_ERROR))->toBe(array($expected, $reason, "update ./sample.rrd 1700000060:42_EOT_\r\n"));
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
})->with(array(
    array("OK u:0.01 s:0.02 r:0.03\n", true, null),
    array("OK\r\n", true, null),
    array(null, null, null),
    array("invalid OK u:0.00", null, null),
    array("ERROR: unknown DS name 'missing'\n", false, "unknown DS name 'missing'"),
    array("ERROR: Permission denied\r\n", false, 'Permission denied'),
    array("ERROR: failed\nOK u:0 s:0 r:0\n", false, 'failed'),
    array("ERROR: expected OK u:0\n", false, 'expected OK u:0'),
));
