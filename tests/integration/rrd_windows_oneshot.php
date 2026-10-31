<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
if (($argv[1] ?? '') === '--native-windows' && PHP_OS_FAMILY !== 'Windows') {
    throw new RuntimeException('This CI mode requires native Windows.');
}

$childCoverage = null;
if (($argv[1] ?? '') === '--coverage') {
    $root = dirname(__DIR__, 2);
    require $root . '/tests/vendor/autoload.php';
    require $root . '/tests/Helpers/NativeChildCoverageEvidence.php';
    require $root . '/tests/Helpers/RrdWindowsCoverageRegistration.php';
    $snapshot = NativeChildCoverageEvidence::snapshot(
        $root,
        RrdWindowsCoverageRegistration::PRODUCER,
        RrdWindowsCoverageRegistration::SCENARIO,
        RrdWindowsCoverageRegistration::SOURCES
    );
    $filter = new SebastianBergmann\CodeCoverage\Filter();
    foreach (RrdWindowsCoverageRegistration::HITS as $source) {
        $filter->includeFile($root . '/' . $source);
    }
    $childCoverage = new SebastianBergmann\CodeCoverage\CodeCoverage(
        (new SebastianBergmann\CodeCoverage\Driver\Selector())->forLineCoverage($filter),
        $filter
    );
    $childCoverage->start(RrdWindowsCoverageRegistration::SCENARIO);
}
require dirname(__DIR__, 2) . '/include/global_constants.php';
define('CACTI_LOCALE', 'en-US');
function read_config_option(string $name): string|false
{
    return $name === 'path_rrdtool' ? PHP_BINARY : false;
}
function cacti_log(string $message, mixed ...$arguments): void
{
    $GLOBALS['rrd_test_logs'][] = $message;
}
function cacti_session_close(): void {}
require dirname(__DIR__, 2) . '/lib/rrd.php';
require dirname(__DIR__, 2) . '/src/Graphing/Infrastructure/Rrd/WindowsOneShotRrdtool.php';
$exchange = new Kadupul\Graphing\Infrastructure\Rrd\WindowsOneShotRrdtool();

