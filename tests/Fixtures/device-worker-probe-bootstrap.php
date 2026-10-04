<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

// Isolated bootstrap for copies of the complete production worker entry points.
// Authorization, revisions, SQL, collector guard and commit/rollback are real;
// network discovery is an owned barrier with a transactional cache write.
$root = getenv('KADUPUL_TEST_PROJECT_ROOT');
require $root . '/include/vendor/autoload.php';
require_once $root . '/tests/Helpers/PhpSource.php';

final class DeviceWorkerProbeDatabase extends PDO
{
    public const TABLES = ['settings', 'user_auth', 'user_auth_realm', 'user_auth_group', 'user_auth_group_members', 'user_auth_group_realm', 'user_auth_perms', 'user_auth_group_perms', 'host', 'graph_local', 'sites', 'poller', 'snmp_query', 'host_snmp_query', 'host_snmp_cache', 'poller_reindex'];

    public function __construct(private string $prefix)
    {
        parent::__construct(getenv('KADUPUL_TEST_MYSQL_DSN'), getenv('KADUPUL_TEST_MYSQL_USER') ?: 'root', getenv('KADUPUL_TEST_MYSQL_PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
        $this->exec("SET SESSION sql_mode='NO_ENGINE_SUBSTITUTION'");
        $this->exec('SET SESSION innodb_lock_wait_timeout=1');
    }

    public function sql(string $sql): string
    {
        return preg_replace_callback('/\b(' . implode('|', self::TABLES) . ')\b/', fn(array $match): string => $this->prefix . $match[1], $sql);
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return parent::prepare($this->sql($query), $options);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        return $fetchMode === null ? parent::query($this->sql($query)) : parent::query($this->sql($query), $fetchMode, ...$fetchModeArgs);
    }
}

function db_fetch_row_prepared(string $sql, array $parameters): array
{
    global $connection;
    $query = $connection->prepare($sql);
    $query->execute($parameters);
    return $query->fetch(PDO::FETCH_ASSOC) ?: [];
}

function db_fetch_cell_prepared(string $sql, array $parameters): mixed
{
    global $connection;
    $query = $connection->prepare($sql);
    $query->execute($parameters);
    return $query->fetchColumn();
}

function db_execute_prepared(string $sql, array $parameters, bool $log = true, mixed $db = false): bool
{
    global $connection;
    return ($db instanceof PDO ? $db : $connection)->prepare($sql)->execute($parameters);
}

function db_execute(string $sql): bool
{
    global $connection;
    return $connection->exec($sql) !== false;
}
function db_begin_transaction(): bool
{
    global $connection;
    return $connection->beginTransaction();
}
function db_commit_transaction(): bool
{
    global $connection;
    return $connection->commit();
}
function db_rollback_transaction(PDO $db): bool
{
    return $db->rollBack();
}
function db_error(): string
{
    return '';
}
function is_error_message(): bool
{
    return false;
}
function read_config_option(string $key): string
{
    return '300';
}
function cacti_log(...$arguments): void {}

function run_data_query(int $host, int $query): bool
{
    global $connection;
    db_execute_prepared('INSERT INTO poller_reindex (host_id,data_query_id,arg1) VALUES (?,?,?)', [$host, $query, 'probe']);
    file_put_contents(getenv('KADUPUL_TEST_PROBE_READY'), 'ready');
    $deadline = microtime(true) + 8;
    while (!is_file(getenv('KADUPUL_TEST_PROBE_RELEASE'))) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Owned probe barrier timed out');
        }
        usleep(10000);
    }
    return file_get_contents(getenv('KADUPUL_TEST_PROBE_RELEASE')) !== 'fail';
}

// PDO-class-only mode lets the parent use the same SQL prefix adapter.
if (getenv('KADUPUL_TEST_PROBE_PARENT') !== '1') {
    ob_start();
    define('HOST_DOWN', 1);
    $config = ['poller_id' => 1, 'url_path' => '/'];
    $database_hostname = 'fixture';
    $database_port = '3306';
    $database_default = 'fixture';
    $connection = new DeviceWorkerProbeDatabase(getenv('KADUPUL_TEST_PROBE_PREFIX'));
    if ($connection->query("SHOW SESSION VARIABLES LIKE 'innodb_snapshot_isolation'")->fetch(PDO::FETCH_ASSOC) !== false) {
        // Exercise MariaDB's newer default even on supported older servers.
        $connection->exec('SET SESSION innodb_snapshot_isolation = ON');
    }
    $database_sessions = ['fixture:3306:fixture' => $connection];
    $_SESSION = [];
    $source = file_get_contents(getenv('KADUPUL_TEST_QUERY_API_SOURCE') ?: $root . '/lib/api_device.php');
    foreach (['api_device_dq_add', 'api_device_dq_change', 'api_device_dq_remove'] as $name) {
        eval(test_php_function_source($source, $name)); // nosemgrep: php.lang.security.eval-use.eval-use
    }
}
