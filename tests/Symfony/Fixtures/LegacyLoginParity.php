<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests\Fixtures;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Symfony\Component\Process\Process;

require_once __DIR__ . '/RealMariaDb.php';

/**
 * The harness shared by the ADR 0010 login parity tests: a throwaway MariaDB
 * schema with the authentication tables from cacti.sql, and the real legacy
 * auth_login.php and include/auth.php served by `php -S` through
 * tests/Fixtures/local-login-parity-router.php.
 */
trait LegacyLoginParity
{
    use RealMariaDb;

    private ?Connection $admin = null;
    private ?Connection $database = null;
    private string $schema = '';
    private string $directory = '';
    private ?Process $server = null;
    private int $port = 0;

    /**
     * @param list<string>          $tables      cacti.sql tables to create
     * @param array<string, string> $settings    rows for the settings table
     * @param array<string, string> $environment extra router environment
     */
    private function bootLegacyLogin(string $prefix, array $tables, array $settings, array $environment = []): void
    {
        $this->admin = $this->realMariaDb(true);
        $parameters = $this->admin->getParams();
        $this->schema = $prefix . '_' . bin2hex(random_bytes(6));
        $this->admin->executeStatement('CREATE DATABASE `' . $this->schema . '` CHARACTER SET utf8mb4');
        $this->database = DriverManager::getConnection(['dbname' => $this->schema] + $parameters);

        $source = file_get_contents(dirname(__DIR__, 3) . '/cacti.sql');
        self::assertIsString($source);
        $mode = (string) $this->database->fetchOne('SELECT @@SESSION.sql_mode');
        $this->database->executeStatement('SET SESSION sql_mode = ' . $this->database->quote(implode(',', array_diff(explode(',', $mode), ['NO_ZERO_DATE', 'NO_ZERO_IN_DATE']))));
        foreach ($tables as $table) {
            self::assertSame(1, preg_match('/^CREATE TABLE `?' . $table . '`? \(.*?^\) ENGINE=[^;]*;/ms', $source, $match), $table);
            $this->database->executeStatement($match[0]);
        }
        $this->database->executeStatement('SET SESSION sql_mode = ' . $this->database->quote($mode));

        $this->directory = sys_get_temp_dir() . '/' . str_replace('_', '-', $prefix) . '-' . bin2hex(random_bytes(6));
        foreach (['', '/sessions', '/include-path'] as $child) {
            mkdir($this->directory . $child, 0700, true);
        }
        file_put_contents($this->directory . '/include-path/global.php', "<?php\n");
        touch($this->directory . '/cacti.log');

        foreach ($settings + ['path_cactilog' => $this->directory . '/cacti.log'] as $name => $value) {
            $this->database->insert('settings', ['name' => $name, 'value' => $value]);
        }

        $this->startServer($parameters, $environment);
    }

