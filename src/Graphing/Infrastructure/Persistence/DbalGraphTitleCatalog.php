<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Persistence;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Kadupul\Graphing\Application\Port\GraphTitle;
use Kadupul\Graphing\Application\Port\GraphTitleCatalog;
use Kadupul\Graphing\Domain\GraphSelection;
use Kadupul\IdentityAccess\Contract\OperatorDatabase;

final readonly class DbalGraphTitleCatalog implements GraphTitleCatalog
{
    public function __construct(private Connection $localConnection, private Connection $mainConnection) {}

    #[\Override]
    public function select(OperatorDatabase $database, GraphSelection $selection): array
    {
        $connection = $database === OperatorDatabase::Main ? $this->mainConnection : $this->localConnection;
        $where = [];
        $params = [];
        $types = [];
        if ($selection->filter !== '') {
            // The original placed the filter inside LIKE '%...%' unescaped, so % and _
            // stay wildcards; it is bound here instead of concatenated into the SQL.
            $where[] = '(gtg.title_cache LIKE ? OR gt.name LIKE ?)';
            $params[] = $params[] = '%' . $selection->filter . '%';
        }
        if ($selection->hostIds !== null) {
            $where[] = 'gl.host_id IN (?)';
            $params[] = $selection->hostIds;
            $types[count($params) - 1] = ArrayParameterType::INTEGER;
        }
        $rows = $connection->fetchAllAssociative('SELECT gtg.local_graph_id, gtg.title_cache
            FROM graph_local gl
            JOIN graph_templates_graph gtg ON gl.id = gtg.local_graph_id
            LEFT JOIN graph_templates gt ON gl.graph_template_id = gt.id'
            . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where)) . '
            ORDER BY gtg.local_graph_id', $params, $types);

        $graphs = [];
        foreach ($rows as $row) {
            $id = $row['local_graph_id'];
            $title = $row['title_cache'];
            if (!is_numeric($id) || !is_string($title)) {
                throw new \UnexpectedValueException('graph_templates_graph returned an unexpected row.');
            }
            $graphs[] = new GraphTitle((int) $id, $title);
        }

        return $graphs;
    }
}
