<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\GraphDefinition\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Kadupul\GraphDefinition\Application\Port\CdefCatalog;
use Kadupul\GraphDefinition\Domain\CdefFunctions;
use Kadupul\GraphDefinition\Domain\CdefRevision;
use Kadupul\GraphDefinition\Domain\CdefListCriteria;
use Kadupul\GraphDefinition\Domain\CdefSummary;

final readonly class DoctrineCdefCatalog implements CdefCatalog
{
    public function __construct(private Connection $database) {}

    public function list(CdefListCriteria $criteria): array
    {
        [$where, $parameters] = $this->where($criteria);
        if ($criteria->hasGraphs) {
            $where .= ' AND COALESCE(usage_stats.graphs, 0) > 0';
        }
        $referenceId = $this->referenceId('value');
        $order = ['name' => 'c.name', 'graphs' => 'COALESCE(usage_stats.graphs, 0)', 'templates' => 'COALESCE(usage_stats.templates, 0)'][$criteria->sort];
        $direction = strtoupper($criteria->direction);
        $rows = $this->database->fetchAllAssociative("SELECT c.id, c.name, COALESCE(usage_stats.graphs, 0) AS graphs,
                COALESCE(usage_stats.templates, 0) AS templates, COALESCE(refs.reference_count, 0) AS referencing_cdefs
            FROM cdef c
            LEFT JOIN (SELECT usage_rows.cdef_id,
                    SUM(CASE WHEN usage_rows.local_graph_id > 0 THEN 1 ELSE 0 END) AS graphs,
                    SUM(CASE WHEN usage_rows.local_graph_id = 0 THEN 1 ELSE 0 END) AS templates
                FROM (SELECT cdef_id, graph_template_id, local_graph_id FROM graph_templates_item WHERE cdef_id > 0
                    GROUP BY cdef_id, graph_template_id, local_graph_id) usage_rows
                GROUP BY usage_rows.cdef_id) usage_stats ON usage_stats.cdef_id = c.id
            LEFT JOIN (SELECT $referenceId AS referenced_id, COUNT(DISTINCT cdef_id) AS reference_count
                FROM cdef_items WHERE type = 5 GROUP BY $referenceId) refs ON refs.referenced_id = c.id
            WHERE $where ORDER BY $order $direction, c.id $direction LIMIT {$criteria->pageSize} OFFSET {$criteria->offset()}", $parameters);

        return array_map(static fn(array $row): CdefSummary => new CdefSummary(
            (int) $row['id'],
            (string) $row['name'],
            (int) $row['graphs'],
            (int) $row['templates'],
            (int) $row['referencing_cdefs']
        ), $rows);
    }

    public function count(CdefListCriteria $criteria): int
    {
        [$where, $parameters] = $this->where($criteria);
        if ($criteria->hasGraphs) {
            $where .= ' AND EXISTS (SELECT 1 FROM graph_templates_item i WHERE i.cdef_id = c.id AND i.local_graph_id > 0)';
        }
        return (int) $this->database->fetchOne("SELECT COUNT(*) FROM cdef c WHERE $where", $parameters);
    }

    public function references(): array
    {
        return array_map(
            static fn(array $row): array => ['id' => (int) $row['id'], 'name' => (string) $row['name']],
            $this->database->fetchAllAssociative('SELECT id, name FROM cdef WHERE `system` = 0 ORDER BY name, id')
        );
    }

    public function functions(): array
    {
        return CdefFunctions::functions($this->roundSupported());
    }

    public function find(int $id): ?array
    {
        $referenceId = $this->referenceId('i.value');
        $cdef = $this->database->fetchAssociative("SELECT c.id, c.hash, c.`system`, c.name,
            (SELECT COUNT(DISTINCT i.local_graph_id) FROM graph_templates_item i WHERE i.cdef_id = c.id AND i.local_graph_id > 0) AS graphs,
            (SELECT COUNT(DISTINCT i.graph_template_id) FROM graph_templates_item i WHERE i.cdef_id = c.id AND i.local_graph_id = 0) AS templates,
            (SELECT COUNT(DISTINCT i.cdef_id) FROM cdef_items i WHERE i.type = 5 AND $referenceId = c.id) AS referencing_cdefs
            FROM cdef c WHERE c.id = ? AND c.`system` = 0", [$id]);
        if ($cdef === false) {
            return null;
        }
        $items = $this->database->fetchAllAssociative('SELECT id, hash, cdef_id, sequence, type, value FROM cdef_items WHERE cdef_id = ? ORDER BY sequence, id', [$id]);
        $roundSupported = $this->roundSupported();
        return [
            'id' => (int) $cdef['id'],
            'name' => (string) $cdef['name'],
            'revision' => CdefRevision::fromRows($cdef, $items),
            'graphs' => (int) $cdef['graphs'],
            'templates' => (int) $cdef['templates'],
            'referencing_cdefs' => (int) $cdef['referencing_cdefs'],
            'items' => array_map(function (array $item) use ($roundSupported): array {
                $type = (int) $item['type'];
                $value = (string) $item['value'];
                $referencedName = $type === 5
                    ? $this->database->fetchOne('SELECT name FROM cdef WHERE id = ? AND `system` = 0', [(int) $value])
                    : null;
                return [
                    'id' => (int) $item['id'],
                    'sequence' => (int) $item['sequence'],
                    'type' => $type,
                    'value' => $value,
                    'label' => CdefFunctions::itemLabel($type, $value, is_string($referencedName) ? $referencedName : null, $roundSupported),
                ];
            }, $items),
        ];
    }

    public function preview(int $id): string
    {
        return $this->resolvePreview($id, [], 0, $this->roundSupported());
    }

    private function resolvePreview(int $id, array $visited = [], int $depth = 0, bool $roundSupported = false): string
    {
        if (isset($visited[$id]) || $depth > 128) {
            return '[recursive CDEF]';
        }
        $visited[$id] = true;
        $items = $this->database->fetchAllAssociative('SELECT type, value FROM cdef_items WHERE cdef_id = ? ORDER BY sequence, id', [$id]);
        $parts = [];
        foreach ($items as $item) {
            $type = (int) $item['type'];
            $value = (string) $item['value'];
            $parts[] = $type === 5
                ? $this->resolvePreview((int) $value, $visited, $depth + 1, $roundSupported)
                : CdefFunctions::rrdValue($type, $value, $roundSupported);
        }
        return implode(',', $parts);
    }

    /** @return array{string,list<string>} */
    private function where(CdefListCriteria $criteria): array
    {
        $where = '`system` = 0';
        $parameters = [];
        if ($criteria->search !== '') {
            $where .= " AND c.name LIKE ? ESCAPE '!'";
            $parameters[] = '%' . strtr($criteria->search, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
        }
        return [$where, $parameters];
    }

    private function roundSupported(): bool
    {
        $version = $this->database->fetchOne("SELECT value FROM settings WHERE name = 'rrdtool_version'");
        $version = is_string($version) ? str_replace(['rrd-', '.x'], ['', '.0'], $version) : '1.4.0';
        return version_compare($version, '1.8.0', '>=');
    }

    private function referenceId(string $value): string
    {
        return 'CAST(' . $value . ($this->database->getDatabasePlatform() instanceof AbstractMySQLPlatform ? ' AS UNSIGNED)' : ' AS INTEGER)');
    }
}
