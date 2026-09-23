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
    private function __construct(private array $checks, private array $rrds, private array $graphs, private array $inputFields) {}

    public static function capture(PDO $db, array $graphs, array $data): self
    {
        $checks = [];
        $templates = $rrds = [];
        foreach ([
            ['data_template_data', 'local_data_id', $data],
            ['data_template_rrd', 'local_data_id', $data],
            ['graph_templates_item', 'local_graph_id', $graphs],
            ['graph_templates_graph', 'local_graph_id', $graphs],
        ] as [$table, $column, $parents]) {
            $ids = self::read($db, $table, 'id', $column, $parents);
            $checks[] = [$table, $column, $parents];
            $checks[] = [$table, 'id', $ids];
            if ($table === 'data_template_data') {
                $templates = $ids;
            } elseif ($table === 'data_template_rrd') {
                $rrds = $ids;
            }
        }
        $checks[] = ['data_input_data', 'data_template_data_id', $templates];
        $checks[] = ['graph_templates_item', 'task_item_id', $rrds];
        return new self($checks, $rrds, $graphs, self::readPairs($db, $templates));
    }

    public function assertExclusive(PDO $db): void
    {
        foreach (self::read($db, 'graph_templates_item', 'local_graph_id', 'task_item_id', $this->rrds) as $graph) {
            if (!in_array($graph, $this->graphs, true)) {
                throw new RuntimeException('Reviewed data acquired an outside graph reference');
            }
        }
        $templateIds = array_values(array_unique(array_column($this->inputFields, 'data_template_data_id')));
        $existingTemplates = self::read($db, 'data_template_data', 'id', 'id', $templateIds);
        $expected = array_values(array_filter($this->inputFields, static fn(array $field): bool => in_array($field['data_template_data_id'], $existingTemplates, true)));
        if (self::readPairs($db, $existingTemplates) !== $expected) {
            throw new RuntimeException('Reviewed input field scope changed');
        }
    }

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
}
