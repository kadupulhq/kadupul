<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-2.0-or-later

test('shipped database session registration uses the native web session engine without warnings', function (): void {
    $dsn = getenv('KADUPUL_TEST_MYSQL_DSN');
    if (!is_string($dsn) || !str_starts_with($dsn, 'mysql:')) $this->markTestSkipped('Disposable MySQL required.');
    $dsn = 'mysql:' . implode(';', array_filter(explode(';', substr($dsn, 6)), static fn(string $part): bool => !str_starts_with($part, 'dbname=')));
    $database = new PDO($dsn, getenv('KADUPUL_TEST_MYSQL_ADMIN_USER') ?: (getenv('KADUPUL_TEST_MYSQL_USER') ?: 'root'), getenv('KADUPUL_TEST_MYSQL_ADMIN_PASSWORD') ?: (getenv('KADUPUL_TEST_MYSQL_PASSWORD') ?: ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $schema = 'session_registration_' . bin2hex(random_bytes(8));
    $root = dirname(__DIR__, 4);
    $server = null;
    $created = false;
    try {
        $database->exec('CREATE DATABASE `' . $schema . '`');
        $created = true;
        $database->exec('USE `' . $schema . '`');
        $sql = file_get_contents($root . '/cacti.sql');
        expect(is_string($sql))->toBeTrue();
        expect(preg_match('/CREATE TABLE `sessions`\s*\([^;]*;/s', $sql, $match))->toBe(1);
        $database->exec($match[0]);
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        expect(is_resource($socket))->toBeTrue();
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $environment = getenv();
        $environment['KADUPUL_SESSION_FIXTURE_DSN'] = $dsn . ';dbname=' . $schema;
        $server = proc_open([getenv('KADUPUL_TEST_SESSION_PHP') ?: PHP_BINARY, '-d', 'error_reporting=32767', '-S', $address, $root . '/tests/fixtures/database-session-registration-web.php'], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']], $pipes, $root, $environment);
        expect(is_resource($server))->toBeTrue();
        for ($attempt = 0; $attempt < 100; $attempt++) {
            $connection = @stream_socket_client('tcp://' . $address, timeout: 0.05);
            if (is_resource($connection)) { fclose($connection); break; }
            usleep(10000);
        }
        foreach (['write' => 42, 'read' => 42, 'gc' => 42, 'destroy' => 42, 'read-empty' => null] as $action => $actor) {
            if ($action === 'gc') $database->exec("INSERT INTO sessions (id, access) VALUES ('expired-owned', 0)");
            $body = file_get_contents('http://' . $address . '/?action=' . $action, false, stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 5]]));
            expect($http_response_header[0])->toContain('200')->and(is_string($body))->toBeTrue();
            expect($body)->toStartWith('{');
            $result = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
            expect($result)->toBe(['module' => 'user', 'actor' => $actor]);
            if ($action === 'write') expect($database->query('SELECT user_id FROM sessions')->fetchColumn())->toBe(42);
            if ($action === 'gc') expect((int) $database->query("SELECT COUNT(*) FROM sessions WHERE id = 'expired-owned'")->fetchColumn())->toBe(0);
            if ($action === 'destroy') expect((int) $database->query('SELECT COUNT(*) FROM sessions')->fetchColumn())->toBe(0);
        }
    } finally {
        if (is_resource($server)) { proc_terminate($server); proc_close($server); }
        if ($created) $database->exec('DROP DATABASE `' . $schema . '`');
    }
});
