<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

require_once dirname(__DIR__) . '/Helpers/ChildProcessCoverage.php';

function run_receiver_delivery(string $scenario): array
{
    $root = dirname(__DIR__, 2);
    $program = '$argv = ["receiver-delivery", ' . var_export($scenario, true) . '];'
        . 'require ' . var_export($root . '/tests/Fixtures/snmpagent-receiver-delivery.php', true) . ';';
    $registration = child_coverage_registration(
        __FILE__,
        'receiver-delivery',
        ['scenario' => $scenario],
        ['receiver-delivery-confirmed'],
        ['lib/snmpagent.php'],
        ['lib/snmpagent.php', 'tests/Fixtures/snmpagent-receiver-delivery.php', 'cacti.sql']
    );
    $registration['collectorPrelude'] = 'define("SNMP_RECEIVER_DELIVERY_TEST_COVERAGE", true);';
    $command = child_coverage_command([PHP_BINARY, '-d', 'display_errors=stderr', '-r', $program], $coverageDirectory, $registration);
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    expect($process)->toBeResource();
    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    expect(proc_close($process))->toBe(0, $errors . $output)->and($errors)->toBe('');
    $result = json_decode($output, true, 8, JSON_THROW_ON_ERROR);
    child_coverage_collect($coverageDirectory);
    return $result;
}

test('disabled receivers receive neither a trap nor a notification log entry', function () {
    $result = run_receiver_delivery('mixed');
    $targets = implode("\n", $result['traps']);
    expect($result['traps'])->toHaveCount(2)
        ->and($targets)->not->toContain('disabled.example:162')
        ->and($targets)->toContain('enabled.example:162')
        ->and($targets)->toContain('legacy-null.example:162')
        ->and($result['logs'])->toBe([1, 3]);
});

test('an all-disabled receiver set stops before dispatch and log writes', function () {
    $result = run_receiver_delivery('all-disabled');
    expect($result['returned'])->toBeFalse()->and($result['traps'])->toBe([])->and($result['logs'])->toBe([]);
});

test('a receiver disabled between discovery and dispatch is not sent a trap', function () {
    $result = run_receiver_delivery('disabled-after-discovery');
    expect($result['traps'])->toBe([])->and($result['logs'])->toBe([]);
});
