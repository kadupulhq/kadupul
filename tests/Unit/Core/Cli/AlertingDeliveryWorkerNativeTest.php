<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/**
 * Runs a copy of bin/legacy-alerting-deliver.php beside a fixture legacy
 * bootstrap and a fixture kernel.
 *
 * @return array{status: int, out: string, err: string, events: list<string>, log: string}
 */
function alerting_delivery_worker_run(string $input, string $mode = 'ok'): array
{
    $root = dirname(__DIR__, 4);
    $dir = sys_get_temp_dir() . '/alerting-deliver-' . bin2hex(random_bytes(8));
    foreach (['/bin', '/include', '/config'] as $sub) {
        expect(mkdir($dir . $sub, 0700, true))->toBeTrue();
    }
    try {
        expect(copy($root . '/bin/legacy-alerting-deliver.php', $dir . '/bin/legacy-alerting-deliver.php'))->toBeTrue()
            ->and(copy($root . '/bin/worker-io.php', $dir . '/bin/worker-io.php'))->toBeTrue()
            ->and(file_put_contents($dir . '/include/cli_check.php', '<?php require ' . var_export($root . '/tests/Fixtures/alerting-delivery-bootstrap.php', true) . ';'))->not->toBeFalse()
            ->and(file_put_contents($dir . '/config/bootstrap.php', '<?php return require ' . var_export($root . '/tests/Fixtures/alerting-delivery-kernel.php', true) . ';'))->not->toBeFalse();
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, '-d', 'display_errors=stderr', $dir . '/bin/legacy-alerting-deliver.php'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $dir,
            array_merge(getenv(), ['ALERTING_FIXTURE' => $dir, 'ALERTING_MODE' => $mode]),
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

        return ['status' => $status, 'out' => $out, 'err' => $err, 'events' => $events, 'log' => $log];
    } finally {
        foreach (['/bin/legacy-alerting-deliver.php', '/bin/worker-io.php', '/include/cli_check.php', '/config/bootstrap.php', '/events', '/log'] as $file) {
            if (is_file($dir . $file)) {
                unlink($dir . $file);
            }
        }
        foreach (['/bin', '/include', '/config', ''] as $sub) {
            rmdir($dir . $sub);
        }
    }
}

test('the worker runs the delivery use case from the container and reports its counts', function () {
    $run = alerting_delivery_worker_run('{"batch_size":20,"budget_seconds":12.5}');

    expect($run['status'])->toBe(0)
        ->and($run['err'])->toBe('')
        ->and($run['out'])->toBe('KADUPUL_ALERTING_DELIVERY_RESULT={"status":"ok","sent":2,"retrying":1,"failed":0,"lost":0,"suppressed":3}' . "\n")
        ->and($run['events'])->toBe(['boot', 'get Kadupul\Alerting\Application\DeliverNotifications', 'deliver [20,12.5]', 'shutdown'])
        ->and($run['log'])->toBe('');
});

test('an integer budget reaches the use case as seconds', function () {
    $run = alerting_delivery_worker_run('{"batch_size":1,"budget_seconds":0}');

    expect($run['status'])->toBe(0)->and($run['events'])->toContain('deliver [1,0.0]');
});

test('a failed delivery is logged by its class only, shuts the kernel down and exits 1', function () {
    $run = alerting_delivery_worker_run('{"batch_size":20,"budget_seconds":5}', 'fail');

    expect($run['status'])->toBe(1)
        ->and($run['out'])->toBe('KADUPUL_ALERTING_DELIVERY_RESULT={"status":"failed"}' . "\n")
        ->and($run['events'])->toBe(['boot', 'get Kadupul\Alerting\Application\DeliverNotifications', 'deliver [20,5.0]', 'shutdown'])
        ->and($run['log'])->toBe("ALERTING ERROR: Alert notification delivery failed: RuntimeException\n")
        ->and($run['out'] . $run['log'])->not->toContain('hunter2');
});

test('an invalid command boots nothing and exits 1', function (string $input, string $error) {
    $run = alerting_delivery_worker_run($input);

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
