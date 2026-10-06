<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/** Buffered SELECT port matches MySQL rowCount without fabricating result rows. */
final class NativeAutomationStatement
{
    private array $rows = [];
    private int $affected = 0;
    public function __construct(private PDOStatement $statement, private bool $write = false) {}
    public function execute(?array $parameters = null): bool
    {
        $result = $this->statement->execute($parameters);
        $this->affected = $result ? $this->statement->rowCount() : 0;
        $this->rows = !$result || $this->write ? [] : $this->statement->fetchAll(PDO::FETCH_ASSOC);
        return $result;
    }
    public function rowCount(): int
    {
        return $this->write ? $this->affected : count($this->rows);
    }
    public function fetchAll(int $mode = PDO::FETCH_ASSOC): array
    {
        $rows = $this->rows;
        $this->rows = [];
        return $mode === PDO::FETCH_BOTH ? array_map(static fn(array $row): array => $row + array_values($row), $rows) : $rows;
    }
    public function fetch(int $mode = PDO::FETCH_ASSOC): array|false
    {
        return array_shift($this->rows) ?? false;
    }
    public function fetchColumn(int $column = 0): mixed
    {
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

/** Bootstrap/auth metadata retain the existing harness; automation queries use actual PDO. */
final class NativeAutomationConnection
{
    public array $queries = [];
    public array $domainQueries = [];
    public function __construct(public PDO $database, private array $writable = []) {}
    public function prepare(string $sql): NativeAutomationStatement|LegacyFormGoldenStatement
    {
        $normalized = trim(preg_replace('/\s+/', ' ', $sql));
        $this->queries[] = $normalized;
        $verb = strtoupper(strtok($normalized, ' '));
        $write = !in_array($verb, ['SELECT', 'SHOW'], true);
        if ($write && preg_match('/^(?:INSERT(?: IGNORE)? INTO|UPDATE|DELETE FROM) `?([a-z_]+)`?\b/i', $normalized, $target) !== 1) {
            throw new RuntimeException('Unregistered automation statement type');
        }
        if ($write && !in_array($target[1], array_merge($this->writable, ['user_auth_row_cache']), true)) {
            throw new RuntimeException('Unregistered automation write target');
        }
        if ($verb === 'SHOW' && preg_match('/^SHOW COLUMNS FROM `?([a-z_]+)`?$/i', $normalized, $catalog) === 1 && in_array($catalog[1], NativeAutomationPresentation::tables(), true)) {
            $this->domainQueries[] = $normalized;
            $query = $this->database->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
                ? "SELECT name AS Field, type AS Type, CASE \"notnull\" WHEN 1 THEN 'NO' ELSE 'YES' END AS \"Null\", dflt_value AS \"Default\", '' AS Extra FROM pragma_table_info('" . $catalog[1] . "')" : $sql;
            return new NativeAutomationStatement($this->database->prepare($query));
        }
        foreach (NativeAutomationPresentation::tables() as $table) {
            if (preg_match('/\b(?:FROM|JOIN|UPDATE|INTO)\s+`?' . preg_quote($table, '/') . '`?\b/i', $normalized)) {
                $this->domainQueries[] = $normalized;
                // MySQL resolves this unqualified ORDER BY through the projected
                // name; make that same output alias explicit for SQLite.
                if ($this->database->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' && str_contains($sql, 'SELECT agr.id, agr.name,')) {
                    $sql = str_replace('SELECT agr.id, agr.name,', 'SELECT agr.id, agr.name AS name,', $sql);
                }
                if ($this->database->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' && str_contains($sql, 'SELECT atr.id, atr.name,')) {
                    $sql = str_replace('SELECT atr.id, atr.name,', 'SELECT atr.id, atr.name AS name,', $sql);
                }
                if ($this->database->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' && $write && $target[1] === 'automation_graph_rule_items' && str_contains($sql, ' ON DUPLICATE KEY UPDATE ')) {
                    // This canonical table has only its id primary key. Preserve
                    // the original insert-or-update values on that same key.
                    $sql = str_replace(' ON DUPLICATE KEY UPDATE ', ' ON CONFLICT(`id`) DO UPDATE SET ', $sql);
                    $sql = preg_replace('/VALUES\((`[a-zA-Z_][a-zA-Z_0-9]*`)\)/', 'excluded.$1', $sql);
                }
                return new NativeAutomationStatement($this->database->prepare($sql), $write);
            }
        }
        if ($write) throw new RuntimeException('Automation write did not reach an owned table');
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

final class NativeAutomationPresentation
{
    public static function sources(): array
    {
        require_once __DIR__ . '/PresentationPageEvidence.php';
        return array_values(array_unique(array_merge(PresentationPageEvidence::sources(), [
            'cacti.sql', 'automation_devices.php', 'automation_graph_rules.php', 'automation_snmp.php', 'automation_templates.php', 'automation_tree_rules.php', 'lib/ping.php', 'tests/Helpers/NativeAutomationPresentation.php',
            'tests/Fixtures/automation-presentation-native.php', 'tests/Unit/AutomationPresentationNativeTest.php',
        ])));
    }
    public static function markers(bool $post = false): array
    {
        return array_merge(['automation-page-rendered', 'automation-pdo-queries-completed', 'automation-persisted-outcome-verified'], $post ? ['automation-post-token-checked'] : []);
    }
    public static function tables(): array
    {
        return ['automation_devices', 'automation_networks', 'automation_graph_rules', 'automation_graph_rule_items', 'automation_snmp', 'automation_snmp_items', 'automation_templates', 'automation_tree_rules', 'automation_tree_rule_items', 'automation_match_rule_items', 'host_template', 'snmp_query', 'snmp_query_graph', 'graph_tree', 'graph_tree_items', 'host', 'host_snmp_cache', 'graph_local', 'graph_templates', 'graph_templates_graph', 'user_auth_row_cache'];
    }
    public static function database(string $root, string $directory): PDO
    {
        $schema = file_get_contents($root . '/cacti.sql');
        if ($schema === false) throw new RuntimeException('Canonical automation schema unavailable');
        // Explicit native-MySQL characterization creates and drops only its
        // randomly named schema. It never reuses or clears an existing schema.
        if (getenv('NATIVE_AUTOMATION_MYSQL_DSN') !== false) {
            $admin = new PDO(getenv('NATIVE_AUTOMATION_MYSQL_DSN'), getenv('NATIVE_AUTOMATION_MYSQL_USER'), getenv('NATIVE_AUTOMATION_MYSQL_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $ownedSchema = 'kadupul_native_automation_' . bin2hex(random_bytes(8));
            $admin->exec('CREATE DATABASE `' . $ownedSchema . '`');
            register_shutdown_function(static function () use ($admin, $ownedSchema): void {
                register_shutdown_function(static function () use ($admin, $ownedSchema): void {
                    $admin->exec('DROP DATABASE `' . $ownedSchema . '`');
                });
            });
            $admin->exec('USE `' . $ownedSchema . '`');
            $admin->exec("SET SESSION time_zone='+00:00'");
            foreach (self::tables() as $table) {
                if (preg_match('/CREATE TABLE `?' . preg_quote($table, '/') . '`? \((.*?)\) ENGINE=[^;]+;/s', $schema, $match) !== 1) {
                    throw new RuntimeException('Canonical native automation table missing: ' . $table);
                }
                $admin->exec($match[0]);
            }
            return $admin;
        }
        $database = new PDO('sqlite:' . $directory . '/automations.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $database->sqliteCreateFunction('INET_ATON', static function ($value): ?int {
            $address = ip2long($value);
            return $address === false ? null : (int) sprintf('%u', $address);
        }, 1);
        $database->sqliteCreateFunction('FROM_UNIXTIME', static fn($value) => date('Y-m-d H:i:s', (int) $value), 1);
        $database->sqliteCreateFunction('IF', static fn($condition, $yes, $no) => $condition ? $yes : $no, 3);
        $database->sqliteCreateFunction('UNIX_TIMESTAMP', static fn(...$args) => $args === [] ? time() : (strtotime($args[0]) ?: 0));
        foreach (self::tables() as $table) {
            if (preg_match('/CREATE TABLE `?' . preg_quote($table, '/') . '`? \((.*?)\) ENGINE=/s', $schema, $match) !== 1) {
                throw new RuntimeException('Canonical automation table missing: ' . $table);
            }
            $columns = [];
            foreach (explode("\n", $match[1]) as $line) {
                $line = rtrim(trim($line), ',');
                if ($line === '' || preg_match('/^(?:KEY|UNIQUE KEY|CONSTRAINT) /i', $line)) continue;
                $line = preg_replace('/\b(?:tinyint|smallint|mediumint|int|bigint)(?:\(\d+\))?(?: unsigned)?/i', 'INTEGER', $line);
                $line = preg_replace('/\b(?:varchar|char)\(\d+\)/i', 'TEXT', $line);
                $line = preg_replace("/\\s+COMMENT\\s+'(?:[^']|'')*'/i", '', $line);
                $line = preg_replace('/\bAUTO_INCREMENT\b|\s+ON UPDATE CURRENT_TIMESTAMP/i', '', $line);
                $columns[] = $line;
            }
            if ($columns === []) throw new RuntimeException('Canonical automation table has no columns');
            $database->exec('CREATE TABLE ' . $table . ' (' . implode(', ', $columns) . ')');
        }
        return $database;
    }
    public static function insert(PDO $database, string $table, array $values): void
    {
        if (!in_array($table, self::tables(), true)) throw new RuntimeException('Unknown owned automation table');
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
