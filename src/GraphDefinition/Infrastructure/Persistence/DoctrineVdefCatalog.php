<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\GraphDefinition\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Kadupul\GraphDefinition\Application\Port\VdefCatalog;
use Kadupul\GraphDefinition\Domain\VdefFunctions;
use Kadupul\GraphDefinition\Domain\VdefListCriteria;
use Kadupul\GraphDefinition\Domain\VdefRevision;
use Kadupul\GraphDefinition\Domain\VdefSummary;

final readonly class DoctrineVdefCatalog implements VdefCatalog
{
    public function __construct(private Connection $database) {}

    public function list(VdefListCriteria $criteria): array
    {
        $where = '1=1';
        $parameters = [];
        if ($criteria->search !== '') {
            $where .= " AND v.name LIKE ? ESCAPE '!'";
            $search = '%' . strtr($criteria->search, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
            $parameters[] = $search;
        }
        if ($criteria->hasGraphs) {
            $where .= ' AND vdef_usage.graphs > 0';
        }
        $order = ['name' => 'v.name', 'graphs' => 'vdef_usage.graphs', 'templates' => 'vdef_usage.templates'][$criteria->sort];
        $direction = strtoupper($criteria->direction);
        $referenceJoin = $this->database->getDatabasePlatform() instanceof AbstractMySQLPlatform
            ? 'BINARY vdef_references.value = BINARY CAST(v.id AS CHAR)'
            : 'vdef_references.value = CAST(v.id AS TEXT)';
        $rows = $this->database->fetchAllAssociative("SELECT v.id, v.name, vdef_usage.graphs, vdef_usage.templates, vdef_references.referencing_vdefs FROM vdef v
            LEFT JOIN (SELECT usage_rows.vdef_id,
                SUM(CASE WHEN usage_rows.local_graph_id > 0 THEN 1 ELSE 0 END) AS graphs,
                SUM(CASE WHEN usage_rows.local_graph_id = 0 THEN 1 ELSE 0 END) AS templates
                FROM (SELECT vdef_id, graph_template_id, local_graph_id FROM graph_templates_item
                    WHERE vdef_id > 0 GROUP BY vdef_id, graph_template_id, local_graph_id) usage_rows
                GROUP BY usage_rows.vdef_id) vdef_usage ON vdef_usage.vdef_id = v.id
            LEFT JOIN (SELECT value, COUNT(DISTINCT vdef_id) AS referencing_vdefs FROM vdef_items
                WHERE type = 5 GROUP BY value) vdef_references ON $referenceJoin
            WHERE $where ORDER BY $order $direction, v.id $direction LIMIT {$criteria->pageSize} OFFSET {$criteria->offset()}", $parameters);
        return array_map(static fn(array $row): VdefSummary => new VdefSummary(
            (int) $row['id'],
            (string) $row['name'],
            (int) ($row['graphs'] ?? 0),
            (int) ($row['templates'] ?? 0),
            (int) ($row['referencing_vdefs'] ?? 0)
        ), $rows);
    }

    public function count(VdefListCriteria $criteria): int
    {
        $where = '1=1';
        $parameters = [];
        if ($criteria->search !== '') {
            $where .= " AND v.name LIKE ? ESCAPE '!'";
            $parameters[] = '%' . strtr($criteria->search, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
        }
        if ($criteria->hasGraphs) {
            $where .= ' AND EXISTS (SELECT 1 FROM graph_templates_item i WHERE i.vdef_id = v.id AND i.local_graph_id > 0)';
        }
        return (int) $this->database->fetchOne("SELECT COUNT(*) FROM vdef v WHERE $where", $parameters);
    }

    public function find(int $id): ?array
    {
        $vdef = $this->database->fetchAssociative('SELECT id, name FROM vdef WHERE id = ?', [$id]);
        if ($vdef === false) {
            return null;
        }
        $items = $this->database->fetchAllAssociative('SELECT id, sequence, type, value FROM vdef_items WHERE vdef_id = ? ORDER BY sequence, id', [$id]);
        $revisionItems = array_map(static fn(array $item): array => [
            'id' => (int) $item['id'], 'sequence' => (int) $item['sequence'],
            'type' => (int) $item['type'], 'value' => (string) $item['value'],
        ], $items);
        return [
            'id' => (int) $vdef['id'],
            'name' => (string) $vdef['name'],
            'revision' => VdefRevision::fromState((string) $vdef['name'], $revisionItems),
            'items' => array_map(fn(array $item): array => [
                'id' => (int) $item['id'], 'sequence' => (int) $item['sequence'],
                'type' => (int) $item['type'], 'value' => (string) $item['value'],
                'label' => (int) $item['type'] === 5
                    ? self::resolvePreview($this->database, (int) $item['value'])
                    : VdefFunctions::itemLabel((int) $item['type'], (string) $item['value']),
            ], $items),
        ];
    }

    public function preview(int $id): string
    {
        return self::resolvePreview($this->database, $id);
    }

    private static function resolvePreview(Connection $database, int $id, array $visited = []): string
    {
        if (isset($visited[$id])) {
            return '[recursive VDEF]';
        }
        $visited[$id] = true;
        $items = $database->fetchAllAssociative('SELECT id, type, value FROM vdef_items WHERE vdef_id = ? ORDER BY sequence, id', [$id]);
        $parts = [];
        foreach ($items as $item) {
            $type = (int) $item['type'];
            $parts[] = $type === 5
                ? self::resolvePreview($database, (int) $item['value'], $visited)
                : VdefFunctions::rrdValue($type, (string) $item['value']);
        }
        return implode(',', $parts);
    }
}
