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
    private function __construct(private array $checks, private array $rrds, private array $graphs) {}

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
        return new self($checks, $rrds, $graphs);
    }

    public function assertExclusive(PDO $db): void
    {
        foreach (self::read($db, 'graph_templates_item', 'local_graph_id', 'task_item_id', $this->rrds) as $graph) {
            if (!in_array($graph, $this->graphs, true)) {
                throw new RuntimeException('Reviewed data acquired an outside graph reference');
            }
        }
    }

    public function assertPurged(PDO $db): void
    {
        foreach ($this->checks as [$table, $column, $ids]) {
            if (self::read($db, $table, $column, $column, $ids) !== []) {
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
}
