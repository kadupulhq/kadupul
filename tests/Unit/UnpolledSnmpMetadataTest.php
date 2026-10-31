<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-2.0-or-later

test('status-only host selection hands complete SNMP configuration to the real availability probe', function (int $version, bool $respond, string $method): void {
    $process = proc_open(
        array(PHP_BINARY, dirname(__DIR__) . '/Fixtures/unpolled-snmp-metadata.php', dirname(__DIR__, 2), (string) $version, $respond ? 'up' : 'down', $method),
        array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
        $pipes
    );
    if (!is_resource($process)) throw new RuntimeException('Cannot start status-only poller fixture');
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    expect(proc_close($process))->toBe(0);
    expect($error)->toBe('');
    expect(json_decode($output, true, 512, JSON_THROW_ON_ERROR))->toBe(array(
        'queries' => 1, 'session_calls' => 1, 'session_closes' => 1, 'available' => $respond,
    ));
})->with(array(2, 3))->with(array(true, false))->with(array('AVAIL_SNMP_OR_PING', 'AVAIL_SNMP', 'AVAIL_SNMP_GET_NEXT', 'AVAIL_SNMP_GET_SYSDESC'));
