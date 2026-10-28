<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';

/** A copy of bin/legacy-boost-master.php beside a fixture install whose poller_boost.php is a stub master. */
function boost_master_fixture(): string
{
    $root = dirname(__DIR__, 4);
    $dir = sys_get_temp_dir() . '/boost-master-' . bin2hex(random_bytes(8));
    mkdir($dir . '/bin', 0700, true);
    mkdir($dir . '/include', 0700);
    copy($root . '/bin/legacy-boost-master.php', $dir . '/bin/legacy-boost-master.php');
    file_put_contents($dir . '/include/cli_check.php', '<?php require ' . var_export($root . '/tests/Fixtures/boost-partition-bootstrap.php', true) . ';');
    file_put_contents($dir . '/poller_boost.php', <<<'PHP'
<?php
file_put_contents(getenv('BOOST_FIXTURE') . '/spawned', json_encode(array_slice($_SERVER['argv'], 1)));
$mode = getenv('BOOST_MODE');
echo "master output\n";
fwrite(STDERR, "master diagnostics\n");
if ($mode === 'hang' || $mode === 'ignore-term') {
    if ($mode === 'ignore-term') {
        pcntl_signal(SIGTERM, SIG_IGN);
    }
    file_put_contents(getenv('BOOST_FIXTURE') . '/child.pid', (string) getmypid());
    sleep(30);
}
exit($mode === 'fail' ? 1 : 0);
PHP);

    return $dir;
}

function boost_master_remove(string $dir): void
{
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($dir);
}

/** @return array{0: ?array, 1: ?array, 2: int, 3: string, 4: string} marker payload, stub argv, exit status, stdout, stderr */
function boost_master_worker_run(string $input, string $mode): array
{
    $dir = boost_master_fixture();
    try {
        $process = proc_open(
            array(PHP_BINARY, $dir . '/bin/legacy-boost-master.php'),
            array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
            $pipes,
            null,
            array_merge(getenv(), array('BOOST_FIXTURE' => $dir, 'BOOST_MODE' => $mode))
        );
        fwrite($pipes[0], $input);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        preg_match('/^BOOST_MASTER=(.*)$/m', $output, $match);

        return array(json_decode($match[1] ?? 'null', true), is_file($dir . '/spawned') ? json_decode(file_get_contents($dir . '/spawned'), true) : null, $exit, $output, $errors);
    } finally {
        boost_master_remove($dir);
    }
}

test('master worker runs poller_boost.php and streams its output', function () {
    [$result, $spawned, $exit, $output, $errors] = boost_master_worker_run('{"force":false,"debug":false}', 'success');

    expect($result)->toBe(array('status' => 'ok'))
        ->and($spawned)->toBe(array())
        ->and($exit)->toBe(0)
        ->and($output)->toContain("master output\n")
        ->and($errors)->toContain('master diagnostics');
});

test('master worker passes force and debug as separate arguments', function ($input, $expected) {
    [$result, $spawned] = boost_master_worker_run($input, 'success');

    expect($result)->toBe(array('status' => 'ok'))->and($spawned)->toBe($expected);
})->with(array(
    'force' => array('{"force":true,"debug":false}', array('--force')),
    'debug' => array('{"force":false,"debug":true}', array('--debug')),
    'both' => array('{"force":true,"debug":true}', array('--force', '--debug')),
));

test('master worker reports failure when the master exits nonzero', function () {
    [$result, , $exit] = boost_master_worker_run('{"force":false,"debug":false}', 'fail');

    expect($result)->toBe(array('status' => 'failed'))->and($exit)->toBe(1);
});

test('master worker rejects malformed input before starting a master', function ($input) {
    [$result, $spawned, $exit] = boost_master_worker_run($input, 'success');

    expect($result)->toBe(array('status' => 'failed'))->and($spawned)->toBeNull()->and($exit)->toBe(1);
})->with(array(
    'not json' => 'nope',
    'array' => '[]',
    'string force' => '{"force":"1","debug":false}',
    'missing debug' => '{"force":true}',
    'int debug' => '{"force":false,"debug":1}',
));

test('master worker stops its master when terminated', function ($mode) {
    if (!function_exists('pcntl_signal') || !function_exists('posix_kill')) {
        $this->markTestSkipped('pcntl and posix are required.');
    }
    $dir = boost_master_fixture();
    $process = proc_open(
        array(PHP_BINARY, $dir . '/bin/legacy-boost-master.php'),
        array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
        $pipes,
        null,
        array_merge(getenv(), array('BOOST_FIXTURE' => $dir, 'BOOST_MODE' => $mode))
    );
    fwrite($pipes[0], '{"force":false,"debug":false}');
    fclose($pipes[0]);
    try {
        $deadline = microtime(true) + 5;
        while (!is_file($dir . '/child.pid') && microtime(true) < $deadline) {
            usleep(20000);
        }
        $pid = (int) @file_get_contents($dir . '/child.pid');
        expect($pid)->toBeGreaterThan(0);
        proc_terminate($process, 15);
        // The worker must not wait out a master that ignores SIGTERM (it sleeps 30 s).
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
        $gone = false;
        for ($i = 0; $i < 100 && !$gone; $i++) {
            $gone = !posix_kill($pid, 0);
            $gone || usleep(50000);
        }
        expect($exited)->toBeTrue()->and($gone)->toBeTrue()->and($state['exitcode'])->toBe(1)->and($stdout)->toContain('BOOST_MASTER={"status":"failed"}');
    } finally {
        if (($pid ?? 0) > 0) {
            @posix_kill($pid, 9);
        }
        boost_master_remove($dir);
    }
})->with(array('master ends on SIGTERM' => 'hang', 'master ignores SIGTERM' => 'ignore-term'));

