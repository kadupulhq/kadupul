<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-2.0-or-later

$root = $argv[1] ?? dirname(__DIR__, 2);
require $root . '/lib/session_store.php';
$dsn = getenv('KADUPUL_TEST_MYSQL_DSN');
$user = getenv('KADUPUL_TEST_MYSQL_ADMIN_USER') !== false ? getenv('KADUPUL_TEST_MYSQL_ADMIN_USER') : (getenv('KADUPUL_TEST_MYSQL_USER') ?: 'root');
$password = getenv('KADUPUL_TEST_MYSQL_ADMIN_PASSWORD') !== false ? getenv('KADUPUL_TEST_MYSQL_ADMIN_PASSWORD') : (getenv('KADUPUL_TEST_MYSQL_PASSWORD') ?: '');
$database = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$schema = 'session_budget_test_' . bin2hex(random_bytes(8));
$created = false;
try {
    $database->exec('CREATE DATABASE `' . $schema . '` CHARACTER SET utf8mb4');
    $created = true;
    $database->exec('USE `' . $schema . '`');
    $source = file_get_contents($root . '/cacti.sql');
    if (!is_string($source) || preg_match('/CREATE TABLE `sessions`\s*\([^;]*;/s', $source, $match) !== 1) throw new RuntimeException('Missing shipped sessions schema.');
    $database->exec($match[0]);
    $store = new CactiSessionStore($database, $schema, 'php_serialize', static fn(): PDO => $database);
    $questions = static function () use ($database): int {
        $statement = $database->query("SHOW SESSION STATUS LIKE 'Questions'");
        $row = $statement->fetch(PDO::FETCH_NUM);
        if (!is_array($row) || !isset($row[1])) throw new RuntimeException('Missing native query counter.');
        return (int) $row[1];
    };
    $measurements = [];
    $measure = static function (string $name, Closure $operation) use (&$measurements, $questions): mixed {
        $before = $questions();
        $start = hrtime(true);
        $result = $operation();
        $elapsed = hrtime(true) - $start;
        $measurements[$name] = ['queries' => $questions() - $before - 1, 'nanoseconds' => $elapsed];
        return $result;
    };
    $data = serialize(['sess_user_id' => 42, 'sess_last_activity' => 1000]);
    if (!$measure('open', static fn(): bool => $store->open())) throw new RuntimeException('Open failed.');
    if ($measure('read_absent', static fn(): string|false => $store->read('budget')) !== '') throw new RuntimeException('Fresh read failed.');
    if (!$measure('write_insert', static fn(): bool => $store->write('budget', $data, '127.0.0.1', 'fixture'))) throw new RuntimeException('Insert failed.');
    if ($measure('read_existing', static fn(): string|false => $store->read('budget')) !== $data) throw new RuntimeException('Read failed.');
    if (!$measure('write_existing', static fn(): bool => $store->write('budget', $data, '127.0.0.1', 'fixture'))) throw new RuntimeException('Write failed.');
    if (!$measure('close', static fn(): bool => $store->close())) throw new RuntimeException('Close failed.');
    if (!$measure('confirmation', static fn(): bool => $store->writeConfirmed('budget'))) throw new RuntimeException('Confirmation failed.');
    $large = serialize(['sess_user_id' => 42, 'sess_last_activity' => 2000, 'cache' => str_repeat('x', 8 * 1024 * 1024)]);
    if (!$store->open() || $store->read('large') !== '') throw new RuntimeException('Large admission failed.');
    if (!$measure('write_8m', static fn(): bool => $store->write('large', $large, '127.0.0.1', 'fixture'))) throw new RuntimeException('Large write failed.');
    if ($measure('read_8m', static fn(): string|false => $store->read('large')) !== $large) throw new RuntimeException('Large read failed.');
    $limits = ['open' => 5, 'read_absent' => 11, 'write_insert' => 19, 'read_existing' => 18,
        'write_existing' => 19, 'close' => 0, 'confirmation' => 0, 'write_8m' => 19, 'read_8m' => 18];
    foreach ($limits as $name => $maximum) {
        if ($measurements[$name]['queries'] < 0 || $measurements[$name]['queries'] > $maximum) throw new RuntimeException('Native session query budget exceeded: ' . $name);
    }
    if (memory_get_peak_usage(true) >= 128 * 1024 * 1024) throw new RuntimeException('Native session memory budget exceeded.');
    define('NATIVE_SESSION_SCENARIO_COMPLETED', true);echo json_encode(['checks' => count($limits) + 1, 'measurements' => $measurements, 'peak_bytes' => memory_get_peak_usage(true), 'runtime' => PHP_VERSION], JSON_THROW_ON_ERROR), PHP_EOL;
} finally {
    if ($database->inTransaction()) $database->rollBack();
    if ($created) $database->exec('DROP DATABASE `' . $schema . '`');
}
