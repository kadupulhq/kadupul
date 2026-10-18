<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
$root = dirname(__DIR__, 2);
$scenario = $argv[1];
$directory = $argv[2];
require $root . '/tests/Helpers/NativeChildCoverageEvidence.php';
require $root . '/tests/Helpers/RrdProxyDeadlineCoverageRegistration.php';
if (isset($argv[4])) {
    $nativeChildCoverageSnapshot = NativeChildCoverageEvidence::snapshot($root,
        RrdProxyDeadlineCoverageRegistration::PRODUCER, json_encode([$scenario, $argv[3]], JSON_THROW_ON_ERROR),
        RrdProxyDeadlineCoverageRegistration::SOURCES);
    define('RRD_PROXY_DEADLINE_TEST_COVERAGE', true);
    define('RRD_TEST_COVERAGE_DIRECTORY', $directory);
    require __DIR__ . '/rrd-process-coverage.php';
}
require $root . '/include/vendor/autoload.php';
require $root . '/include/global_constants.php';
require $root . '/tests/Helpers/RrdProxyFrames.php';
require $root . '/lib/rrd.php';
$config = ['rra_path' => '/fixture', 'rrd_command_timeout' => in_array($scenario, ['configured', 'success'], true) ? 1 : 60];
$logs = [];
function cacti_log($message, ...$arguments) { $GLOBALS['logs'][] = $message; }
function read_config_option($name) { return $name === 'rsa_private_key' ? $GLOBALS['proxy_key'] : ''; }
$proxy_key = rrd_proxy_test_key();
$public = rrd_proxy_test_public_key($proxy_key);
if (!socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $sockets)) {
    throw new RuntimeException('Cannot create isolated proxy sockets');
}
$pid = pcntl_fork();
if ($pid === -1) {
    throw new RuntimeException('Cannot start isolated proxy');
}
if ($pid === 0) {
    // No child coverage evidence: only the parent executes production code.
    socket_close($sockets[0]);
    socket_set_option($sockets[1], SOL_SOCKET, SO_RCVTIMEO, ['sec' => 2, 'usec' => 0]);
    $commands = '';
    $end = hrtime(true) + 1500000000;
    if ($scenario === 'success') {
        for ($i = 0; $i < 2; $i++) {
            $frame = '';
            while (!str_contains($frame, "_EOT_\r\n")) {
                $chunk = @socket_read($sockets[1], 8192);
                if (!is_string($chunk) || $chunk === '') { exit(2); }
                $frame .= $chunk;
            }
            $commands .= rrd_proxy_test_plaintext($frame);
            usleep(600000);
            $reply = encrypt("payload-$i\nOK u:0 s:0 r:0\n", $public) . "_EOP_\r\n_EOT_\r\n";
            foreach (str_split($reply, 37) as $chunk) {
                if (!rrdtool_proxy_write($sockets[1], $chunk)) { exit(3); }
                usleep(1000);
            }
        }
    } else {
        socket_set_nonblock($sockets[1]);
        while (hrtime(true) < $end) {
            $chunk = @socket_read($sockets[1], 8192);
            if (is_string($chunk)) { $commands .= $chunk; }
            if ($scenario === 'drip') { @socket_write($sockets[1], 'x'); }
            usleep(40000);
        }
        $commands = rrd_proxy_test_plaintext($commands);
    }
    file_put_contents($directory . '/commands', $commands);
    socket_close($sockets[1]);
    // Avoid running the parent's registered coverage shutdown handler.
    posix_kill(getmypid(), SIGKILL);
    exit(4);
}
socket_close($sockets[1]);
$cap = &rrd_command_deadline();
$cap = $scenario === 'configured' || $scenario === 'success' ? null : hrtime(true) + ($scenario === 'expired' ? -1 : 180000000);
$pipe = [$sockets[0], $public];
$start = hrtime(true);
$result = __rrd_proxy_execute('info /fixture/sample.rrd', false, constant($argv[3]), $pipe);
$elapsed = (hrtime(true) - $start) / 1e9;
$closed = false;
try { socket_get_option($sockets[0], SOL_SOCKET, SO_TYPE); } catch (Error) { $closed = true; }
if ($scenario === 'success') {
    $second = __rrd_proxy_execute('info /fixture/second.rrd', false, RRDTOOL_OUTPUT_STDOUT, $pipe);
    socket_close($sockets[0]);
} else {
    // A caller can still hold its pipe array after a timeout. It must fail safely.
    $second = __rrd_proxy_execute('info /fixture/second.rrd', false, RRDTOOL_OUTPUT_BOOLEAN, $pipe);
    __rrd_proxy_close($pipe);
}
pcntl_waitpid($pid, $status);
$cap = null;
$commands = file_get_contents($directory . '/commands');
if ($commands === false) { throw new RuntimeException('Missing native wire transcript'); }
$nativeChildCoverageMarkers = RrdProxyDeadlineCoverageRegistration::MARKERS;
echo json_encode([$result, $second, $elapsed, $commands, $logs, $closed], JSON_THROW_ON_ERROR);
