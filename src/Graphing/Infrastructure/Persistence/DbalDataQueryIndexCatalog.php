<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Kadupul\Graphing\Application\Port\DataQueryIndexCatalog;
use Kadupul\Graphing\Application\Port\DataQueryIndexItem;
use Kadupul\Graphing\Domain\DataQuerySelection;
use Kadupul\IdentityAccess\Contract\OperatorDatabase;

final readonly class DbalDataQueryIndexCatalog implements DataQueryIndexCatalog
{
    public function __construct(private Connection $localConnection, private Connection $mainConnection) {}

    #[\Override]
    public function select(OperatorDatabase $database, DataQuerySelection $selection): array
    {
        $connection = $database === OperatorDatabase::Main ? $this->mainConnection : $this->localConnection;
        $where = ["dif.type_code = 'output_type'"];
        $params = [];
        if ($selection->hostId !== null) {
            $where[] = 'dl.host_id = ?';
            $params[] = $selection->hostId;
        }
        if ($selection->queryId !== null) {
            $where[] = 'dl.snmp_query_id = ?';
            $params[] = $selection->queryId;
        }
        // The joins are the script's. A data source with no stored output type is
        // kept, with the empty fields it read, because the script kept it.
        $rows = $connection->fetchAllAssociative('SELECT dl.host_id, dl.snmp_query_id, dl.snmp_index, did.data_template_data_id, did.value
            FROM data_local dl
            LEFT JOIN data_template_data dtd ON dl.id = dtd.local_data_id
            LEFT JOIN data_input_fields dif ON dtd.data_input_id = dif.data_input_id
            LEFT JOIN data_input_data did ON dtd.id = did.data_template_data_id AND dif.id = did.data_input_field_id
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY dl.id', $params);

        $items = [];
        foreach ($rows as $row) {
            $templateDataId = $row['data_template_data_id'];
            $value = $row['value'];
            if (!is_numeric($row['host_id']) || !is_numeric($row['snmp_query_id']) || !is_string($row['snmp_index'])
                || ($templateDataId !== null && !is_numeric($templateDataId)) || ($value !== null && !is_scalar($value))) {
                throw new \UnexpectedValueException('data_local returned an unexpected row.');
            }
            $items[] = new DataQueryIndexItem((int) $row['host_id'], (int) $row['snmp_query_id'], $row['snmp_index'], $templateDataId === null ? null : (int) $templateDataId, $value === null ? null : (string) $value);
        }

        return $items;
    }
}
