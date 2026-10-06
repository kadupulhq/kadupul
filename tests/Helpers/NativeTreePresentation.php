<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

require_once __DIR__ . '/NativeDevicePresentation.php';

/** Share only the canonical inventory database port; tree outcomes are independent. */
final class NativeTreePresentation
{
    public static function sources(): array
    {
        return array_values(array_unique(array_merge(NativeDevicePresentation::sources(), [
            'tree.php', 'tests/Helpers/NativeTreePresentation.php', 'tests/Fixtures/tree-presentation-native.php',
            'tests/Unit/TreePresentationNativeTest.php',
        ])));
    }
    public static function markers(): array
    {
        return ['tree-workflow-completed', 'tree-pdo-outcome-verified', 'tree-adjacent-records-preserved'];
    }
    public static function writes(string $mode): array
    {
        return match ($mode) {
            '' => [],
            'sequence' => ['UPDATE graph_tree SET sequence = ? WHERE sequence = ?', 'UPDATE graph_tree SET sequence = ? WHERE id = ?'],
            'host-sort' => ['UPDATE graph_tree_items SET host_grouping_type = ? WHERE id = ?'],
            'branch-sort' => ['UPDATE graph_tree_items SET sort_children_type = ? WHERE id = ?'],
            default => throw new RuntimeException('Unknown bounded tree write mode'),
        };
    }
    public static function expected(array $before, array $changes): array
    {
        foreach ($changes as $table => $records) {
            if (!in_array($table, ['graph_tree', 'graph_tree_items'], true)) throw new RuntimeException('Unexpected tree mutation table');
            foreach ($records as $id => $fields) {
                $found = false;
                foreach ($before[$table] as &$row) {
                    if ((int) $row['id'] === (int) $id) {
                        foreach ($fields as $field => $value) {
                            if (!array_key_exists($field, $row)) throw new RuntimeException('Unknown tree mutation field');
                            $value = self::value($value);
                            $row[$field] = is_string($row[$field]) ? (string) $value : $value;
                        }
                        $found = true;
                    }
                }
                unset($row);
                if (!$found) throw new RuntimeException('Expected tree mutation target missing');
            }
        }
        return $before;
    }
    public static function database(string $root, string $directory): PDO
    {
        $database = NativeDevicePresentation::database($root, $directory);
        $schema = file_get_contents($root . '/cacti.sql');
        if ($schema === false || preg_match('/CREATE TABLE graph_templates_graph \((.*?)\) ENGINE=[^;]+;/s', $schema, $match) !== 1) {
            throw new RuntimeException('Canonical tree graph table unavailable');
        }
        if ($database->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $database->exec($match[0]);
        } else {
            $columns = [];
            foreach (explode("\n", $match[1]) as $line) {
                $line = rtrim(trim($line), ',');
                if ($line === '' || preg_match('/^(?:KEY|UNIQUE KEY|CONSTRAINT) /i', $line)) continue;
                $line = preg_replace('/\b(?:tinyint|smallint|mediumint|int|bigint)(?:\(\d+\))?(?: unsigned)?/i', 'INTEGER', $line);
                $line = preg_replace('/\b(?:varchar|char)\(\d+\)/i', 'TEXT', $line);
                $line = preg_replace('/\bAUTO_INCREMENT\b|\s+ON UPDATE CURRENT_TIMESTAMP/i', '', $line);
                $columns[] = $line;
            }
            $database->exec('CREATE TABLE graph_templates_graph (' . implode(', ', $columns) . ')');
        }
        return $database;
    }
    public static function insert(PDO $database, string $table, array $values): void
    {
        $values = array_map(self::value(...), $values);
        if ($table !== 'graph_templates_graph') {
            NativeDevicePresentation::insert($database, $table, $values);
        } else {
            $statement = $database->prepare('INSERT INTO graph_templates_graph (' . implode(',', array_keys($values)) . ') VALUES (' . implode(',', array_fill(0, count($values), '?')) . ')');
            $statement->execute(array_values($values));
        }
    }
    private static function value(mixed $value): mixed
    {
        if (is_string($value) && preg_match('/^(?:TREE_ORDERING_|HOST_GROUPING_)[A-Z_]+$/', $value)) {
            if (!defined($value)) throw new RuntimeException('Actual tree ordering constant unavailable');
            return constant($value);
        }
        return $value;
    }
    public static function snapshot(PDO $database): array
    {
        $state = NativeDevicePresentation::snapshot($database);
        $state['graph_templates_graph'] = $database->query('SELECT * FROM graph_templates_graph ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        return $state;
    }
}
