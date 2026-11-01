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
use Kadupul\Graphing\Application\Port\GraphRemovalCatalog;
use Kadupul\Graphing\Application\Port\GraphTitle;
use Kadupul\Graphing\Application\Port\CatalogChoice;
use Kadupul\Graphing\Application\Port\CatalogDevice;
use Kadupul\Graphing\Domain\GraphRemovalFilter;
use Kadupul\IdentityAccess\Contract\OperatorDatabase;

/** DBAL implementation of GraphRemovalCatalog, reading the local or the main database as the operator database selects. */
final readonly class DbalGraphRemovalCatalog implements GraphRemovalCatalog
{
    public function __construct(private Connection $localConnection, private Connection $mainConnection) {}

    #[\Override]
    public function graphs(OperatorDatabase $database, GraphRemovalFilter $filter): array
    {
        $where = ['gl.id > 0'];
        $values = [];
        $types = [];
        // --all ignores the selectors, as the original did.
        if (!$filter->all) {
            foreach ([['gl.host_id', $filter->hostIds], ['h.host_template_id', $filter->hostTemplateIds], ['gl.graph_template_id', $filter->graphTemplateIds]] as [$column, $ids]) {
                if ($ids !== []) {
                    $where[] = $column . ' IN (?)';
                    $values[] = $ids;
                    $types[] = ArrayParameterType::INTEGER;
                }
            }
            if ($filter->patterns !== []) {
                $where[] = '(' . implode(' OR ', array_fill(0, count($filter->patterns), 'gtg.title_cache RLIKE ?')) . ')';
                foreach ($filter->patterns as $pattern) {
                    $values[] = $pattern;
                    $types[] = ParameterType::STRING;
                }
            }
        }
        $rows = $this->connection($database)->fetchAllNumeric('SELECT gl.id, gtg.title_cache
            FROM graph_local AS gl
            INNER JOIN graph_templates_graph AS gtg ON gl.id = gtg.local_graph_id
            INNER JOIN host AS h ON h.id = gl.host_id
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY gl.id', $values, $types);

        return array_map(static fn(array $row): GraphTitle => new GraphTitle(self::int($row[0]), self::text($row[1])), $rows);
    }

    #[\Override]
    public function devices(OperatorDatabase $database, array $hostTemplateIds): array
    {
        // The join is the original's: a device whose template row is missing is not listed for a template.
        $rows = $this->connection($database)->fetchAllNumeric('SELECT h.id, h.hostname, h.description, h.host_template_id
            FROM host AS h
            LEFT JOIN host_template AS ht ON h.host_template_id = ht.id'
            . ($hostTemplateIds === [] ? '' : ' WHERE ht.id IN (?)') . '
            ORDER BY h.id', $hostTemplateIds === [] ? [] : [$hostTemplateIds], $hostTemplateIds === [] ? [] : [ArrayParameterType::INTEGER]);

        return array_map(static fn(array $row): CatalogDevice => new CatalogDevice(self::int($row[0]), self::text($row[1]), self::int($row[3]), self::text($row[2])), $rows);
    }

    #[\Override]
    public function deviceTemplates(OperatorDatabase $database): array
    {
        $rows = $this->connection($database)->fetchAllNumeric('SELECT id, name FROM host_template ORDER BY id');

        return array_map(static fn(array $row): CatalogChoice => new CatalogChoice(self::int($row[0]), self::text($row[1])), $rows);
    }

    #[\Override]
    public function graphTemplates(OperatorDatabase $database, array $hostTemplateIds): array
    {
        $rows = $this->connection($database)->fetchAllNumeric('SELECT htg.graph_template_id, gt.name
            FROM host_template_graph AS htg
            LEFT JOIN graph_templates AS gt ON htg.graph_template_id = gt.id'
            . ($hostTemplateIds === [] ? '' : ' WHERE htg.host_template_id IN (?)') . '
            ORDER BY gt.name ASC, htg.graph_template_id', $hostTemplateIds === [] ? [] : [$hostTemplateIds], $hostTemplateIds === [] ? [] : [ArrayParameterType::INTEGER]);
        // A graph template attached to several device templates is listed once.
        $choices = [];
        foreach ($rows as $row) {
            $choices[self::int($row[0])] ??= new CatalogChoice(self::int($row[0]), self::text($row[1]));
        }

        return array_values($choices);
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
        return is_string($value) ? $value : '';
    }
}
