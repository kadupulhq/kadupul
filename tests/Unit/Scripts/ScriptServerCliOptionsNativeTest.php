<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

test('actual CLI option dispatch preserves the startup and exit contract', function (array $arguments, string $expected, string $poller = '1') {
    $root = dirname(__DIR__, 3);
    $directory = sys_get_temp_dir() . '/script-server-options-' . bin2hex(random_bytes(8));
    mkdir($directory . '/include', 0700, true);
    $copy = $directory . '/script_server.php';
    copy($root . '/script_server.php', $copy);
    $this->assertSame(hash_file('sha256', $root . '/script_server.php'), hash_file('sha256', $copy));
    file_put_contents($directory . '/include/cli_check.php', <<<'BOOT'
<?php
$config = ['cacti_server_os' => 'unix'];
define('POLLER_VERBOSITY_DEBUG', 5);
define('POLLER_VERBOSITY_HIGH', 3);
define('COPYRIGHT_YEARS', '2004-2026');
function cacti_log(...$arguments) {}
function read_config_option($name) { return 300; }
function get_cacti_version() { return 'fixture-version'; }
function db_close() { file_put_contents(__DIR__ . '/closed', 'closed'); }
file_put_contents(__DIR__ . '/state.json', json_encode(['environ' => $environ, 'poller' => $poller_id, 'mode' => $conn_mode]));
BOOT);
    $coverage = $this->getTestResultObject()->getCodeCoverage();
    $command = [PHP_BINARY, '-d', 'error_reporting=24575', '-d', 'opcache.jit=0', '-d', 'opcache.jit_buffer_size=0', '-d', 'pcov.directory=/'];
    if ($coverage !== null) {
        file_put_contents($directory . '/coverage.php', '<?php define("RRD_TEST_COVERAGE_DIRECTORY", __DIR__); define("RRD_TEST_CLI_COVERAGE_COPY", ' . var_export($copy, true) . '); define("RRD_TEST_CLI_COVERAGE_SOURCE", ' . var_export($root . '/script_server.php', true) . '); require ' . var_export($root . '/tests/fixtures/rrd-process-coverage.php', true) . ';');
        $command[] = '-d';
        $command[] = 'auto_prepend_file=' . $directory . '/coverage.php';
    }
    try {
        $process = proc_open(array_merge($command, [$copy], $arguments), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $directory, getenv());
        $this->assertIsResource($process);
        fwrite($pipes[0], "quit\n");
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($process), $output . $error);
        $this->assertSame('', $error);
        if ($expected === 'help' || $expected === 'version') {
            $this->assertStringStartsWith('Cacti Script Server, Version fixture-version ', $output);
            $this->assertStringNotContainsString('has Started', $output);
            $this->assertSame($expected === 'help', str_contains($output, 'usage: script_server.php [environ poller_id]'));
            if ($expected === 'help') {
                $this->assertStringContainsString("'realtime', and 'other'", $output);
            }
            $this->assertFileDoesNotExist($directory . '/include/closed');
        } else {
            $this->assertSame('PHP Script Server has Started - Parent is ' . $expected . "\nPHP Script Server Shutdown request received, exiting\n", $output);
            $state = json_decode(file_get_contents($directory . '/include/state.json'), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame($expected, $state['environ']);
            $this->assertSame($poller, (string) $state['poller']);
            $this->assertSame('online', $state['mode']);
            $this->assertFileExists($directory . '/include/closed');
        }
        if ($coverage !== null) {
            $reports = glob($directory . '/*.coverage');
            $this->assertCount(1, $reports);
            $coverage->merge(unserialize(file_get_contents($reports[0])));
        }
    } finally {
        foreach (glob($directory . '/include/*') as $file) {
            unlink($file);
        }
        rmdir($directory . '/include');
        foreach (glob($directory . '/*') as $file) {
            unlink($file);
        }
        rmdir($directory);
    }
})->with([
    [['--environ=spine'], 'spine'],
    [['--environ=realtime', '--poller=1'], 'realtime'],
    [['--environ=realtime', '--poller=2'], 'realtime', '2'],
    [['--environ=cmd', '--poller=1', '--mode=online'], 'cmd'],
    [['--environ=other'], 'other'],
    [['--environ'], 'cmd'],
    [['--environ='], 'cmd'],
    [['--environ', 'spine'], 'cmd'],
    [['--environ=spine', '--environ=realtime'], 'cmd'],
    [['--environ=unknown'], 'cmd'],
    [['--environ=spine', '--environ='], 'cmd'],
    [['--environ=', '--environ=spine'], 'cmd'],
    [['--environ', '--environ=spine'], 'cmd'],
    [['--environ=spine', '--', '--environ='], 'spine'],
    [['--', '--environ='], 'other', '--environ='],
    [['spine', '1'], 'spine'],
    [['spine', '3'], 'spine', '3'],
    [['-v', 'spine', '3'], 'version'],
    [['--version'], 'version'],
    [['-v'], 'version'],
    [['-V'], 'version'],
    [['--help'], 'help'],
    [['-h'], 'help'],
    [['-H'], 'help'],
    [['-h', '-v'], 'help'],
]);