    private function tearDownLegacyLogin(): void
    {
        $this->server?->stop(0);
        $this->database?->close();
        if ($this->admin !== null && $this->schema !== '') {
            $this->admin->executeStatement('DROP DATABASE IF EXISTS `' . $this->schema . '`');
            $this->admin->close();
        }
        if ($this->directory !== '' && is_dir($this->directory)) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($this->directory);
        }
    }

    /**
     * @param array<string, mixed>  $parameters
     * @param array<string, string> $environment
     */
    private function startServer(array $parameters, array $environment): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($socket);
        $this->port = (int) substr((string) strrchr((string) stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);

        $root = dirname(__DIR__, 3);
        $this->server = new Process(
            [PHP_BINARY, '-d', 'session.save_path=' . $this->directory . '/sessions', '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', 'error_log=' . $this->directory . '/php-errors.log', '-S', '127.0.0.1:' . $this->port, $root . '/tests/Fixtures/local-login-parity-router.php'],
            $root,
            [
                'KADUPUL_PARITY_STATE' => $this->directory,
                'KADUPUL_PARITY_DB_NAME' => $this->schema,
                'KADUPUL_PARITY_DB_HOST' => (string) ($parameters['host'] ?? '127.0.0.1'),
                'KADUPUL_PARITY_DB_PORT' => (string) ($parameters['port'] ?? 3306),
                'KADUPUL_PARITY_DB_USER' => (string) ($parameters['user'] ?? ''),
                'KADUPUL_PARITY_DB_PASSWORD' => (string) ($parameters['password'] ?? ''),
            ] + $environment,
            null,
            null,
        );
        $this->server->start();
        for ($attempt = 0; $attempt < 100; $attempt++) {
            if (@file_get_contents('http://127.0.0.1:' . $this->port . '/__health') === 'ready') {
                return;
            }
            usleep(50000);
        }
        self::fail('php -S did not start: ' . $this->server->getErrorOutput());
    }

    /**
     * @param array<string, string> $form
     * @param array<string, string> $cookies
     * @param list<string>          $headers
     * @return array{status: int, location: ?string, cookies: array<string, string>, body: string, session: array<string, mixed>, elapsed_ms: int, php_errors: list<string>}
     */
    private function request(string $path, array $form = [], array $cookies = [], array $headers = [], bool $phpErrorsExpected = false): array
    {
        @unlink($this->directory . '/state.json');
        $headers[] = 'Host: 127.0.0.1:' . $this->port;
        if ($cookies !== []) {
            $headers[] = 'Cookie: ' . implode('; ', array_map(static fn(string $name, string $value): string => $name . '=' . rawurlencode($value), array_keys($cookies), $cookies));
        }
        $options = ['ignore_errors' => true, 'follow_location' => 0, 'timeout' => 30, 'method' => $form === [] ? 'GET' : 'POST'];
        if ($form !== []) {
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            $options['content'] = http_build_query($form);
        }
        $options['header'] = implode("\r\n", $headers);
        $body = file_get_contents('http://127.0.0.1:' . $this->port . $path, false, stream_context_create(['http' => $options]));
        self::assertIsString($body);
        /** @var list<string> $http_response_header */
        self::assertSame(1, preg_match('#^HTTP/\S+ (\d{3})#', $http_response_header[0], $status));
        $location = null;
        $set = [];
        foreach (array_slice($http_response_header, 1) as $line) {
            [$name, $value] = array_map('trim', explode(':', $line, 2)) + [1 => ''];
            if (strcasecmp($name, 'Location') === 0) {
                $location = $value;
            } elseif (strcasecmp($name, 'Set-Cookie') === 0) {
                [$pair] = explode(';', $value, 2);
                [$cookie, $content] = explode('=', $pair, 2) + [1 => ''];
                $set[$cookie] = rawurldecode($content);
            }
        }
        $state = json_decode((string) file_get_contents($this->directory . '/state.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($state);
        $errors = is_file($this->directory . '/php-errors.log') ? (string) file_get_contents($this->directory . '/php-errors.log') : '';
        if (!$phpErrorsExpected) {
            self::assertSame('', $errors, 'The legacy request raised PHP errors.');
        }
        @unlink($this->directory . '/php-errors.log');
        // Strip the timestamp, checkout path and line number so a test can pin the message.
        $root = dirname(__DIR__, 3) . '/';
        $errors = array_map(static fn(string $line): string => str_replace($root, '', (string) preg_replace(['/^\[[^\]]+\] /', '/ on line \d+$/'], '', $line)), $errors !== '' ? explode("\n", trim($errors)) : []);

        return ['status' => (int) $status[1], 'location' => $location, 'cookies' => $set, 'body' => $body, 'session' => $state['session'], 'elapsed_ms' => $state['elapsed_ms'], 'php_errors' => $errors];
    }

    /** @return list<array{username: string, user_id: int, result: int, ip: string}> */
    private function userLog(): array
    {
        return array_map(
            static fn(array $row): array => ['username' => (string) $row['username'], 'user_id' => (int) $row['user_id'], 'result' => (int) $row['result'], 'ip' => (string) $row['ip']],
            $this->database->fetchAllAssociative('SELECT username, user_id, result, ip FROM user_log ORDER BY time, result'),
        );
    }

    /** @return list<string> the AUTH lines of cacti.log without their timestamps */
    private function authLog(): array
    {
        $lines = file($this->directory . '/cacti.log', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $auth = [];
        foreach ($lines as $line) {
            if (preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d - AUTH (.*)$/', $line, $match) === 1) {
                $auth[] = $match[1];
            }
        }

        return $auth;
    }

    private function loginError(string $body): string
    {
        self::assertSame(1, preg_match("#<div class='loginErrors'>\s*(.*?)\s*</div>#s", $body, $match), 'The response is not the login page.');

        return $match[1];
    }
}
