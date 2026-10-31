<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-2.0-or-later

// Focused cache serialization boundary: real helper and shipped host/cache DDL.
// RRD path and scheduling are fixed values; no devices or RRD files are accessed.
if (PHP_SAPI !== 'cli') exit(1);
ini_set('zend.exception_ignore_args', '1');
$root = dirname(__DIR__, 2);
require $root . '/tests/Helpers/PhpSource.php';
require $root . '/include/global_constants.php';
if (!defined('SQL_NO_CACHE')) define('SQL_NO_CACHE', '');
$dsn = getenv('KADUPUL_TEST_MYSQL_DSN');
if (!is_string($dsn) || !str_starts_with($dsn, 'mysql:')) throw new RuntimeException('Disposable MySQL connection required.');
// This fixture owns a fresh schema; its scoped account need not enter the CI
// runner's unrelated initial schema before creating the owned one.
$dsn = 'mysql:' . implode(';', array_filter(explode(';', substr($dsn, 6)), static fn(string $part): bool => !str_starts_with($part, 'dbname=')));
$database = new PDO($dsn, getenv('KADUPUL_TEST_MYSQL_ADMIN_USER') ?: (getenv('KADUPUL_TEST_MYSQL_USER') ?: 'root'), getenv('KADUPUL_TEST_MYSQL_ADMIN_PASSWORD') ?: (getenv('KADUPUL_TEST_MYSQL_PASSWORD') ?: ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
$schema = 'cache_null_' . bin2hex(random_bytes(8));
$created = false;
function db_fetch_row_prepared(string $sql, array $parameters): array {
    $statement = $GLOBALS['database']->prepare($sql);
    $statement->execute($parameters);
    return $statement->fetch(PDO::FETCH_ASSOC) ?: [];
}
function db_qstr(mixed $value): string|false { return $value === null ? 'NULL' : $GLOBALS['database']->quote((string) $value); }
function cacti_sizeof(mixed $value): int { return is_array($value) ? count($value) : 0; }
function api_poller_get_rrd_next_step(mixed ...$arguments): int { return 0; }
function get_data_source_path(mixed ...$arguments): string { return '/owned-fixture/cache.rrd'; }
function clean_up_path(string $path): string { return $path; }
try {
    $database->exec('CREATE DATABASE `' . $schema . '`');
    $created = true;
    $database->exec('USE `' . $schema . '`');
    $database->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION'");
    $source = file_get_contents($root . '/cacti.sql');
    if (!is_string($source)) throw new RuntimeException('Schema unavailable.');
    foreach (['host', 'poller_item'] as $table) {
        if (preg_match('/CREATE TABLE `?' . $table . '`?\s*\([^;]*;/s', $source, $match) !== 1) throw new RuntimeException('Actual schema unavailable.');
        $database->exec($match[0]);
    }
    $nullable = ['snmp_community', 'snmp_username', 'snmp_password', 'snmp_auth_protocol', 'snmp_priv_passphrase', 'snmp_priv_protocol', 'snmp_context', 'snmp_engine_id'];
    $database->exec("INSERT INTO host (id, hostname, snmp_version) VALUES (1, 'fixture.invalid', 3)");
    $values = array_fill_keys($nullable, $argv[1] === 'null-host' ? null : 'stored');
    $statement = $database->prepare('UPDATE host SET ' . implode(', ', array_map(static fn(string $column): string => $column . ' = ?', $nullable)) . ' WHERE id = 1');
    $statement->execute(array_values($values));
    $overrides = $argv[1] === 'null-override' ? array_fill_keys($nullable, null) : [];
    if ($argv[1] === 'values') $overrides = ['snmp_username' => '0', 'snmp_password' => 'replacement'];
    if ($argv[1] === 'invalid') $database->exec('UPDATE host SET snmp_version = 0');
    if ($argv[1] === 'invalid-community') $database->exec('UPDATE host SET snmp_version = 2, snmp_community = NULL');
    if ($argv[1] === 'disabled') $database->exec("UPDATE host SET disabled = 'on'");
    $source = file_get_contents($root . '/lib/api_poller.php');
    if (!is_string($source)) throw new RuntimeException('Producer unavailable.');
    eval(test_php_function_source($source, 'api_poller_cache_item_add'));
    $tuple = api_poller_cache_item_add($argv[1] === 'non-device' ? 0 : 1, $overrides, 1, 300, $argv[1] === 'non-device' ? POLLER_ACTION_SCRIPT : POLLER_ACTION_SNMP, 'fixture', 1, '.1.3.6.1.2.1.1.3.0');
    if ($tuple !== null) {
        $prefix = 'INSERT INTO poller_item (local_data_id, poller_id, host_id, action, hostname, snmp_community, snmp_version, snmp_timeout, snmp_username, snmp_password, snmp_auth_protocol, snmp_priv_passphrase, snmp_priv_protocol, snmp_context, snmp_engine_id, snmp_port, rrd_name, rrd_path, rrd_num, rrd_step, rrd_next_step, arg1, arg2, arg3, present) VALUES ';
        $database->exec($prefix . $tuple);
        if ($argv[1] === 'null-override') {
            $next = api_poller_cache_item_add(1, [], 2, 300, POLLER_ACTION_SNMP, 'fixture', 1, '.1.3.6.1.2.1.1.3.0');
            $database->exec($prefix . $next);
        }
    }
    echo json_encode(['database_actor' => $database->query('SELECT CURRENT_USER()')->fetchColumn(), 'numeric' => $database->query('SELECT snmp_version, snmp_port, snmp_timeout FROM poller_item WHERE local_data_id = 1')->fetch(PDO::FETCH_ASSOC), 'retained' => $database->query('SELECT ' . implode(', ', $nullable) . ' FROM poller_item WHERE local_data_id = 2')->fetch(PDO::FETCH_ASSOC), 'record' => $database->query('SELECT ' . implode(', ', $nullable) . ' FROM poller_item WHERE local_data_id = 1')->fetch(PDO::FETCH_ASSOC), 'stored' => $database->query('SELECT ' . implode(', ', $nullable) . ' FROM host')->fetch(PDO::FETCH_ASSOC)], JSON_THROW_ON_ERROR);
} finally {
    if ($created) $database->exec('DROP DATABASE `' . $schema . '`');
}
