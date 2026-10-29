<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/** A copy of bin/legacy-boost-partition.php beside a fixture install whose poller_boost.php is a stub child. */
function boost_partition_fixture(): string
{
    $root = dirname(__DIR__, 4);
    $dir = sys_get_temp_dir() . '/boost-partition-' . bin2hex(random_bytes(8));
    mkdir($dir . '/bin', 0700, true);
    mkdir($dir . '/include', 0700);
    copy($root . '/bin/legacy-boost-partition.php', $dir . '/bin/legacy-boost-partition.php');
    file_put_contents($dir . '/include/cli_check.php', '<?php require ' . var_export($root . '/tests/Fixtures/boost-partition-bootstrap.php', true) . ';');
    // The real child writes its own status row; the stub does the same.
    file_put_contents($dir . '/poller_boost.php', <<<'PHP'
<?php
file_put_contents(getenv('BOOST_FIXTURE') . '/spawned', json_encode(array_slice($_SERVER['argv'], 1)));
$mode = getenv('BOOST_MODE');
if ($mode === 'hang' || $mode === 'ignore-term') {
    if ($mode === 'ignore-term') {
        pcntl_signal(SIGTERM, SIG_IGN);
    }
    file_put_contents(getenv('BOOST_FIXTURE') . '/child.pid', (string) getmypid());
    sleep(30);
}
$run = substr($_SERVER['argv'][2], strlen('--run='));
$child = substr($_SERVER['argv'][1], strlen('--child='));
$rows = ['success' => '7', 'child-failed' => '-1', 'garbage' => 'many'][$mode] ?? null;
if ($rows !== null) {
    file_put_contents(getenv('BOOST_FIXTURE') . '/status-' . $run . '-' . $child, $rows);
}
exit($mode === 'child-failed' ? 1 : 0);
PHP);

    return $dir;
}

function boost_partition_remove(string $dir): void
{
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($dir);
}

/**
 * Runs the worker once. Returns the marker payload, the stub child's argv (null
 * when it was not started) and the worker's exit status.
 */
function boost_partition_worker_run(string $input, string $mode, string $active): array
{
    $dir = boost_partition_fixture();
    try {
        $process = proc_open(
            array(PHP_BINARY, $dir . '/bin/legacy-boost-partition.php'),
            array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
            $pipes,
            null,
            array_merge(getenv(), array('BOOST_FIXTURE' => $dir, 'BOOST_MODE' => $mode, 'BOOST_ACTIVE_RUN' => $active))
        );
        fwrite($pipes[0], $input);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        preg_match('/^BOOST_PARTITION=(.*)$/m', $output, $match);

        return array(json_decode($match[1] ?? 'null', true), is_file($dir . '/spawned') ? json_decode(file_get_contents($dir . '/spawned'), true) : null, $exit);
    } finally {
        boost_partition_remove($dir);
    }
}

test('partition worker refuses a run the master is not waiting on', function () {
    $run = str_repeat('a', 32);
    [$result, $spawned, $exit] = boost_partition_worker_run(json_encode(array('run' => $run, 'partition' => 2)), 'success', str_repeat('b', 32));

    expect($result)->toBe(array('status' => 'stale'))
        ->and($spawned)->toBeNull()
        ->and($exit)->toBe(0);
});

test('partition worker runs the child for the active run and reports its row count', function () {
    $run = str_repeat('a', 32);
    [$result, $spawned, $exit] = boost_partition_worker_run(json_encode(array('run' => $run, 'partition' => 2)), 'success', $run);

    expect($result)->toBe(array('status' => 'ok', 'rows' => 7))
        ->and($spawned)->toBe(array('--child=2', '--run=' . $run))
        ->and($exit)->toBe(0);
});

test('partition worker reports failure when the child fails or leaves no usable status', function ($mode) {
    $run = str_repeat('a', 32);
    [$result, , $exit] = boost_partition_worker_run(json_encode(array('run' => $run, 'partition' => 1)), $mode, $run);

    expect($result)->toBe(array('status' => 'failed'))->and($exit)->toBe(1);
})->with(array('child-failed', 'no-row', 'garbage', 'hang'));

test('partition worker rejects malformed input before starting a child', function ($input) {
    [$result, $spawned, $exit] = boost_partition_worker_run($input, 'success', str_repeat('a', 32));

    expect($result)->toBe(array('status' => 'failed'))->and($spawned)->toBeNull()->and($exit)->toBe(1);
})->with(array(
    'not json' => 'nope',
    'bad run' => '{"run":"AAAA","partition":1}',
    'newline run' => '{"run":"' . str_repeat('a', 32) . '\n","partition":1}',
    'zero partition' => '{"run":"' . str_repeat('a', 32) . '","partition":0}',
    'string partition' => '{"run":"' . str_repeat('a', 32) . '","partition":"1"}',
    'array' => '[]',
));

test('partition worker stops its child when the consumer terminates it', function ($mode) {
    if (!function_exists('pcntl_signal') || !function_exists('posix_kill')) {
        $this->markTestSkipped('pcntl and posix are required.');
    }
    $run = str_repeat('a', 32);
    $dir = boost_partition_fixture();
    $process = proc_open(
        array(PHP_BINARY, $dir . '/bin/legacy-boost-partition.php'),
        array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
        $pipes,
        null,
        array_merge(getenv(), array('BOOST_FIXTURE' => $dir, 'BOOST_MODE' => $mode, 'BOOST_ACTIVE_RUN' => $run))
    );
    fwrite($pipes[0], json_encode(array('run' => $run, 'partition' => 1)));
    fclose($pipes[0]);
    try {
        $deadline = microtime(true) + 5;
        while (!is_file($dir . '/child.pid') && microtime(true) < $deadline) {
            usleep(20000);
        }
        $pid = (int) @file_get_contents($dir . '/child.pid');
        expect($pid)->toBeGreaterThan(0);
        proc_terminate($process, 15);
        // The worker must not wait out a child that ignores SIGTERM (it sleeps 30 s).
        $deadline = microtime(true) + 10;
        do {
            $state = proc_get_status($process);
            $state['running'] && usleep(50000);
        } while ($state['running'] && microtime(true) < $deadline);
        $exited = !$state['running'];
        $exited || proc_terminate($process, 9);
        $stdout = stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        proc_close($process);
        $exit = $state['exitcode'];
        $gone = false;
        for ($i = 0; $i < 100 && !$gone; $i++) {
            $gone = !posix_kill($pid, 0);
            $gone || usleep(50000);
        }
        expect($exited)->toBeTrue()->and($gone)->toBeTrue()->and($exit)->toBe(1)->and($stdout)->toContain('BOOST_PARTITION={"status":"failed"}');
    } finally {
        if (($pid ?? 0) > 0) {
            @posix_kill($pid, 9);
        }
        boost_partition_remove($dir);
    }
})->with(array('child ends on SIGTERM' => 'hang', 'child ignores SIGTERM' => 'ignore-term'));
