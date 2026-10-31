<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-2.0-or-later

test('native cache producer preserves nullable host values under strict SQL', function ($variant) {
    if (!getenv('KADUPUL_TEST_MYSQL_DSN')) $this->markTestSkipped('Disposable database required.');
    $root = dirname(__DIR__, 2);
    $process = proc_open([PHP_BINARY, $root . '/tests/Fixtures/poller-cache-null-native.php', $variant], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    expect(is_resource($process))->toBeTrue();
    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    expect(proc_close($process))->toBe(0, $errors)->and($errors)->toBe('');
    $state = json_decode($output, true, 16, JSON_THROW_ON_ERROR);
    if (in_array($variant, ['invalid', 'invalid-community', 'disabled'], true)) {
        expect($state['record'])->toBeFalse();
    } elseif ($variant === 'non-device') {
        expect(array_unique(array_values($state['record'])))->toBe([''])->and($state['numeric'])->toBe(['snmp_version' => 0, 'snmp_port' => 0, 'snmp_timeout' => 0]);
    } elseif ($variant === 'values') {
        expect($state['record']['snmp_username'])->toBe('0')->and($state['record']['snmp_password'])->toBe('replacement')->and($state['record']['snmp_community'])->toBe('stored')->and($state['stored']['snmp_username'])->toBe('stored');
    } else {
        $expected = array_fill_keys(array_keys($state['record']), '');
        $expected['snmp_context'] = null;
        $expected['snmp_engine_id'] = null;
        expect($state['record'])->toBe($expected)->and(array_unique(array_values($state['stored'])))->toBe([$variant === 'null-host' ? null : 'stored']);
        if ($variant === 'null-override') {
            expect($state['retained'])->toBe($state['stored']);
        }
    }
})->with(['null-host', 'null-override', 'values', 'invalid', 'invalid-community', 'disabled', 'non-device']);
