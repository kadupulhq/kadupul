<?php

declare(strict_types=1);
// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

function realtime_server_batch(array $scenario): array
{
    $root = dirname(__DIR__, 4);
    $directory = sys_get_temp_dir() . '/realtime-script-server-' . bin2hex(random_bytes(8));
    if (!mkdir($directory, 0700) || !mkdir($directory . '/script root', 0700)) throw new RuntimeException('Cannot create private script-server fixture');
    $server = <<<'PHP'
<?php
error_reporting(0);
$root = dirname(__DIR__);
$scenario = json_decode(file_get_contents($root . '/scenario.json'), true, 512, JSON_THROW_ON_ERROR);
function event(string $type, mixed $data = null): void
{
    file_put_contents(dirname(__DIR__) . '/events.jsonl', json_encode(['type' => $type, 'pid' => getmypid(), 'data' => $data]) . "\n", FILE_APPEND | LOCK_EX);
}
event('start', array_slice($argv, 1));
if (($scenario['startup'] ?? '') === 'eof') exit(7);
echo ($scenario['startup'] ?? '') === 'wrong' ? "Unexpected startup output\n" : "PHP Script Server has Started - Parent is realtime\n";
fflush(STDOUT);
while (($line = fgets(STDIN, 1024)) !== false) {
    $line = trim($line);
    if ($line === '') continue;
    if ($line === 'quit') { event('quit'); exit(0); }
    event('command', $line);
    if ($line === 'exit') exit(5);
    if (!empty($scenario['loud'])) fwrite(STDERR, str_repeat('diagnostic', 65536));
    if ($line === 'multi') $value = 'in:1 out:2';
    elseif ($line === 'error') $value = 'ERROR: unavailable';
    elseif (preg_match('/^metric ([0-9]+)/', $line, $match)) $value = $match[1];
    else $value = 'U';
    echo $value . "\n";
    fflush(STDOUT);
}
event('eof');
PHP;
    file_put_contents($directory . '/script root/script_server.php', $server);
    if (!empty($scenario['spaced_php'])) {
        if (!symlink(PHP_BINARY, $directory . (!empty($scenario['metachar_php']) ? '/php binary ; $(touch injected)' : '/php binary'))) throw new RuntimeException('Cannot create executable-path fixture');
        $scenario['php_path'] = $directory . (!empty($scenario['metachar_php']) ? '/php binary ; $(touch injected)' : '/php binary');
    }
    file_put_contents($directory . '/scenario.json', json_encode($scenario, JSON_THROW_ON_ERROR));
    $process = null;
    try {
        $process = proc_open([PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'error_reporting=-1', $root . '/tests/Fixtures/realtime-script-server.php', $root, $directory], [0 => ['file', '/dev/null', 'r'], 1 => ['file', $directory . '/output.json', 'w'], 2 => ['file', $directory . '/stderr', 'w']], $pipes, $directory);
        if (!is_resource($process)) throw new RuntimeException('Cannot start script-server scenario');
        $deadline = hrtime(true) + 10000000000;
        do {
            $status = proc_get_status($process);
            if (!$status['running']) break;
            usleep(10000);
        } while (hrtime(true) < $deadline);
        if ($status['running']) {
            proc_terminate($process, 9);
            throw new RuntimeException('Script-server scenario exceeded its test deadline');
        }
        $closed = proc_close($process);
        $process = null;
        $exit = $status['exitcode'] >= 0 ? $status['exitcode'] : $closed;
        $stderr = file_get_contents($directory . '/stderr');
        $output = file_get_contents($directory . '/output.json');
        if ($exit !== 0 || $stderr !== '') throw new RuntimeException($stderr . $output);
        return json_decode($output, true, 512, JSON_THROW_ON_ERROR);
    } finally {
        if (is_resource($process)) {
            proc_terminate($process, 9);
            proc_close($process);
        }
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        rmdir($directory);
    }
}

function realtime_server_scenario(int $sources, int $itemsPerSource = 1): array
{
    $scenario = ['request' => ['host_id' => 7, 'poller_id' => 'BatchA1', 'local_data_ids' => []], 'items' => []];
    for ($source = 0; $source < $sources; $source++) {
        $id = $source + 11;
        $scenario['request']['local_data_ids'][] = $id;
        for ($item = 0; $item < $itemsPerSource; $item++) $scenario['items'][(string) $id][] = ['action' => 'POLLER_ACTION_SCRIPT_PHP', 'arg1' => 'metric ' . ($source * $itemsPerSource + $item), 'rrd_name' => 'value' . $item];
    }
    return $scenario;
}

