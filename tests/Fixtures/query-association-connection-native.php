<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Infrastructure\Legacy;

use PDO;
use PDOStatement;
use RuntimeException;

$root = dirname(__DIR__, 2);
require $root . '/include/vendor/autoload.php';
require $root . '/tests/Helpers/PhpSource.php';
foreach ([
    'lib/api_device.php' => ['api_device_dq_add', 'api_device_dq_change', 'api_device_dq_remove'],
    'lib/poller.php' => ['poller_connect_to_remote', 'poller_push_to_remote_db_connect', 'remote_poller_up'],
    'lib/database.php' => ['db_connect_real'],
] as $path => $functions) {
    $source = file_get_contents($root . '/' . $path);
    if (!is_string($source)) {
        throw new RuntimeException('Missing production connection source');
    }
    foreach ($functions as $function) {
        eval('namespace ' . __NAMESPACE__ . '; use PDO; use RuntimeException;' . \test_php_function_source($source, $function)); // nosemgrep: php.lang.security.eval-use.eval-use
    }
}

final class AssociationDatabase extends PDO
{
    public const TABLES = ['host', 'poller', 'snmp_query', 'host_snmp_query', 'host_snmp_cache', 'poller_reindex'];

    public function __construct(public readonly string $prefix)
    {
        parent::__construct(getenv('KADUPUL_TEST_MYSQL_DSN'), getenv('KADUPUL_TEST_MYSQL_USER') ?: 'root', getenv('KADUPUL_TEST_MYSQL_PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
        $this->exec("SET SESSION sql_mode='NO_ENGINE_SUBSTITUTION'");
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

// Query adapters execute real SQL. Network reindexing is deliberately isolated:
// this contract verifies mapping writes and PDO ownership, not remote HTTP work.
function db_execute_prepared(string $sql, array $params = [], bool $log = true, mixed $connection = false): bool
{
    global $primary, $writeConnections;
    $database = $connection instanceof PDO ? $connection : $primary;
    $writeConnections[] = spl_object_id($database);
    return $database->prepare($sql)->execute($params);
}

function db_fetch_row_prepared(string $sql, array $params): array
{
    global $primary;
    $query = $primary->prepare($sql);
    $query->execute($params);
    return $query->fetch(PDO::FETCH_ASSOC) ?: [];
}

function db_fetch_cell_prepared(string $sql, array $params): mixed
{
    global $primary;
    $query = $primary->prepare($sql);
    $query->execute($params);
    return $query->fetchColumn();
}

function cacti_sizeof(mixed $value): int
{
    return is_array($value) ? count($value) : 0;
}

function read_config_option(string $name): int
{
    return 300;
}

function run_data_query(int $device, int $query): bool
{
    global $reindexes, $refreshFailure;
    $reindexes[] = [$device, $query];
    return !$refreshFailure;
}

function raise_message(...$arguments): never
{
    throw new RuntimeException('Unexpected collector failure');
}

function cacti_log(...$arguments): never
{
    throw new RuntimeException('Unexpected collector connection failure');
}

$operation = $argv[1];
$remoteDevice = $argv[2] === 'remote';
$commit = $argv[3] === 'commit';
$refreshFailure = ($argv[4] ?? '') === 'failed-refresh';
if (($argv[5] ?? '') === 'false-flag') {
    define('KADUPUL_THROW_DATABASE_ERRORS', false);
}
$primary = new AssociationDatabase('association_' . bin2hex(random_bytes(8)) . '_');
$collector = new AssociationDatabase('association_' . bin2hex(random_bytes(8)) . '_');
$config = ['poller_id' => 1];
$writeConnections = [];
$reindexes = [];
$database_sessions = ['collector.invalid:3306:fixture' => $collector];
$databases = [$primary, $collector];
try {
    $schema = file_get_contents($root . '/cacti.sql');
    foreach ($databases as $db) {
        foreach (AssociationDatabase::TABLES as $table) {
            if (!preg_match('/CREATE TABLE `?' . preg_quote($table, '/') . '`? \(.*?;\n/s', $schema, $match)) {
                throw new RuntimeException('Missing install schema table');
            }
            $db->exec($db->sql($match[0]));
        }
        $pollerId = $remoteDevice ? 3 : 1;
        foreach ([
            "INSERT INTO host (id, description, hostname, site_id, poller_id, deleted) VALUES (7,'fixture','127.0.0.1',0,$pollerId,''),(8,'unrelated','127.0.0.1',0,$pollerId,'')",
            "INSERT INTO poller (id, name, dbhost, dbdefault, last_status, disabled) VALUES (3,'collector','collector.invalid','fixture',NOW(),'')",
            "INSERT INTO snmp_query (id, name) VALUES (9,'fixture')",
            'INSERT INTO host_snmp_query (host_id,snmp_query_id,reindex_method) VALUES (7,9,1),(8,9,3)',
            "INSERT INTO host_snmp_cache (host_id,snmp_query_id,field_name,snmp_index,field_value,oid) VALUES (7,9,'ifName','1','eth0','fixture'),(8,9,'ifName','1','eth1','fixture')",
            "INSERT INTO poller_reindex (host_id,data_query_id,arg1) VALUES (7,9,'fixture'),(8,9,'fixture')",
        ] as $sql) {
            $db->exec($db->sql($sql));
        }
    }
    $remote = $remoteDevice ? poller_connect_to_remote(3) : null;
    $sameConnection = !$remoteDevice || ($remote === $collector && poller_push_to_remote_db_connect(7) === $remote);
    $primary->beginTransaction();
    if ($remote !== null) {
        $remote->beginTransaction();
    }
    $writer = new DeviceAssociationWriter();
    $device = new \Kadupul\Inventory\Domain\DeviceAssociations(7, 'fixture', 0, $remoteDevice ? 3 : 1, 0, [9 => 'fixture'], [9 => 1], 'query');
    $change = new \Kadupul\Inventory\Domain\DeviceAssociationChange('query', $operation, 9, 2);
    $writer->apply($primary, $remote, $device, $change);
    $writer->verify($primary, $remote, $device, $change);
    $transactions = [$primary->inTransaction(), $remote === null || $remote->inTransaction()];
    foreach (array_filter([$primary, $remote]) as $db) {
        $commit ? $db->commit() : $db->rollBack();
    }
    $rows = [];
    foreach ($databases as $db) {
        $rows[] = [
            'mapping' => $db->query('SELECT reindex_method FROM host_snmp_query WHERE host_id=7 AND snmp_query_id=9')->fetchColumn(),
            'cache' => (int) $db->query('SELECT COUNT(*) FROM host_snmp_cache WHERE host_id=7 AND snmp_query_id=9')->fetchColumn(),
            'reindex' => (int) $db->query('SELECT COUNT(*) FROM poller_reindex WHERE host_id=7 AND data_query_id=9')->fetchColumn(),
            'unrelated' => (int) $db->query('SELECT reindex_method FROM host_snmp_query WHERE host_id=8 AND snmp_query_id=9')->fetchColumn(),
        ];
    }
    echo json_encode(['same_connection' => $sameConnection, 'transactions' => $transactions, 'connections' => array_values(array_unique($writeConnections)), 'expected_connections' => array_map(spl_object_id(...), array_filter([$primary, $remote])), 'reindexes' => $reindexes, 'rows' => $rows], JSON_THROW_ON_ERROR);
} finally {
    foreach ($databases as $db) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        foreach (array_reverse(AssociationDatabase::TABLES) as $table) {
            $db->exec('DROP TABLE IF EXISTS ' . $db->prefix . $table);
        }
    }
}
