<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/** Buffered SELECT port matches MySQL rowCount without fabricating result rows. */
final class NativeDeviceStatement
{
    private array $rows = [];
    public function __construct(private PDOStatement $statement, private bool $buffered = true, private ?Closure $onExecute = null) {}
    public function execute(?array $parameters = null): bool
    {
        $result = $this->statement->execute($parameters);
        if ($result && $this->onExecute !== null)($this->onExecute)($parameters ?? []);
        $this->rows = $this->buffered ? $this->statement->fetchAll(PDO::FETCH_ASSOC) : [];
        return $result;
    }
    public function rowCount(): int
    {
        return $this->buffered ? count($this->rows) : $this->statement->rowCount();
    }
    public function fetchAll(int $mode = PDO::FETCH_ASSOC): array
    {
        if (!$this->buffered) return $this->statement->fetchAll($mode);
        $rows = $this->rows;
        $this->rows = [];
        return $mode === PDO::FETCH_BOTH ? array_map(static fn(array $row): array => $row + array_values($row), $rows) : $rows;
    }
    public function fetch(int $mode = PDO::FETCH_ASSOC): array|false
    {
        return $this->buffered ? (array_shift($this->rows) ?? false) : $this->statement->fetch($mode);
    }
    public function fetchColumn(int $column = 0): mixed
    {
        if (!$this->buffered) return $this->statement->fetchColumn($column);
        $row = array_shift($this->rows);
        return $row === null ? false : array_values($row)[$column];
    }
    public function closeCursor(): bool
    {
        return true;
    }
    public function errorCode(): string
    {
        return $this->statement->errorCode();
    }
    public function errorInfo(): array
    {
        return $this->statement->errorInfo();
    }
}

/** Bootstrap/auth metadata retain the existing harness; device queries use actual PDO. */
final class NativeDeviceConnection
{
    public array $queries = [];
    public array $cacheHashes = [];
    public function __construct(public PDO $database) {}
    public function prepare(string $sql): NativeDeviceStatement|LegacyFormGoldenStatement|PDOStatement
    {
        $normalized = trim(preg_replace('/\s+/', ' ', $sql));
        $cacheWrite = $normalized === 'REPLACE INTO user_auth_row_cache (user_id, class, hash, total_rows, time) VALUES (?, ?, ?, ?, FROM_UNIXTIME(?))';
        if (!$cacheWrite && !preg_match('/^(?:SELECT|SHOW)\b/i', $normalized)) {
            throw new RuntimeException('Read-only device presentation attempted a database mutation');
        }
        foreach (NativeDevicePresentation::tables() as $table) {
            if (preg_match('/\b(?:FROM|JOIN|INTO)\s+`?' . preg_quote($table, '/') . '`?\b/i', $normalized)) {
                if (!$cacheWrite && !str_starts_with(strtoupper($normalized), 'SELECT ')) {
                    throw new RuntimeException('Device presentation attempted a database mutation');
                }
                $this->queries[] = $normalized;
                $statement = $this->database->prepare($sql);
                if (str_starts_with($normalized, 'SELECT total_rows, UNIX_TIMESTAMP(time) AS time FROM user_auth_row_cache ')) {
                    return new NativeDeviceStatement($statement, $this->database->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql', function (array $parameters): void {
                        $this->cacheHashes[] = $parameters[2];
                    });
                }
                return $this->database->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? $statement : new NativeDeviceStatement($statement);
            }
        }
        return new LegacyFormGoldenStatement($sql);
    }
    public function quote(string $value): string|false
    {
        return $this->database->quote($value);
    }
    public function inTransaction(): bool
    {
        return $this->database->inTransaction();
    }
    public function errorCode(): string
    {
        return $this->database->errorCode();
    }
    public function errorInfo(): array
    {
        return $this->database->errorInfo();
    }
    public function lastInsertId(): string|false
    {
        return $this->database->lastInsertId();
    }
}

