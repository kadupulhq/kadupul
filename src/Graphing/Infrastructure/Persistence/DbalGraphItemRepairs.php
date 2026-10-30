<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Persistence;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Kadupul\Graphing\Application\Port\GraphItemRepairs;
use Kadupul\Graphing\Application\Port\RepairGraph;
use Kadupul\Graphing\Application\Port\RepairRrdTarget;
use Kadupul\Graphing\Application\Port\WrongItem;
use Kadupul\IdentityAccess\Contract\OperatorDatabase;

final readonly class DbalGraphItemRepairs implements GraphItemRepairs
{
    public function __construct(private Connection $localConnection, private Connection $mainConnection) {}

    private function connection(OperatorDatabase $database): Connection
    {
        return $database === OperatorDatabase::Main ? $this->mainConnection : $this->localConnection;
    }

    #[\Override]
    public function graphs(OperatorDatabase $database, int $graphTemplateId, ?int $hostId): array
    {
        $rows = $this->connection($database)->fetchAllNumeric(
            'SELECT id, host_id FROM graph_local WHERE graph_template_id = ?' . ($hostId === null ? '' : ' AND host_id = ?') . ' ORDER BY id',
            $hostId === null ? [$graphTemplateId] : [$graphTemplateId, $hostId],
        );

        return array_map(fn(array $row): RepairGraph => new RepairGraph(self::int($row[0]), self::int($row[1])), $rows);
    }

    #[\Override]
    public function rrdTarget(OperatorDatabase $database, int $hostId, int $dataTemplateId): ?RepairRrdTarget
    {
        $connection = $this->connection($database);
        $source = $connection->fetchOne('SELECT id FROM data_local WHERE host_id = ? AND data_template_id = ? ORDER BY id LIMIT 1', [$hostId, $dataTemplateId]);
        if ($source === false) {
            return null;
        }
        $rrd = $connection->fetchOne('SELECT id FROM data_template_rrd WHERE local_data_id = ? ORDER BY id LIMIT 1', [$source]);

        return new RepairRrdTarget(self::int($source), $rrd === false ? null : self::int($rrd));
    }

    #[\Override]
    public function wrongItems(OperatorDatabase $database, int $graphId, int $graphTemplateId, int $dataTemplateId, int $rrdId): array
    {
        // The scalar subquery is the original's: a data template with several RRD
        // items makes the server refuse it, and the run stops with that failure.
        $rows = $this->connection($database)->fetchAllNumeric(
            'SELECT id, task_item_id FROM graph_templates_item
            WHERE task_item_id != ? AND graph_template_id = ? AND local_graph_id = ?
            AND local_graph_template_item_id IN (
                SELECT id FROM graph_templates_item
                WHERE local_graph_template_item_id = 0 AND local_graph_id = 0
                AND task_item_id = (SELECT id FROM data_template_rrd WHERE local_data_template_rrd_id = 0 AND local_data_id = 0 AND data_template_id = ?))
            ORDER BY id',
            [$rrdId, $graphTemplateId, $graphId, $dataTemplateId],
        );

        return array_map(fn(array $row): WrongItem => new WrongItem(self::int($row[0]), self::int($row[1])), $rows);
    }

    #[\Override]
    public function repoint(OperatorDatabase $database, int $graphId, int $graphTemplateId, int $rrdId, array $itemIds): void
    {
        if ($itemIds === []) {
            return;
        }
        $this->connection($database)->executeStatement(
            'UPDATE graph_templates_item SET task_item_id = ? WHERE task_item_id != ? AND graph_template_id = ? AND local_graph_id = ? AND id IN (?)',
            [$rrdId, $rrdId, $graphTemplateId, $graphId, $itemIds],
            [ParameterType::INTEGER, ParameterType::INTEGER, ParameterType::INTEGER, ParameterType::INTEGER, ArrayParameterType::INTEGER],
        );
    }

    private static function int(mixed $value): int
    {
        if (!is_numeric($value)) {
            throw new \UnexpectedValueException('The graph tables returned an unexpected row.');
        }

        return (int) $value;
    }
}