test('realtime polling reuses one script server for the batch', function (int $sources, int $itemsPerSource): void {
    $scenario = realtime_server_scenario($sources, $itemsPerSource);
    $result = realtime_server_batch($scenario);
    expect($result['exception'])->toBeNull();
    $values = json_decode($result['output'], true, 512, JSON_THROW_ON_ERROR);
    $count = $sources * $itemsPerSource;
    expect(array_column($values, 'value'))->toBe($count === 0 ? [] : array_map('strval', range(0, $count - 1)));
    $starts = array_values(array_filter($result['events'], static fn(array $event): bool => $event['type'] === 'start'));
    $quits = array_values(array_filter($result['events'], static fn(array $event): bool => $event['type'] === 'quit'));
    expect($starts)->toHaveCount($count === 0 ? 0 : 1)
        ->and($quits)->toHaveCount($count === 0 ? 0 : 1)
        ->and($result['open_processes_before_harness_cleanup'])->toBe(0)
        ->and($result['trace']['queries'])->toHaveCount($sources);
    if ($count) expect($starts[0]['data'])->toBe(['realtime', 'BatchA1']);
})->with(['empty' => [0, 1], 'single' => [1, 1], '20 sources' => [20, 1], '20 items in one source' => [1, 20]]);

test('a batch without PHP items never starts the script server', function (): void {
    $scenario = realtime_server_scenario(1, 2);
    $scenario['items'][11][0] = ['action' => 'POLLER_ACTION_SCRIPT', 'arg1' => 'ordinary', 'rrd_name' => 'ordinary'];
    $scenario['items'][11][1] = ['action' => 'POLLER_ACTION_SNMP', 'snmp_version' => 0, 'rrd_name' => 'disabled'];
    $result = realtime_server_batch($scenario);
    expect($result['exception'])->toBeNull()
        ->and($result['events'])->toBe([])
        ->and(array_column(json_decode($result['output'], true, 512, JSON_THROW_ON_ERROR), 'value'))->toBe(['42', 'U'])
        ->and($result['open_processes_before_harness_cleanup'])->toBe(0);
});

test('unavailable or failed startup returns unknown without repeated starts', function (string $failure): void {
    $scenario = realtime_server_scenario(3);
    if (in_array($failure, ['eof', 'wrong'], true)) $scenario['startup'] = $failure;
    else $scenario[$failure] = true;
    $result = realtime_server_batch($scenario);
    expect($result['exception'])->toBeNull()
        ->and(array_column(json_decode($result['output'], true, 512, JSON_THROW_ON_ERROR), 'value'))->toBe(['U', 'U', 'U'])
        ->and($result['trace']['opens'])->toHaveCount($failure === 'disabled' ? 0 : 1)
        ->and($result['open_processes_before_harness_cleanup'])->toBe(0);
})->with(['disabled', 'spawn_failure', 'spawn_warning', 'eof', 'wrong', 'status_failure']);

test('later failures close the batch worker before propagating the exception', function (): void {
    $scenario = realtime_server_scenario(2);
    $scenario['throw_at'] = 12;
    $result = realtime_server_batch($scenario);
    expect($result['exception'])->toBe(RuntimeException::class)
        ->and($result['output'])->toBe('')
        ->and(count(array_filter($result['events'], static fn(array $event): bool => $event['type'] === 'quit')))->toBe(1)
        ->and($result['open_processes_before_harness_cleanup'])->toBe(0);
});

test('the whole selection is validated before starting a worker', function (): void {
    $scenario = realtime_server_scenario(1);
    $scenario['request']['local_data_ids'][] = 'invalid';
    $result = realtime_server_batch($scenario);
    expect($result['exception'])->toBe(InvalidArgumentException::class)
        ->and($result['trace']['queries'])->toBe([])
        ->and($result['events'])->toBe([])
        ->and($result['open_processes_before_harness_cleanup'])->toBe(0);
});

test('denied devices start no worker and read no polling items', function (): void {
    $scenario = realtime_server_scenario(1);
    $scenario['allowed'] = false;
    $result = realtime_server_batch($scenario);
    expect($result['output'])->toBe('[]')
        ->and($result['trace']['queries'])->toBe([])
        ->and($result['events'])->toBe([]);
});

