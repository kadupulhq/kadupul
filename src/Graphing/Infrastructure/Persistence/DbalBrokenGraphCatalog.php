<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Persistence;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Kadupul\Graphing\Application\Port\BrokenGraphCatalog;
use Kadupul\Graphing\Application\Port\BrokenGraphGroup;
use Kadupul\Graphing\Application\Port\GraphTitle;
use Kadupul\IdentityAccess\Contract\OperatorDatabase;

final readonly class DbalBrokenGraphCatalog implements BrokenGraphCatalog
{
    private const int TITLE_BATCH = 1000;

    public function __construct(private Connection $localConnection, private Connection $mainConnection) {}

    #[\Override]
    public function find(OperatorDatabase $database): array
    {
        $connection = $database === OperatorDatabase::Main ? $this->mainConnection : $this->localConnection;
        // One row per graph. The original read GROUP_CONCAT, which the server cuts
        // off at group_concat_max_len, so a template with many broken graphs lost some.
        $rows = $connection->fetchAllNumeric('SELECT gt.id, gt.name, gti.local_graph_id
            FROM (
                SELECT DISTINCT graph_template_id, local_graph_id
                FROM graph_templates_item
                WHERE local_graph_id > 0
                AND graph_template_id > 0
                AND task_item_id > 0
                AND task_item_id NOT IN (SELECT id FROM data_template_rrd WHERE local_data_id > 0)
            ) AS gti
            INNER JOIN graph_templates AS gt ON gt.id = gti.graph_template_id
            ORDER BY gt.id, gti.local_graph_id');
        $titles = $this->titles($connection, array_map(static fn(array $row): int => self::int($row[2]), $rows));
        $groups = [];
        foreach ($rows as $row) {
            $template = self::int($row[0]);
            $graph = self::int($row[2]);
            $groups[$template] ??= ['name' => is_string($row[1]) ? $row[1] : '', 'graphs' => []];
            $groups[$template]['graphs'][] = new GraphTitle($graph, $titles[$graph] ?? '');
        }
        $result = [];
        foreach ($groups as $template => $group) {
            $result[] = new BrokenGraphGroup($template, $group['name'], $group['graphs']);
        }

        return $result;
    }

    /**
     * @param list<int> $ids
     * @return array<int, string>
     */
    private function titles(Connection $connection, array $ids): array
    {
        $titles = [];
        foreach (array_chunk($ids, self::TITLE_BATCH) as $batch) {
            $rows = $connection->fetchAllNumeric('SELECT local_graph_id, title_cache FROM graph_templates_graph WHERE local_graph_id IN (?)', [$batch], [ArrayParameterType::INTEGER]);
            foreach ($rows as $row) {
                $titles[self::int($row[0])] = is_string($row[1]) ? $row[1] : '';
            }
        }

        return $titles;
    }

    private static function int(mixed $value): int
    {
        if (!is_numeric($value)) {
            throw new \UnexpectedValueException('The graph tables returned an unexpected row.');
        }

        return (int) $value;
    }
}