$directory = sys_get_temp_dir() . '/rrd-windows-io-' . bin2hex(random_bytes(12));
if (!mkdir($directory, 0700)) {
    throw new RuntimeException('Cannot create owned process fixture directory.');
}
$previous = getcwd();
$count = 0;
function rrd_windows_check(bool $condition, string $name): void
{
    if (!$condition) {
        throw new RuntimeException('Failed: ' . $name);
    }
    $GLOBALS['count']++;
}
try {
    // PHP_BINARY with '-' executes this owned file. It provides an actual child
    // for the production RRDtool argv on both Windows and POSIX, without a shell.
    $fixture = <<<'PHP'
<?php
$first = fread(STDIN, 16);
if (str_starts_with($first, 'stall')) {
    print 'partial'; fflush(STDOUT); usleep(10000000); exit(0);
}
if (str_starts_with($first, 'diagnostic')) { print "ERROR: declined\n"; exit(7); }
if (str_starts_with($first, 'empty')) { exit(0); }
if (str_starts_with($first, 'nonzero')) { print "OK\n"; exit(7); }
if (str_starts_with($first, 'large')) {
    print str_repeat("binary\0payload\n", 65536); fflush(STDOUT);
    $input = $first . stream_get_contents(STDIN);
    print hash('sha256', $input) . "\nOK u:0 s:0 r:0\n"; exit(0);
}
stream_get_contents(STDIN);
print "OK\n";
PHP;
    rrd_windows_check(file_put_contents($directory . '/-', $fixture) === strlen($fixture), 'complete fixture write');
    rrd_windows_check(chdir($directory), 'fixture working directory');
    $command = "large\n" . str_repeat('remaining-input ', 65536) . "\r\nquit\r\n";
    $payload = str_repeat("binary\0payload\n", 65536) . hash('sha256', $command) . "\n";
    $result = $exchange->run([PHP_BINARY, '-'], $command, hrtime(true) + 5000000000, true);
    rrd_windows_check($result === ['completed' => true, 'status' => 0, 'output' => $payload . "OK u:0 s:0 r:0\n"], 'large full duplex and exact input hash');
    $result = $exchange->run([PHP_BINARY, '-'], $command, hrtime(true) + 5000000000, false);
    rrd_windows_check($result === ['completed' => true, 'status' => 0, 'output' => ''], 'discard large output without blocking');
    foreach (['stall', 'stall\n' . str_repeat('input ', 262144)] as $input) {
        $started = hrtime(true);
        $result = $exchange->run([PHP_BINARY, '-'], $input, $started + 400000000, true);
        rrd_windows_check(!$result['completed'] && $result['output'] === '' && hrtime(true) - $started < 3000000000, 'stalled child terminated under deadline');
    }
    $result = $exchange->run([PHP_BINARY, '-'], 'diagnostic', hrtime(true) + 5000000000, true);
    rrd_windows_check($result === ['completed' => true, 'status' => 7, 'output' => "ERROR: declined\n"], 'retain diagnostic and nonzero exit');
    $result = $exchange->run([PHP_BINARY, '-'], 'empty', hrtime(true) + 5000000000, true);
    rrd_windows_check($result === ['completed' => true, 'status' => 0, 'output' => ''], 'successful empty output');
    $result = $exchange->run([PHP_BINARY, '-'], 'stall', hrtime(true) - 1, true);
    rrd_windows_check(!$result['completed'], 'expired deadline refuses start');

    $config = ['cacti_server_os' => 'win32', 'is_web' => false, 'rrd_command_timeout' => 1];
    foreach ([RRDTOOL_OUTPUT_STDOUT, RRDTOOL_OUTPUT_GRAPH_DATA, RRDTOOL_OUTPUT_RETURN_STDERR] as $flag) {
        $result = __rrd_execute($command, false, $flag);
        // __rrd_execute appends its own quit, so compare against the actual input.
        $expected = str_repeat("binary\0payload\n", 65536) . hash('sha256', str_replace(["\r", "\n"], ' ', $command) . "\r\nquit\r\n") . "\n";
        rrd_windows_check($result === $expected, 'production output flag ' . $flag);
    }
    foreach (['ok' => true, 'diagnostic' => false, 'empty' => false, 'nonzero' => false, 'stall' => false] as $input => $expected) {
        rrd_windows_check(__rrd_execute($input, false, RRDTOOL_OUTPUT_BOOLEAN) === $expected, 'production acknowledgement ' . $input);
    }
    rrd_windows_check(rrdtool_last_rejection() === 'declined', 'retain rejection detail');
    ob_start();
    __rrd_execute('diagnostic', false, RRDTOOL_OUTPUT_STDERR);
    $printed = ob_get_clean();
    rrd_windows_check($printed === "ERROR: declined\n", 'production printed diagnostic');
    rrd_windows_check(__rrd_execute($command, false, RRDTOOL_OUTPUT_NULL) === null, 'production discard waits for child');
    $cap = & rrd_command_deadline();
    $cap = hrtime(true) + 200000000;
    $started = hrtime(true);
    rrd_windows_check(__rrd_execute('stall', false, RRDTOOL_OUTPUT_STDOUT) === false && hrtime(true) - $started < 3000000000, 'production poller deadline cap');
    $cap = null;
} finally {
    chdir($previous);
    unlink($directory . '/-');
    rmdir($directory);
}
print 'Verified ' . $count . ' Windows one-shot checks on ' . PHP_OS_FAMILY . " using real child processes.\n";
if ($childCoverage !== null) {
    rrd_windows_check($count === 21, 'complete coverage scenario');
    $childCoverage->stop();
    $report = $argv[2] . '/windows.coverage';
    if (file_put_contents($report, serialize($childCoverage)) === false) {
        throw new RuntimeException('Cannot write child coverage');
    }
    NativeChildCoverageEvidence::write($report, $root, $snapshot, RrdWindowsCoverageRegistration::MARKERS);
}