/** Runs the real boost_poller_bottom() with its collaborators stubbed; returns the calls it made. */
function boost_poller_bottom_run(?string $scheduler): array
{
    $root = dirname(__DIR__, 4);
    $base = sys_get_temp_dir() . '/boost-bottom-' . bin2hex(random_bytes(8));
    mkdir($base . '/lib', 0700, true);
    file_put_contents($base . '/lib/poller.php', '<?php');
    $script = 'namespace BoostBottomProbe;'
        . '$config = array("base_path" => ' . var_export($base, true) . ', "library_path" => ' . var_export($base . '/lib', true) . ', "cacti_server_os" => "unix"); $calls = array();'
        . 'function read_config_option($name) { return array("boost_rrd_update_enable" => "on", "path_boost_log" => "", "path_php_binary" => "/usr/bin/php")[$name] ?? ""; }'
        . 'function boost_update_snmp_statistics() { $GLOBALS["calls"][] = "statistics"; }'
        . 'function cacti_escapeshellarg($value) { return $value; }'
        . 'function exec_background($command, $args, $redirect) { $GLOBALS["calls"][] = "launch"; }'
        . test_php_function_source(file_get_contents($root . '/lib/boost.php'), 'boost_poller_bottom')
        . test_php_function_source(file_get_contents($root . '/lib/boost.php'), 'boost_debug_log_path')
        . 'boost_poller_bottom(); echo json_encode($calls);';
    $env = getenv();
    unset($env['KADUPUL_BOOST_SCHEDULER']);
    if ($scheduler !== null) {
        $env['KADUPUL_BOOST_SCHEDULER'] = $scheduler;
    }
    try {
        $pipes = array();
        $process = proc_open(array(PHP_BINARY, '-r', $script), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, null, $env);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        expect(proc_close($process))->toBe(0, $error . $output)->and($error)->toBe('');

        return json_decode($output, true, 512, JSON_THROW_ON_ERROR);
    } finally {
        unlink($base . '/lib/poller.php');
        rmdir($base . '/lib');
        rmdir($base);
    }
}

test('poller does not launch Boost when the scheduler owns it', function () {
    // Statistics are not part of the launch and still update every cycle.
    expect(boost_poller_bottom_run('1'))->toBe(array('statistics'));
});

test('poller launches Boost when the scheduler is unset, 0 or not a literal 1', function ($value) {
    expect(boost_poller_bottom_run($value))->toBe(array('statistics', 'launch'));
})->with(array('unset' => null, 'zero' => '0', 'empty' => '', 'true' => 'true'));

test('master worker stops a master that outlives its deadline', function ($mode) {
    if (!function_exists('pcntl_signal') || !function_exists('posix_kill')) {
        $this->markTestSkipped('pcntl and posix are required.');
    }
    $dir = boost_master_fixture();
    $started = microtime(true);
    try {
        // The fixture window is 1 s, so the deadline is 3 s; the stub master sleeps 30 s.
        $process = proc_open(
            array(PHP_BINARY, $dir . '/bin/legacy-boost-master.php'),
            array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
            $pipes,
            null,
            array_merge(getenv(), array('BOOST_FIXTURE' => $dir, 'BOOST_MODE' => $mode))
        );
        fwrite($pipes[0], '{"force":false,"debug":false}');
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        $exit = proc_close($process);
        $pid = (int) file_get_contents($dir . '/child.pid');
        $gone = false;
        for ($i = 0; $i < 100 && !$gone; $i++) {
            $gone = !posix_kill($pid, 0);
            $gone || usleep(50000);
        }
        expect($stdout)->toContain('BOOST_MASTER={"status":"failed"}')
            ->and($exit)->toBe(1)
            ->and($stderr)->toContain('exceeded its deadline')
            ->and($gone)->toBeTrue()
            ->and(microtime(true) - $started)->toBeLessThan(20.0);
    } finally {
        if (($pid ?? 0) > 0) {
            @posix_kill($pid, 9);
        }
        boost_master_remove($dir);
    }
})->with(array('master hangs' => 'hang', 'master ignores SIGTERM' => 'ignore-term'));
