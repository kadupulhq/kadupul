<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Persistence;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Kadupul\Graphing\Application\Port\DataSourceTitle;
use Kadupul\Graphing\Application\Port\DataSourceTitleCatalog;
use Kadupul\Graphing\Domain\GraphSelection;
use Kadupul\IdentityAccess\Contract\OperatorDatabase;

final readonly class DbalDataSourceTitleCatalog implements DataSourceTitleCatalog
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
            $where[] = '(dtd.name_cache LIKE ? OR dtd.local_data_id LIKE ? OR dt.name LIKE ? OR di.name LIKE ?)';
            array_push($params, ...array_fill(0, 4, '%' . $selection->filter . '%'));
        }
        if ($selection->hostIds !== null) {
            $where[] = 'dl.host_id IN (?)';
            $params[] = $selection->hostIds;
            $types[count($params) - 1] = ArrayParameterType::INTEGER;
        }
        $rows = $connection->fetchAllAssociative('SELECT dtd.local_data_id, dtd.name_cache
            FROM data_local dl
            JOIN data_template_data dtd ON dl.id = dtd.local_data_id
            LEFT JOIN data_input di ON di.id = dtd.data_input_id
            LEFT JOIN data_template dt ON dl.data_template_id = dt.id'
            . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where)) . '
            ORDER BY dtd.local_data_id', $params, $types);

        $sources = [];
        foreach ($rows as $row) {
            $id = $row['local_data_id'];
            $title = $row['name_cache'] ?? '';
            if (!is_numeric($id) || !is_string($title)) {
                throw new \UnexpectedValueException('data_template_data returned an unexpected row.');
            }
            $sources[] = new DataSourceTitle((int) $id, $title);
        }

        return $sources;
    }
}
