<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

require_once dirname(__DIR__, 3) . '/Helpers/RrdCharacterization.php';

beforeEach(function () {
    if (PHP_OS_FAMILY === 'Windows') {
        $this->markTestSkipped('POSIX one-shot process regression tests.');
    }
});

test('one-shot execution drains output while sending the remaining input', function (int $flag) {
    $directory = sys_get_temp_dir() . '/rrd-oneshot-' . bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    $server = $directory . '/rrd-server';
    $source = <<<'PHP'
$first = fgets(STDIN);
echo str_repeat("binary\0payload\n", 32768);
fflush(STDOUT);
$rest = stream_get_contents(STDIN);
file_put_contents(__DIR__ . '/received.sha256', hash('sha256', $first . $rest));
echo hash('sha256', $first . $rest) . "\nOK u:0 s:0 r:0\n";
PHP;
    file_put_contents($server, '#!' . PHP_BINARY . "\n<?php\n" . $source);
    chmod($server, 0700);
    $command = "first\n" . str_repeat('remaining-input ', 65536);
    try {
        $output = rrd_characterization_run($this, [
            'options' => ['path_rrdtool' => $server],
            'calls' => [
                ['fn' => 'pcntl_alarm', 'args' => [5]],
                ['fn' => 'rrdtool_execute', 'args' => [$command, false, $flag], 'config' => ['rrd_command_timeout' => 2], 'base64' => true],
                ['fn' => 'pcntl_alarm', 'args' => [0]],
            ],
        ]);
        $expected = str_repeat("binary\0payload\n", 32768) . hash('sha256', $command . "\r\nquit\r\n") . "\n";
        expect(file_get_contents($directory . '/received.sha256'))->toBe(hash('sha256', $command . "\r\nquit\r\n"));
        $result = $output['results'][1];
        expect($result['diagnostics'])->toBe([]);
        if ($flag === 0 || $flag === 2) {
            expect($result['returned'])->toBeNull()
                ->and($result['printed'])->toBe($flag === 2 ? $expected : '');
        } else {
            expect(base64_decode($result['returned'], true))->toBe($expected)
                ->and($result['printed'])->toBe('');
        }
    } finally {
        if (is_file($directory . '/received.sha256')) {
            unlink($directory . '/received.sha256');
        }
        unlink($server);
        rmdir($directory);
    }
})->with(['discard' => 0, 'stdout' => 1, 'stderr display' => 2, 'graph data' => 3, 'stderr return' => 5]);

test('a completed process retains its diagnostic output on a nonzero exit', function (int $flag) {
    $directory = sys_get_temp_dir() . '/rrd-oneshot-diagnostic-' . bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    $server = $directory . '/rrd-server';
    $source = 'stream_get_contents(STDIN); echo "ERROR: declined\n";';
    if ($flag === 5) {
        $source .= 'fwrite(STDERR, "detail\n");';
    }
    file_put_contents($server, '#!' . PHP_BINARY . "\n<?php\n" . $source . 'exit(7);');
    chmod($server, 0700);
    try {
        $output = rrd_characterization_run($this, ['options' => ['path_rrdtool' => $server], 'calls' => [
            ['fn' => 'rrdtool_execute', 'args' => ['info fixture', false, $flag]],
        ]]);
        expect($output['results'][0]['returned'])->toBe("ERROR: declined\n" . ($flag === 5 ? "detail\n" : ''))
            ->and($output['results'][0]['diagnostics'])->toBe([]);
    } finally {
        unlink($server);
        rmdir($directory);
    }
})->with(['stdout' => 1, 'merged stderr' => 5]);

