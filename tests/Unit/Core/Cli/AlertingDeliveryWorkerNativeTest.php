<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

const ALERTING_DELIVERY_WORKER = 'bin/legacy-alerting-deliver.php';

/**
 * A private copy of bin/legacy-alerting-deliver.php and bin/worker-io.php
 * beside a fixture legacy bootstrap and a fixture kernel. Under a coverage
 * run the copy is measured as the real worker (tests/Symfony/WorkerIoCoverage.php).
 */
function alerting_delivery_worker_copy(): string
{
    $root = dirname(__DIR__, 4);
    $dir = realpath(sys_get_temp_dir()) . '/alerting-deliver-' . bin2hex(random_bytes(8));
    foreach (['/bin', '/include', '/config'] as $sub) {
        expect(mkdir($dir . $sub, 0700, true))->toBeTrue();
    }
    expect(copy($root . '/' . ALERTING_DELIVERY_WORKER, $dir . '/' . ALERTING_DELIVERY_WORKER))->toBeTrue()
        ->and(copy($root . '/bin/worker-io.php', $dir . '/bin/worker-io.php'))->toBeTrue()
        ->and(file_put_contents($dir . '/include/cli_check.php', '<?php require ' . var_export($root . '/tests/Fixtures/alerting-delivery-bootstrap.php', true) . ';'))->not->toBeFalse()
        ->and(file_put_contents($dir . '/config/bootstrap.php', '<?php return require ' . var_export($root . '/tests/Fixtures/alerting-delivery-kernel.php', true) . ';'))->not->toBeFalse();

    return $dir;
}

function alerting_delivery_worker_remove(string $dir): void
{
    foreach (['/bin', '/include', '/config', ''] as $sub) {
        foreach (glob($dir . $sub . '/{,.}*', GLOB_BRACE) ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir($dir . $sub);
    }
}

/**
 * The collector options and environment for one scenario, or none outside a
 * coverage run.
 *
 * @return array{list<string>, array<string, string>}
 */
function alerting_delivery_worker_coverage(?object $coverage, string $dir, string $scenario): array
{
    if ($coverage === null) {
        return [[], []];
    }
    require_once dirname(__DIR__, 4) . '/tests/Symfony/WorkerIoCoverage.php';

    return Kadupul\Tests\WorkerIoCoverage::configuration(dirname(__DIR__, 4), $dir, ALERTING_DELIVERY_WORKER, $scenario);
}

/** Merges the scenario's one admitted report into the parent run. */
function alerting_delivery_worker_merge(?object $coverage, string $dir, string $scenario, bool $helperExecuted = true): void
{
    if ($coverage === null) {
        return;
    }
    $reports = glob($dir . '/*.coverage');
    expect($reports)->toHaveCount(1);
    $coverage->merge(Kadupul\Tests\WorkerIoCoverage::load($reports[0], dirname(__DIR__, 4), ALERTING_DELIVERY_WORKER, $scenario, $helperExecuted));
}

/**
 * Runs the copied worker with $input on stdin.
 *
 * @return array{status: int, out: string, err: string, events: list<string>, log: string}
 */
function alerting_delivery_worker_run(?object $coverage, string $input, string $mode = 'ok'): array
{
    $dir = alerting_delivery_worker_copy();
    $scenario = hash('sha256', $mode . "\0" . $input);
    try {
        [$options, $environment] = alerting_delivery_worker_coverage($coverage, $dir, $scenario);
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'pcov.directory=/', '-d', 'pcov.exclude=~/(include/vendor|tests)/~', ...$options, $dir . '/' . ALERTING_DELIVERY_WORKER],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $dir,
            array_merge(getenv(), ['ALERTING_FIXTURE' => $dir, 'ALERTING_MODE' => $mode], $environment),
        );
        expect(is_resource($process))->toBeTrue();
        fwrite($pipes[0], $input);
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        $events = is_file($dir . '/events') ? file($dir . '/events', FILE_IGNORE_NEW_LINES) : [];
        $log = is_file($dir . '/log') ? file_get_contents($dir . '/log') : '';
        alerting_delivery_worker_merge($coverage, $dir, $scenario);

        return ['status' => $status, 'out' => $out, 'err' => $err, 'events' => $events, 'log' => $log];
    } finally {
        alerting_delivery_worker_remove($dir);
    }
}

test('the worker runs the delivery use case from the container and reports its counts', function () {
    $run = alerting_delivery_worker_run($this->getTestResultObject()->getCodeCoverage(), '{"batch_size":20,"budget_seconds":12.5}');

    expect($run['status'])->toBe(0)
        ->and($run['err'])->toBe('')
        ->and($run['out'])->toBe('KADUPUL_ALERTING_DELIVERY_RESULT={"status":"ok","sent":2,"retrying":1,"failed":0,"lost":0,"suppressed":3}' . "\n")
        ->and($run['events'])->toBe(['boot', 'get Kadupul\Alerting\Application\DeliverNotifications', 'deliver [20,12.5]', 'shutdown'])
        ->and($run['log'])->toBe('');
});

