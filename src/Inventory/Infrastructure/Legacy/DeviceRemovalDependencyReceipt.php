<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Infrastructure\Legacy;

use PDO;
use RuntimeException;

/** Keeps dependency identities observable after the legacy lifecycle deletes parents. */
final readonly class DeviceRemovalDependencyReceipt
{
    /**
     * Holds the captured ids and rows; use capture() to build one.
     */
    private function __construct(private array $checks, private array $rrds, private array $graphs, private array $templates, private array $scopeRows, private array $inputFields) {}

    /**
     * Locks and records the dependent rows of the reviewed graphs and data sources, so the removal can later prove it deleted exactly those.
     *
     * @param array $graphs Reviewed graph ids.
     * @param array $data Reviewed data source ids.
     * @throws \LogicException When no transaction is active.
     * @throws RuntimeException When a dependent cannot be read.
     */
    public static function capture(PDO $db, array $graphs, array $data): self
    {
        $checks = [];
        $scopeRows = [];
        $templates = $rrds = [];
        foreach ([
            ['data_template_data', 'local_data_id', 'data_local', $data, ['id', 'local_data_id']],
            ['data_template_rrd', 'local_data_id', 'data_local', $data, ['id', 'local_data_id']],
            ['graph_templates_item', 'local_graph_id', 'graph_local', $graphs, ['id', 'local_graph_id', 'task_item_id']],
            ['graph_templates_graph', 'local_graph_id', 'graph_local', $graphs, ['id', 'local_graph_id']],
        ] as [$table, $column, $ownerTable, $parents, $identityColumns]) {
            $ids = self::read($db, $table, 'id', $column, $parents);
            $checks[] = [$table, $column, $parents];
            $checks[] = [$table, 'id', $ids];
            $scopeRows[] = [$table, $column, $ownerTable, $parents, $identityColumns, self::readRows($db, $table, $identityColumns, $column, $parents)];
            if ($table === 'data_template_data') {
                $templates = $ids;
            } elseif ($table === 'data_template_rrd') {
                $rrds = $ids;
            }
        }
        $checks[] = ['data_input_data', 'data_template_data_id', $templates];
        $checks[] = ['graph_templates_item', 'task_item_id', $rrds];
        return new self($checks, $rrds, $graphs, $templates, $scopeRows, self::readPairs($db, $templates));
    }

    /**
     * Checks that the dependents have not changed since capture: no rows added or lost, and no outside graph references the reviewed data.
     * Rows whose owner has already been deleted are allowed to be gone.
     *
     * @throws RuntimeException When the reviewed scope changed.
     */
    public function assertExclusive(PDO $db): void
    {
        foreach ($this->scopeRows as [$table, $parentColumn, $ownerTable, $ownerIds, $identityColumns, $capturedRows]) {
            $existingOwners = self::read($db, $ownerTable, 'id', 'id', $ownerIds);
            $expectedRows = array_values(array_filter($capturedRows, static fn(array $row): bool => in_array($row[$parentColumn], $existingOwners, true)));
            if (self::readRows($db, $table, $identityColumns, $parentColumn, $ownerIds) !== $expectedRows) {
                throw new RuntimeException('Reviewed dependent scope changed');
            }
        }
        foreach (self::read($db, 'graph_templates_item', 'local_graph_id', 'task_item_id', $this->rrds) as $graph) {
            if (!in_array($graph, $this->graphs, true)) {
                throw new RuntimeException('Reviewed data acquired an outside graph reference');
            }
        }
        $existingTemplates = self::read($db, 'data_template_data', 'id', 'id', $this->templates);
        $expected = array_values(array_filter($this->inputFields, static fn(array $field): bool => in_array($field['data_template_data_id'], $existingTemplates, true)));
        if (self::readPairs($db, $existingTemplates) !== $expected) {
            throw new RuntimeException('Reviewed input field scope changed');
        }
    }

    /**
     * Checks that every captured dependent row has been deleted.
     *
     * @throws RuntimeException When any remains.
     */
    public function assertPurged(PDO $db): void
    {
        foreach ($this->checks as [$table, $column, $ids]) {
            if (self::read($db, $table, $column, $column, $ids) !== []) {
                throw new RuntimeException('Reviewed primary dependent remains');
            }
        }
        foreach ($this->inputFields as $field) {
            $query = $db->prepare('SELECT COUNT(*) FROM data_input_data WHERE data_template_data_id = ? AND data_input_field_id = ? FOR UPDATE');
            if (!$query || !$query->execute([$field['data_template_data_id'], $field['data_input_field_id']]) || (int) $query->fetchColumn() !== 0) {
                throw new RuntimeException('Reviewed primary dependent remains');
            }
        }
    }

    /**
     * Locks rows matching the given parent ids and returns one column as integers.
     *
     * @param array $ids Parent ids; empty returns nothing.
     * @return array List of int.
     * @throws \LogicException When no transaction is active.
     * @throws RuntimeException When the rows cannot be read.
     */
    private static function read(PDO $db, string $table, string $select, string $column, array $ids): array
    {
        if (!$db->inTransaction()) {
            throw new \LogicException('Dependency receipts require a transaction');
        }
        if ($ids === []) {
            return [];
        }
        $query = $db->prepare("SELECT $select FROM $table WHERE $column IN (" . implode(',', array_fill(0, count($ids), '?')) . ') FOR UPDATE');
        if (!$query || !$query->execute($ids)) {
            throw new RuntimeException('Dependency receipt unavailable');
        }
        $rows = $query->fetchAll(PDO::FETCH_COLUMN);
        if ($query->errorCode() !== '00000') {
            throw new RuntimeException('Dependency receipt unavailable');
        }
        return array_map('intval', $rows);
    }

    /**
     * Locks and returns the data input values of the given data templates as pairs of data template and input field ids.
     *
     * @param array $templateIds List of int.
     * @return array The pairs, each an array with "data_template_data_id" and "data_input_field_id".
     * @throws \LogicException When no transaction is active.
     * @throws RuntimeException When the rows cannot be read.
     */
    private static function readPairs(PDO $db, array $templateIds): array
    {
        if (!$db->inTransaction()) {
            throw new \LogicException('Dependency receipts require a transaction');
        }
        if ($templateIds === []) {
            return [];
        }
        $lock = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? '' : ' FOR UPDATE';
        $query = $db->prepare('SELECT data_template_data_id, data_input_field_id FROM data_input_data WHERE data_template_data_id IN (' . implode(',', array_fill(0, count($templateIds), '?')) . ') ORDER BY data_template_data_id, data_input_field_id' . $lock);
        if (!$query || !$query->execute($templateIds)) {
            throw new RuntimeException('Dependency receipt unavailable');
        }
        $rows = array_map(static fn(array $row): array => [
            'data_template_data_id' => (int) $row['data_template_data_id'],
            'data_input_field_id' => (int) $row['data_input_field_id'],
        ], $query->fetchAll(PDO::FETCH_ASSOC));
        if ($query->errorCode() !== '00000') {
            throw new RuntimeException('Dependency receipt unavailable');
        }
        return $rows;
    }

    /**
     * Locks and returns the identity columns of the rows under the given parents, as integers, ordered by parent and id.
     *
     * @param array $columns Columns to select.
     * @param array $parentIds List of int.
     * @return array The rows, each an array of integers keyed by column name.
     * @throws \LogicException When no transaction is active.
     * @throws RuntimeException When the rows cannot be read.
     */
    private static function readRows(PDO $db, string $table, array $columns, string $parentColumn, array $parentIds): array
    {
        if (!$db->inTransaction()) {
            throw new \LogicException('Dependency receipts require a transaction');
        }
        if ($parentIds === []) {
            return [];
        }
        $select = implode(', ', $columns);
        $lock = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? '' : ' FOR UPDATE';
        $query = $db->prepare("SELECT $select FROM $table WHERE $parentColumn IN (" . implode(',', array_fill(0, count($parentIds), '?')) . ") ORDER BY $parentColumn, id$lock");
        if (!$query || !$query->execute($parentIds)) {
            throw new RuntimeException('Dependency receipt unavailable');
        }
        $rows = array_map(static fn(array $row): array => array_map('intval', $row), $query->fetchAll(PDO::FETCH_ASSOC));
        if ($query->errorCode() !== '00000') {
            throw new RuntimeException('Dependency receipt unavailable');
        }
        return $rows;
    }
}
