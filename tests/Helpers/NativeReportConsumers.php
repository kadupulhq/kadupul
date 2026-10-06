<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

require_once __DIR__ . '/NativeTreePresentation.php';

/** Preserve the actual SQL predicate while adapting MySQL's parenthesized UNION. */
final class NativeReportConnection
{
    public function __construct(private NativeDeviceConnection $connection) {}
    public function prepare(string $sql): NativeDeviceStatement|LegacyFormGoldenStatement|PDOStatement
    {
        if ($this->connection->database->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
            // The shared read-only port recognizes SELECT by its leading token;
            // MySQL also admits the actual parenthesized branch SELECT unchanged.
            $normalized = trim(preg_replace('/\s+/', ' ', $sql));
            if (str_starts_with($normalized, '(SELECT gti.id, CONCAT(')) {
                $this->connection->queries[] = $normalized;
                return $this->connection->database->prepare($sql);
            }
            return $this->connection->prepare($sql);
        }
        if ($this->connection->database->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' && str_contains($sql, 'UNION (SELECT')) {
            if (!str_starts_with($sql, 'SELECT -1 AS id,') || !str_ends_with($sql, 'ORDER BY name)')) {
                throw new RuntimeException('Unrecognized report UNION dialect port');
            }
            $sql = str_replace('UNION (SELECT', 'UNION SELECT * FROM (SELECT', $sql, $changed);
            if ($changed !== 1) throw new RuntimeException('Ambiguous report UNION dialect port');
        }
        $normalized = trim(preg_replace('/\s+/', ' ', $sql));
        $countWrapper = str_starts_with($normalized, 'SELECT COUNT(*) FROM ((SELECT gti.id, CONCAT(');
        if ($countWrapper) {
            if (!str_ends_with($normalized, ') AS rower')) throw new RuntimeException('Unrecognized branch count wrapper');
            $normalized = substr($normalized, strlen('SELECT COUNT(*) FROM ('), -strlen(') AS rower'));
        }
        if (str_starts_with($normalized, "(SELECT gti.id, CONCAT(")) {
            if (preg_match('/^\((SELECT gti\.id, .*?)\) UNION \( (SELECT gti\.id, .*)\) (.*)$/s', $normalized, $parts) !== 1) {
                throw new RuntimeException('Unrecognized actual branch UNION dialect port');
            }
            // Derived SELECTs retain both original branch predicates and global ordering.
            $sql = 'SELECT * FROM (' . $parts[1] . ') AS native_report_branch UNION SELECT * FROM (' . $parts[2] . ') AS native_report_device ' . $parts[3];
        }
        if ($countWrapper) $sql = 'SELECT COUNT(*) FROM (' . $sql . ') AS rower';
        return $this->connection->prepare($sql);
    }
    public function quote(string $value): string|false
    {
        return $this->connection->quote($value);
    }
    public function inTransaction(): bool
    {
        return $this->connection->inTransaction();
    }
    public function errorCode(): string
    {
        return $this->connection->errorCode();
    }
    public function errorInfo(): array
    {
        return $this->connection->errorInfo();
    }
}

final class NativeReportConsumers
{
    public static function sources(): array
    {
        return array_values(array_unique(array_merge(NativeTreePresentation::sources(), [
            'lib/timespan_settings.php', 'lib/html_reports.php', 'reports_admin.php',
            'tests/Helpers/NativeReportConsumers.php', 'tests/Fixtures/report-consumers-native.php',
            'tests/Unit/ReportConsumersNativeTest.php',
        ])));
    }
    public static function markers(): array
    {
        return ['actual-report-page-completed', 'actual-report-persistence-verified', 'adjacent-report-preserved'];
    }
    public static function extraTables(): array
    {
        return ['reports', 'reports_items', 'user_auth', 'user_auth_realm', 'user_auth_perms',
            'user_auth_group', 'user_auth_group_members', 'user_auth_group_realm', 'user_auth_group_perms'];
    }
    public static function database(string $root, string $directory): PDO
    {
        $database = NativeTreePresentation::database($root, $directory);
        $schema = file_get_contents($root . '/cacti.sql');
        if ($schema === false) throw new RuntimeException('Canonical report schema unavailable');
        foreach (self::extraTables() as $table) {
            if (preg_match('/CREATE TABLE `?' . preg_quote($table, '/') . '`? \((.*?)\)\s+ENGINE=[^;]+;/s', $schema, $match) !== 1) throw new RuntimeException('Missing canonical report table');
            if ($database->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
                $database->exec($match[0]);
            } else {
                $columns = [];
                foreach (explode("\n", $match[1]) as $line) {
                    $line = rtrim(trim($line), ',');
                    if ($line === '' || preg_match('/^(?:KEY|UNIQUE KEY|CONSTRAINT) /i', $line)) continue;
                    $line = preg_replace('/\b(?:tinyint|smallint|mediumint|int|bigint)(?:\(\d+\))?(?: unsigned)?/i', 'INTEGER', $line);
                    $line = preg_replace('/\b(?:varchar|char)\(\d+\)/i', 'TEXT', $line);
                    $columns[] = preg_replace('/\bAUTO_INCREMENT\b|\s+ON UPDATE CURRENT_TIMESTAMP/i', '', $line);
                }
                $database->exec('CREATE TABLE ' . $table . ' (' . implode(', ', $columns) . ')');
            }
        }
        if ($database->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $database->sqliteCreateFunction('CONCAT', static fn(...$values) => in_array(null, $values, true) ? null : implode('', $values));
        }
        return $database;
    }
    public static function snapshot(PDO $database): array
    {
        $state = NativeTreePresentation::snapshot($database);
        foreach (self::extraTables() as $table) {
            $rows = $database->query('SELECT * FROM ' . $table)->fetchAll(PDO::FETCH_ASSOC);
            usort($rows, static fn(array $a, array $b): int => json_encode($a) <=> json_encode($b));
            $state[$table] = $rows;
        }
        return $state;
    }
}
