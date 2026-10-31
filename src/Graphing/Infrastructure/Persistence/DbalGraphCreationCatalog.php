<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Kadupul\Graphing\Application\Port\CatalogChoice;
use Kadupul\Graphing\Application\Port\CatalogDevice;
use Kadupul\Graphing\Application\Port\GraphCreationCatalog;
use Kadupul\Graphing\Application\Port\InputField;
use Kadupul\Graphing\Application\Port\SnmpFilter;
use Kadupul\IdentityAccess\Contract\OperatorDatabase;

final readonly class DbalGraphCreationCatalog implements GraphCreationCatalog
{
    public function __construct(private Connection $localConnection, private Connection $mainConnection) {}

    #[\Override]
    public function devices(OperatorDatabase $database): array
    {
        return array_map(static fn(array $row): CatalogDevice => new CatalogDevice(self::int($row[0]), self::text($row[1]), self::int($row[3]), self::text($row[2])), $this->connection($database)->fetchAllNumeric('SELECT id, hostname, description, host_template_id FROM host ORDER BY id'));
    }

    #[\Override]
    public function graphTemplates(OperatorDatabase $database): array
    {
        return $this->choices($this->connection($database)->fetchAllNumeric('SELECT id, name FROM graph_templates ORDER BY id'));
    }

    #[\Override]
    public function graphTemplatesOfDeviceTemplate(OperatorDatabase $database, string $hostTemplateId): ?array
    {
        if (!is_numeric($hostTemplateId)) {
            return null;
        }
        // The script put the number into the statement as typed; adding zero gives the number MySQL would have read.
        $rows = $this->connection($database)->fetchAllNumeric('SELECT htg.graph_template_id, gt.name
            FROM host_template_graph AS htg
            LEFT JOIN graph_templates AS gt ON htg.graph_template_id = gt.id
            WHERE htg.host_template_id IN (?)
            ORDER BY gt.name ASC', [$hostTemplateId + 0]);

        return $this->choices($rows);
    }

    #[\Override]
    public function inputFields(OperatorDatabase $database, string $templateId): array
    {
        $rows = $this->connection($database)->fetchAllNumeric("SELECT DISTINCT dif.data_name, dif.name, did.value, dtd.data_template_id, dif.id
            FROM data_input_fields AS dif
            INNER JOIN (
                SELECT data_input_field_id, data_template_data_id, value
                FROM data_input_data
                WHERE t_value = 'on'
            ) AS did
            ON did.data_input_field_id = dif.id
            INNER JOIN (
                SELECT id, data_input_id, data_template_id
                FROM data_template_data FORCE INDEX (local_data_id)
                WHERE local_data_id = 0
            ) AS dtd
            ON did.data_template_data_id = dtd.id
            AND dtd.data_input_id = dif.data_input_id
            INNER JOIN (
                SELECT data_template_id, id
                FROM data_template_rrd
                WHERE local_data_id = 0 AND hash != ''
            ) AS dtr
            ON dtr.data_template_id = dtd.data_template_id
            INNER JOIN graph_templates_item AS gti
            ON dtr.id = gti.task_item_id
            INNER JOIN graph_templates AS gt
            ON gt.id = gti.graph_template_id
            WHERE gt.id = ?
            AND dif.input_output IN ('in', 'inout')", [$templateId]);
        // Filed by key, as the script did: a later row replaces an earlier one with the same key but keeps its place.
        $fields = [];
        foreach ($rows as $row) {
            $field = new InputField(self::text($row[0]), self::text($row[1]), self::text($row[2]), self::int($row[3]), self::int($row[4]));
            $fields[$field->key()] = $field;
        }

        return array_values($fields);
    }

    #[\Override]
    public function queries(OperatorDatabase $database): array
    {
        return $this->choices($this->connection($database)->fetchAllNumeric('SELECT id, name FROM snmp_query ORDER BY id'));
    }

    #[\Override]
    public function queryTypes(OperatorDatabase $database, int $queryId): array
    {
        return $this->choices($this->connection($database)->fetchAllNumeric('SELECT id, name FROM snmp_query_graph WHERE snmp_query_id = ? ORDER BY id', [$queryId]));
    }

    #[\Override]
    public function snmpFields(OperatorDatabase $database, int $deviceId, ?int $queryId): array
    {
        $sql = 'SELECT DISTINCT field_name FROM host_snmp_cache WHERE host_id = ?' . ($queryId === null ? '' : ' AND snmp_query_id = ?') . ' ORDER BY field_name';

        return array_map(self::text(...), $this->connection($database)->fetchFirstColumn($sql, $queryId === null ? [$deviceId] : [$deviceId, $queryId]));
    }

    #[\Override]
    public function snmpValues(OperatorDatabase $database, int $deviceId, string $field, ?int $queryId): array
    {
        $sql = 'SELECT field_value FROM host_snmp_cache WHERE host_id = ? AND field_name = ?' . ($queryId === null ? '' : ' AND snmp_query_id = ?') . ' ORDER BY field_value';
        $values = array_map(self::text(...), $this->connection($database)->fetchFirstColumn($sql, $queryId === null ? [$deviceId, $field] : [$deviceId, $field, $queryId]));

        // The script kept them as array keys, so a value that repeats exactly counted once.
        return array_values(array_unique($values));
    }

    #[\Override]
    public function queryAssociated(OperatorDatabase $database, int $deviceId, int $queryId): bool
    {
        return self::int($this->connection($database)->fetchOne('SELECT COUNT(host_id) FROM host_snmp_query WHERE host_id = ? AND snmp_query_id = ?', [$deviceId, $queryId])) > 0;
    }

    #[\Override]
    public function existingGraph(OperatorDatabase $database, int $templateId, int $deviceId, ?int $queryId, ?string $queryIndex): ?int
    {
        $sql = "SELECT gl.id
            FROM graph_local AS gl
            INNER JOIN graph_templates AS gt
            ON gt.id = gl.graph_template_id
            WHERE graph_template_id = ?
            AND host_id = ?" . ($queryId === null ? '' : ' AND snmp_query_id = ? AND snmp_index = ?') . "
            AND multiple = ''";
        $id = $this->connection($database)->fetchOne($sql, $queryId === null ? [$templateId, $deviceId] : [$templateId, $deviceId, $queryId, $queryIndex]);

        return $id === false ? null : self::int($id);
    }

    #[\Override]
    public function firstDataSource(OperatorDatabase $database, int $graphId): ?int
    {
        $id = $this->connection($database)->fetchOne('SELECT dtr.local_data_id
            FROM graph_templates_item AS gti
            INNER JOIN data_template_rrd AS dtr
            ON gti.task_item_id = dtr.id
            WHERE gti.local_graph_id = ?
            LIMIT 1', [$graphId]);

        return $id === false || $id === null ? null : self::int($id);
    }

    #[\Override]
    public function snmpIndexes(OperatorDatabase $database, int $deviceId, int $queryId, array $filters): array
    {
        $sql = 'SELECT DISTINCT snmp_index FROM host_snmp_cache WHERE host_id = ? AND snmp_query_id = ?';
        $parameters = [$deviceId, $queryId];
        /** @var SnmpFilter $filter */
        foreach ($filters as $filter) {
            // As in the original, the inner query does not look at the data query.
            $sql .= ' AND snmp_index IN (SELECT DISTINCT snmp_index FROM host_snmp_cache WHERE host_id = ? AND field_name = ? AND field_value ' . ($filter->value !== null ? '=' : 'REGEXP') . ' ?)';
            array_push($parameters, $deviceId, $filter->field, $filter->value ?? $filter->expression);
        }

        return array_map(self::text(...), $this->connection($database)->fetchFirstColumn($sql, $parameters));
    }

    /**
     * Once for each id, as the script keyed them: the first place, the last name.
     *
     * @param list<list<mixed>> $rows
     * @return list<CatalogChoice>
     */
    private function choices(array $rows): array
    {
        $names = [];
        foreach ($rows as $row) {
            $names[self::int($row[0])] = self::text($row[1]);
        }

        return array_map(static fn(int $id, string $name): CatalogChoice => new CatalogChoice($id, $name), array_keys($names), array_values($names));
    }

    private function connection(OperatorDatabase $database): Connection
    {
        return $database === OperatorDatabase::Main ? $this->mainConnection : $this->localConnection;
    }

    private static function int(mixed $value): int
    {
        if (!is_numeric($value)) {
            throw new \UnexpectedValueException('The graph tables returned an unexpected row.');
        }

        return (int) $value;
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : (is_int($value) || is_float($value) ? (string) $value : '');
    }
}