test('one-shot deadlines reap stalled children and reject partial output', function (string $mode, int $flag, ?int $budget) {
    $directory = sys_get_temp_dir() . '/rrd-oneshot-timeout-' . bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    $server = $directory . '/rrd-server';
    $source = <<<'PHP'
pcntl_async_signals(true);
pcntl_signal(SIGTERM, SIG_IGN);
file_put_contents(__DIR__ . '/pid', (string) getmypid());
$mode = file_get_contents(__DIR__ . '/mode');
if ($mode === 'partial') {
    echo "PARTIAL\0OUTPUT\n";
    fflush(STDOUT);
} elseif ($mode === 'closed-output') {
    fclose(STDOUT);
} elseif ($mode === 'closed-input') {
    fclose(STDIN);
}
while (true) { usleep(10000); }
PHP;
    file_put_contents($server, '#!' . PHP_BINARY . "\n<?php\n" . $source);
    file_put_contents($directory . '/mode', $mode);
    chmod($server, 0700);
    try {
        $call = ['fn' => 'rrdtool_execute', 'args' => [in_array($mode, ['not-reading', 'closed-input'], true) ? str_repeat('x', 1048576) : 'graph fixture', false, $flag],
            'config' => ['rrd_command_timeout' => $budget === null ? 1 : 30]];
        if ($budget !== null) {
            $call['command_budget_ns'] = $budget;
        }
        $start = hrtime(true);
        $scenario = ['options' => ['path_rrdtool' => $server], 'calls' => [
            ['fn' => 'pcntl_alarm', 'args' => [5]], $call, ['fn' => 'pcntl_alarm', 'args' => [0]],
        ]];
        if ($mode === 'export') {
            $items = [rrd_characterization_item(1, 'AREA', rrd_characterization_ds('traffic_in') + ['hex' => '00CF00'])];
            $scenario['files'] = ['router_traffic_11.rrd'];
            $scenario['db'] = rrd_characterization_graph_db(rrd_characterization_graph(), $items);
            $scenario['calls'][1] = ['fn' => 'rrdtool_function_graph', 'args' => [7, 0, [
                'graph_start' => 1700000000, 'graph_end' => 1700003600,
                'export' => true, 'export_filename' => 'rra/export.png',
            ], false, [], 0], 'config' => ['rrd_command_timeout' => 1]];
        }
        $output = rrd_characterization_run($this, $scenario);
        expect((hrtime(true) - $start) / 1e9)->toBeLessThan(4)
            ->and($output['results'][1]['returned'])->toBeFalse()
            ->and($output['results'][1]['printed'])->toBe('');
        $diagnostics = $output['results'][1]['diagnostics'];
        if ($mode === 'closed-input') {
            // The fixture records even the expected, suppressed broken-pipe warning.
            expect($diagnostics)->not->toBeEmpty();
            foreach ($diagnostics as $diagnostic) {
                expect($diagnostic)->toContain('fwrite');
            }
        } else {
            expect($diagnostics)->toBe([]);
        }
        if (is_file($directory . '/pid')) {
            expect(posix_kill((int) file_get_contents($directory . '/pid'), 0))->toBeFalse();
        }
    } finally {
        // The outer alarm also bounds this test against the old implementation.
        // Kill only a still-running child whose command contains this owned path.
        if (is_file($directory . '/pid')) {
            $pid = (int) file_get_contents($directory . '/pid');
            $probe = new Symfony\Component\Process\Process(['ps', '-p', (string) $pid, '-o', 'command=']);
            $probe->run();
            if (str_contains($probe->getOutput(), $server)) {
                posix_kill($pid, SIGKILL);
            }
        }
        foreach (['pid', 'mode', 'rrd-server'] as $name) {
            if (is_file($directory . '/' . $name)) {
                unlink($directory . '/' . $name);
            }
        }
        rmdir($directory);
    }
})->with([
    'silent child ignores SIGTERM' => ['silent', 1, null],
    'child does not read a large input' => ['not-reading', 1, null],
    'child closes input before delivery completes' => ['closed-input', 1, null],
    'partial graph' => ['partial', 3, null],
    'EOF with a live child' => ['closed-output', 0, null],
    'partial returned diagnostic' => ['partial', 5, null],
    'partial displayed diagnostic' => ['partial', 2, null],
    'graph export reports its timeout' => ['export', 0, null],
    'poller budget caps a longer timeout' => ['silent', 1, 250000000],
    'already expired poller budget' => ['silent', 1, -1],
]);

test('real RRDtool can answer a batch larger than both pipe buffers', function () {
    $binary = getenv('RRDTOOL_TEST_BINARY') ?: (is_executable('/usr/bin/rrdtool') ? '/usr/bin/rrdtool' : '/opt/homebrew/bin/rrdtool');
    if (!is_executable($binary)) {
        $this->markTestSkipped('Real RRDtool is required.');
    }
    $directory = sys_get_temp_dir() . '/rrd-oneshot-native-' . bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    try {
        $path = $directory . '/sample.rrd';
        $create = new Symfony\Component\Process\Process([$binary, 'create', $path, '--start', '1700000000', 'DS:value:GAUGE:600:U:U', 'RRA:AVERAGE:0.5:1:10']);
        $create->mustRun();
        $command = rtrim(str_repeat("last '$path'\n", 8000), "\n");
        $output = rrd_characterization_run($this, ['options' => ['path_rrdtool' => $binary], 'calls' => [
            ['fn' => 'pcntl_alarm', 'args' => [15]],
            ['fn' => 'rrdtool_execute', 'args' => [$command, false, 1], 'config' => ['rrd_command_timeout' => 10]],
            ['fn' => 'pcntl_alarm', 'args' => [0]],
        ]]);
        $result = $output['results'][1];
        expect($result['diagnostics'])->toBe([])
            ->and(preg_match_all('/^1700000000$/m', $result['returned']))->toBe(8000)
            ->and(preg_match_all('/^OK u:/m', $result['returned']))->toBe(7999)
            ->and($result['returned'])->not->toContain('ERROR:');
    } finally {
        if (is_file($directory . '/sample.rrd')) {
            unlink($directory . '/sample.rrd');
        }
        rmdir($directory);
    }
});
