<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-2.0-or-later

test('native cache producer preserves nullable host values under strict SQL', function (string $variant): void {
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


test('cache fixture uses configured nonempty credentials when no administrator override exists', function (): void {
    if (!getenv('KADUPUL_TEST_MYSQL_DSN')) $this->markTestSkipped('Disposable database required.');
    $database = new PDO(getenv('KADUPUL_TEST_MYSQL_DSN'), getenv('KADUPUL_TEST_MYSQL_ADMIN_USER') ?: (getenv('KADUPUL_TEST_MYSQL_USER') ?: 'root'), getenv('KADUPUL_TEST_MYSQL_ADMIN_PASSWORD') ?: (getenv('KADUPUL_TEST_MYSQL_PASSWORD') ?: ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $user = 'cache_fixture_' . bin2hex(random_bytes(8));
    $password = bin2hex(random_bytes(24));
    $account = $database->quote($user) . "@'%'";
    $context = 'cache_fixture_context_' . bin2hex(random_bytes(8));
    $created = false;
    $contextCreated = false;
    try {
        $database->exec('CREATE USER ' . $account . ' IDENTIFIED BY ' . $database->quote($password));
        $created = true;
        $database->exec('GRANT ALL ON `cache_null_%`.* TO ' . $account);
        $database->exec('CREATE DATABASE `' . $context . '`');
        $contextCreated = true;
        $environment = getenv();
        unset($environment['KADUPUL_TEST_MYSQL_ADMIN_USER'], $environment['KADUPUL_TEST_MYSQL_ADMIN_PASSWORD']);
        $environment['KADUPUL_TEST_MYSQL_USER'] = $user;
        $environment['KADUPUL_TEST_MYSQL_PASSWORD'] = $password;
        $parts = array_filter(explode(';', substr($environment['KADUPUL_TEST_MYSQL_DSN'], 6)), static fn(string $part): bool => !str_starts_with($part, 'dbname='));
        $environment['KADUPUL_TEST_MYSQL_DSN'] = 'mysql:' . implode(';', $parts) . ';dbname=' . $context;
        $root = dirname(__DIR__, 2);
        $process = proc_open([PHP_BINARY, $root . '/tests/Fixtures/poller-cache-null-native.php', 'null-host'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $environment);
        expect(is_resource($process))->toBeTrue();
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        expect(proc_close($process))->toBe(0)->and($errors)->toBe('')->and(str_contains($output . $errors, $password))->toBeFalse();
        $state = json_decode($output, true, 16, JSON_THROW_ON_ERROR);
        expect($state['record']['snmp_username'])->toBe('')->and(str_starts_with($state['database_actor'], $user . '@'))->toBeTrue();
    } finally {
        if ($contextCreated) $database->exec('DROP DATABASE `' . $context . '`');
        if ($created) $database->exec('DROP USER ' . $account);
    }
});
