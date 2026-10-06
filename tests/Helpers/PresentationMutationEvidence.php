<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

require_once __DIR__ . '/PresentationPageEvidence.php';

/** SQLite SELECT rowCount port backed by actual fetched rows, as MySQL PDO requires. */
final class PresentationMutationStatement extends PDOStatement
{
    private ?array $selectedRows = null;

    protected function __construct() {}

    public function execute(?array $params = null): bool
    {
        $result = parent::execute($params);
        $this->selectedRows = $result && preg_match('/^\s*SELECT\b/i', $this->queryString)
            ? parent::fetchAll(PDO::FETCH_ASSOC) : null;
        return $result;
    }

    public function rowCount(): int
    {
        return $this->selectedRows === null ? parent::rowCount() : count($this->selectedRows);
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        if ($this->selectedRows === null) {
            return parent::fetchAll($mode, ...$args);
        }
        if (!in_array($mode, array(PDO::FETCH_ASSOC, PDO::FETCH_DEFAULT, PDO::FETCH_NUM, PDO::FETCH_BOTH), true) || $args !== array()) {
            throw new RuntimeException('Unsupported mutation SELECT result mode');
        }
        $rows = $this->selectedRows;
        $this->selectedRows = array();
        return match ($mode) {
            PDO::FETCH_NUM => array_map(array_values(...), $rows),
            PDO::FETCH_BOTH => array_map(static fn(array $row): array => $row + array_values($row), $rows),
            default => $rows,
        };
    }

    public function fetchColumn(int $column = 0): mixed
    {
        if ($this->selectedRows === null) {
            return parent::fetchColumn($column);
        }
        $row = array_shift($this->selectedRows);
        return $row === null ? false : array_values($row)[$column];
    }
}

final class PresentationMutationDatabase extends PDO
{
    public array $prepared = array();

    public function prepare(string $query, array $options = array()): PDOStatement|false
    {
        $this->prepared[] = trim(preg_replace('/\s+/', ' ', $query));
        return parent::prepare($query, $options);
    }
}

final class PresentationMutationEvidence
{
    public static function sources(): array
    {
        return array_values(array_unique(array_merge(PresentationPageEvidence::sources(), array(
            'cacti.sql', 'color_templates_items.php', 'tests/Helpers/PresentationMutationEvidence.php',
            'tests/Fixtures/presentation-mutations-native.php', 'tests/Unit/PresentationMutationNativeCoverageTest.php',
        ))));
    }

    public static function markers(string $case): array
    {
        return array('original-module-executed:' . $case, 'persisted-outcome-verified:' . $case,
            'adjacent-records-preserved:' . $case, 'mutation-query-budget-verified:' . $case);
    }

    public static function tables(): array
    {
        return array('snmp_query', 'snmp_query_graph', 'snmp_query_graph_rrd', 'snmp_query_graph_rrd_sv',
            'snmp_query_graph_sv', 'host_template_snmp_query', 'host_snmp_query', 'host_snmp_cache',
            'color_template_items', 'settings');
    }

    /**
     * Project the canonical table columns/defaults/primary keys into SQLite.
     * Secondary indexes, engine/collation, unsigned range and ON UPDATE are
     * outside these prepared equality/delete/order characterizations.
     */
    public static function database(string $root, string $directory): PresentationMutationDatabase
    {
        $database = new PresentationMutationDatabase('sqlite:' . $directory . '/mutations.sqlite');
        $database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $database->setAttribute(PDO::ATTR_STATEMENT_CLASS, array(PresentationMutationStatement::class));
        self::createCanonicalTables($database, $root, self::tables());
        return $database;
    }

    public static function createCanonicalTables(PDO $database, string $root, array $tables): void
    {
        $schema = file_get_contents($root . '/cacti.sql');
        if ($schema === false) {
            throw new RuntimeException('Canonical presentation mutation schema unavailable');
        }
        foreach ($tables as $table) {
            if (preg_match('/CREATE TABLE `?' . preg_quote($table, '/') . '`? \((.*?)\)\s+ENGINE=/s', $schema, $match) !== 1) {
                throw new RuntimeException('Canonical mutation table missing: ' . $table);
            }
            $columns = array();
            foreach (explode("\n", $match[1]) as $line) {
                $line = rtrim(trim($line), ',');
                if ($line === '' || preg_match('/^(?:KEY|UNIQUE KEY|CONSTRAINT) /i', $line)) {
                    continue;
                }
                $line = preg_replace('/\b(?:tinyint|smallint|mediumint|int|bigint)\(\d+\)(?: unsigned)?/i', 'INTEGER', $line);
                $line = preg_replace('/\b(?:varchar|char)\(\d+\)/i', 'TEXT', $line);
                $line = preg_replace('/\bAUTO_INCREMENT\b|\s+ON UPDATE CURRENT_TIMESTAMP/i', '', $line);
                $columns[] = $line;
            }
            if ($columns === array()) {
                throw new RuntimeException('Canonical mutation table has no columns');
            }
            $database->exec('CREATE TABLE ' . $table . ' (' . implode(', ', $columns) . ')');
        }
    }

    public static function snapshot(PDO $database): array
    {
        $state = array();
        foreach (self::tables() as $table) {
            $rows = $database->query('SELECT * FROM ' . $table)->fetchAll(PDO::FETCH_ASSOC);
            usort($rows, static fn(array $a, array $b): int => json_encode($a) <=> json_encode($b));
            $state[$table] = $rows;
        }
        return $state;
    }
}