test('an integer budget reaches the use case as seconds', function () {
    $run = alerting_delivery_worker_run($this->getTestResultObject()->getCodeCoverage(), '{"batch_size":1,"budget_seconds":0}');

    expect($run['status'])->toBe(0)->and($run['events'])->toContain('deliver [1,0.0]');
});

test('a failed delivery is logged by its class only, shuts the kernel down and exits 1', function () {
    $run = alerting_delivery_worker_run($this->getTestResultObject()->getCodeCoverage(), '{"batch_size":20,"budget_seconds":5}', 'fail');

    expect($run['status'])->toBe(1)
        ->and($run['out'])->toBe('KADUPUL_ALERTING_DELIVERY_RESULT={"status":"failed"}' . "\n")
        ->and($run['events'])->toBe(['boot', 'get Kadupul\Alerting\Application\DeliverNotifications', 'deliver [20,5.0]', 'shutdown'])
        ->and($run['log'])->toBe("ALERTING ERROR: Alert notification delivery failed: RuntimeException\n")
        ->and($run['out'] . $run['log'])->not->toContain('hunter2');
});

test('an invalid command boots nothing and exits 1', function (string $input, string $error) {
    $run = alerting_delivery_worker_run($this->getTestResultObject()->getCodeCoverage(), $input);

    expect($run['status'])->toBe(1)
        ->and($run['out'])->toBe('KADUPUL_ALERTING_DELIVERY_RESULT={"status":"failed"}' . "\n")
        ->and($run['events'])->toBe([])
        ->and($run['log'])->toBe('ALERTING ERROR: Alert notification delivery failed: ' . $error . "\n");
})->with([
    'empty' => ['', 'JsonException'],
    'not json' => ['nope', 'JsonException'],
    'list' => ['[20, 5]', 'InvalidArgumentException'],
    'missing budget' => ['{"batch_size":20}', 'InvalidArgumentException'],
    'extra key' => ['{"batch_size":20,"budget_seconds":5,"force":true}', 'InvalidArgumentException'],
    'keys out of order' => ['{"budget_seconds":5,"batch_size":20}', 'InvalidArgumentException'],
    'zero batch' => ['{"batch_size":0,"budget_seconds":5}', 'InvalidArgumentException'],
    'batch above the cap' => ['{"batch_size":10001,"budget_seconds":5}', 'InvalidArgumentException'],
    'string batch' => ['{"batch_size":"20","budget_seconds":5}', 'InvalidArgumentException'],
    'negative budget' => ['{"batch_size":20,"budget_seconds":-1}', 'InvalidArgumentException'],
    'budget above the cap' => ['{"batch_size":20,"budget_seconds":3601}', 'InvalidArgumentException'],
    'string budget' => ['{"batch_size":20,"budget_seconds":"5"}', 'InvalidArgumentException'],
    'oversized' => ['{"batch_size":20,"budget_seconds":5' . str_repeat(' ', 300) . '}', 'InvalidArgumentException'],
]);

test('a web request for the worker is refused before it reads anything', function () {
    $coverage = $this->getTestResultObject()->getCodeCoverage();
    $dir = alerting_delivery_worker_copy();
    $server = null;
    try {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $message);
        expect($socket)->not->toBeFalse();
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        [$options, $environment] = alerting_delivery_worker_coverage($coverage, $dir, 'cli-server');
        $server = proc_open(
            [PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'pcov.directory=/', '-d', 'pcov.exclude=~/(include/vendor|tests)/~', ...$options, '-S', $address, '-t', $dir],
            [0 => ['pipe', 'r'], 1 => ['file', $dir . '/server.out', 'w'], 2 => ['file', $dir . '/server.err', 'w']],
            $pipes,
            $dir,
            array_merge(getenv(), ['ALERTING_FIXTURE' => $dir, 'ALERTING_MODE' => 'ok'], $environment),
        );
        expect(is_resource($server))->toBeTrue();
        // Connect only once the server says it listens, so no refused connection is recorded.
        $deadline = microtime(true) + 10;
        while (!str_contains((string) @file_get_contents($dir . '/server.err'), 'started') && microtime(true) < $deadline) {
            usleep(10000);
        }
        $connection = stream_socket_client('tcp://' . $address, $errno, $message, 5);
        expect($connection)->toBeResource();
        stream_set_timeout($connection, 5);
        fwrite($connection, 'GET /' . ALERTING_DELIVERY_WORKER . " HTTP/1.0\r\nHost: localhost\r\n\r\n");
        $response = stream_get_contents($connection);
        fclose($connection);
        proc_terminate($server);
        proc_close($server);
        $server = null;

        expect($response)->toBeString();
        [$head, $body] = explode("\r\n\r\n", $response, 2);
        expect($head)->toStartWith('HTTP/1.0 404 Not Found')
            ->and($body)->toBe('')
            ->and(is_file($dir . '/events'))->toBeFalse()
            ->and(is_file($dir . '/log'))->toBeFalse();
        alerting_delivery_worker_merge($coverage, $dir, 'cli-server', false);
    } finally {
        if (is_resource($server)) {
            proc_terminate($server);
            proc_close($server);
        }
        alerting_delivery_worker_remove($dir);
    }
});