final class NativeDevicePresentation
{
    public static function sources(): array
    {
        require_once __DIR__ . '/PresentationPageEvidence.php';
        return array_values(array_unique(array_merge(PresentationPageEvidence::sources(), [
            'cacti.sql', 'host.php', 'lib/ping.php', 'tests/Helpers/NativeDevicePresentation.php',
            'tests/Fixtures/device-presentation-native.php', 'tests/Unit/DevicePresentationNativeTest.php',
        ])));
    }
    public static function markers(): array
    {
        return ['device-page-rendered', 'device-pdo-queries-completed', 'device-persisted-state-preserved'];
    }
    public static function tables(): array
    {
        return ['host', 'sites', 'poller', 'host_template', 'host_graph', 'graph_local', 'data_local',
            'graph_templates', 'graph_templates_item', 'snmp_query', 'snmp_query_graph', 'host_snmp_query',
            'host_snmp_cache', 'data_template_rrd', 'data_template_data', 'graph_tree', 'graph_tree_items', 'user_auth_row_cache'];
    }
    public static function database(string $root, string $directory): PDO
    {
        $schema = file_get_contents($root . '/cacti.sql');
        if ($schema === false) throw new RuntimeException('Canonical device schema unavailable');
        // Explicit native-MySQL characterization creates and drops only its
        // randomly named schema. It never reuses or clears an existing schema.
        if (getenv('NATIVE_DEVICE_MYSQL_DSN') !== false) {
            $admin = new PDO(getenv('NATIVE_DEVICE_MYSQL_DSN'), getenv('NATIVE_DEVICE_MYSQL_USER'), getenv('NATIVE_DEVICE_MYSQL_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $ownedSchema = 'kadupul_native_device_' . bin2hex(random_bytes(8));
            $admin->exec('CREATE DATABASE `' . $ownedSchema . '`');
            register_shutdown_function(static function () use ($admin, $ownedSchema): void {
                register_shutdown_function(static function () use ($admin, $ownedSchema): void {
                    $admin->exec('DROP DATABASE `' . $ownedSchema . '`');
                });
            });
            $admin->exec('USE `' . $ownedSchema . '`');
            foreach (self::tables() as $table) {
                if (preg_match('/CREATE TABLE `?' . preg_quote($table, '/') . '`? \((.*?)\) ENGINE=[^;]+;/s', $schema, $match) !== 1) {
                    throw new RuntimeException('Canonical native device table missing: ' . $table);
                }
                $admin->exec($match[0]);
            }
            return $admin;
        }
        $database = new PDO('sqlite:' . $directory . '/devices.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $database->sqliteCreateFunction('IF', static fn($condition, $yes, $no) => $condition ? $yes : $no, 3);
        $database->sqliteCreateFunction('UNIX_TIMESTAMP', static fn(...$args) => $args === [] ? time() : (strtotime($args[0]) ?: 0));
        $database->sqliteCreateFunction('FROM_UNIXTIME', static fn($timestamp): string => gmdate('Y-m-d H:i:s', (int) $timestamp), 1);
        foreach (self::tables() as $table) {
            if (preg_match('/CREATE TABLE `?' . preg_quote($table, '/') . '`? \((.*?)\) ENGINE=/s', $schema, $match) !== 1) {
                throw new RuntimeException('Canonical device table missing: ' . $table);
            }
            $columns = [];
            foreach (explode("\n", $match[1]) as $line) {
                $line = rtrim(trim($line), ',');
                if ($line === '' || preg_match('/^(?:KEY|UNIQUE KEY|CONSTRAINT) /i', $line)) continue;
                $line = preg_replace('/\b(?:tinyint|smallint|mediumint|int|bigint)(?:\(\d+\))?(?: unsigned)?/i', 'INTEGER', $line);
                $line = preg_replace('/\b(?:varchar|char)\(\d+\)/i', 'TEXT', $line);
                $line = preg_replace('/\bAUTO_INCREMENT\b|\s+ON UPDATE CURRENT_TIMESTAMP/i', '', $line);
                $columns[] = $line;
            }
            if ($columns === []) throw new RuntimeException('Canonical device table has no columns');
            $database->exec('CREATE TABLE ' . $table . ' (' . implode(', ', $columns) . ')');
        }
        return $database;
    }
    public static function insert(PDO $database, string $table, array $values): void
    {
        if (!in_array($table, self::tables(), true)) throw new RuntimeException('Unknown owned device table');
        $statement = $database->prepare('INSERT INTO ' . $table . ' (' . implode(',', array_keys($values)) . ') VALUES (' . implode(',', array_fill(0, count($values), '?')) . ')');
        $statement->execute(array_values($values));
    }
    public static function snapshot(PDO $database): array
    {
        $result = [];
        foreach (self::tables() as $table) {
            if ($table === 'user_auth_row_cache') continue;
            $rows = $database->query('SELECT * FROM ' . $table)->fetchAll(PDO::FETCH_ASSOC);
            usort($rows, static fn(array $a, array $b): int => json_encode($a) <=> json_encode($b));
            $result[$table] = $rows;
        }
        return $result;
    }
}