test('invalid commands cannot poison the next response', function (string $command): void {
    $scenario = realtime_server_scenario(1, 2);
    $scenario['items'][11][0]['arg1'] = $command;
    $result = realtime_server_batch($scenario);
    expect($result['exception'])->toBeNull()
        ->and(array_column(json_decode($result['output'], true, 512, JSON_THROW_ON_ERROR), 'value'))->toBe(['U', '1'])
        ->and(array_column(array_values(array_filter($result['events'], static fn(array $event): bool => $event['type'] === 'command')), 'data'))->toBe(['metric 1'])
        ->and($result['open_processes_before_harness_cleanup'])->toBe(0);
})->with(['empty' => '', 'whitespace' => '  ', 'newline' => "metric 0\nmetric 99", 'carriage return' => "metric 0\rmetric 99", 'quit' => 'quit', 'reserved prefix' => 'quitter', 'oversized' => 'metric 7 ' . str_repeat('a', 1015)]);

test('the existing maximum command length and multi-value output are retained', function (): void {
    $scenario = realtime_server_scenario(1, 2);
    $scenario['items'][11][0]['arg1'] = 'metric 7 ' . str_repeat('a', 1014);
    $scenario['items'][11][1]['arg1'] = 'multi';
    $result = realtime_server_batch($scenario);
    expect($result['exception'])->toBeNull()
        ->and(array_column(json_decode($result['output'], true, 512, JSON_THROW_ON_ERROR), 'value'))->toBe(['7', 'in:1 out:2'])
        ->and($result['open_processes_before_harness_cleanup'])->toBe(0);
});

test('script errors do not restart a healthy server', function (): void {
    $scenario = realtime_server_scenario(1, 2);
    $scenario['items'][11][0]['arg1'] = 'error';
    $result = realtime_server_batch($scenario);
    expect($result['exception'])->toBeNull()
        ->and(array_column(json_decode($result['output'], true, 512, JSON_THROW_ON_ERROR), 'value'))->toBe(['U', '1'])
        ->and($result['trace']['opens'])->toHaveCount(1)
        ->and($result['open_processes_before_harness_cleanup'])->toBe(0);
});

test('a dead child cannot consume later commands', function (): void {
    $scenario = realtime_server_scenario(1, 3);
    $scenario['items'][11][1]['arg1'] = 'exit';
    $result = realtime_server_batch($scenario);
    expect($result['exception'])->toBeNull()
        ->and(array_column(json_decode($result['output'], true, 512, JSON_THROW_ON_ERROR), 'value'))->toBe(['0', 'U', 'U'])
        ->and($result['trace']['opens'])->toHaveCount(1)
        ->and($result['open_processes_before_harness_cleanup'])->toBe(0);
});

test('batch stderr cannot fill a pipe', function (): void {
    $scenario = realtime_server_scenario(1, 3);
    $scenario['loud'] = true;
    $result = realtime_server_batch($scenario);
    expect($result['exception'])->toBeNull()
        ->and(array_column(json_decode($result['output'], true, 512, JSON_THROW_ON_ERROR), 'value'))->toBe(['0', '1', '2'])
        ->and($result['open_processes_before_harness_cleanup'])->toBe(0);
});

test('configured executable paths stay literal arguments', function (bool $metacharacters): void {
    $scenario = realtime_server_scenario(1);
    $scenario['spaced_php'] = true;
    $scenario['metachar_php'] = $metacharacters;
    $result = realtime_server_batch($scenario);
    expect($result['exception'])->toBeNull()
        ->and(array_column(json_decode($result['output'], true, 512, JSON_THROW_ON_ERROR), 'value'))->toBe(['0'])
        ->and($result['open_processes_before_harness_cleanup'])->toBe(0)
        ->and($result['trace']['opens'][0])->toBe([$result['php_path'], '-q', $result['script_path'], 'realtime', 'BatchA1'])
        ->and($result['injected_file_exists'])->toBeFalse();
})->with([false, true]);

test('shutdown handles positive short writes and failed writes', function (string $mode): void {
    $scenario = realtime_server_scenario(1);
    $scenario[$mode] = true;
    $result = realtime_server_batch($scenario);
    expect($result['exception'])->toBeNull()
        ->and(array_column(json_decode($result['output'], true, 512, JSON_THROW_ON_ERROR), 'value'))->toBe(['0'])
        ->and($result['open_processes_before_harness_cleanup'])->toBe(0);
    if ($mode === 'shutdown_short') expect($result['trace']['shutdown_writes'])->toBe(3);
})->with(['shutdown_short', 'shutdown_failure']);
