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
use Kadupul\Graphing\Application\Port\HostGraphRow;
use Kadupul\Graphing\Application\Port\TreeCatalog;
use Kadupul\Graphing\Application\Port\TreeItem;
use Kadupul\Graphing\Application\Port\TreeNodeRow;
use Kadupul\Graphing\Application\Port\TreeSummary;
use Kadupul\IdentityAccess\Contract\OperatorDatabase;

final readonly class DbalTreeCatalog implements TreeCatalog
{
    /** What PHP accepts as an integer array key: the form (string) of that integer would take. */
    private const string CANONICAL_KEY = '/^(?:0|-?[1-9][0-9]*)$/';

    public function __construct(private Connection $localConnection, private Connection $mainConnection) {}

    #[\Override]
    public function treeIdByName(OperatorDatabase $database, string $name): ?int
    {
        $id = $this->connection($database)->fetchOne('SELECT id FROM graph_tree WHERE name = ? ORDER BY id LIMIT 1', [$name]);

        return $id === false ? null : self::int($id);
    }

    #[\Override]
    public function treeExists(OperatorDatabase $database, int $treeId): bool
    {
        return $this->connection($database)->fetchOne('SELECT id FROM graph_tree WHERE id = ?', [$treeId]) !== false;
    }

    #[\Override]
    public function findItem(OperatorDatabase $database, int $treeId, int $itemId): ?TreeItem
    {
        $row = $this->connection($database)->fetchNumeric('SELECT title, local_graph_id, host_id, site_id FROM graph_tree_items WHERE graph_tree_id = ? AND id = ?', [$treeId, $itemId]);

        return $row === false ? null : new TreeItem(self::text($row[0]), self::int($row[1]) > 0, self::int($row[2]) > 0, self::int($row[3]) > 0);
    }

    #[\Override]
    public function findGraph(OperatorDatabase $database, string $id): ?int
    {
        if (!is_numeric($id)) {
            return null;
        }
        $found = $this->connection($database)->fetchOne('SELECT id FROM graph_local WHERE id = ? ORDER BY id LIMIT 1', [$id]);

        return $found === false ? null : self::int($found);
    }

    #[\Override]
    public function deviceExists(OperatorDatabase $database, string $id): bool
    {
        return preg_match(self::CANONICAL_KEY, $id) === 1 && $this->connection($database)->fetchOne('SELECT id FROM host WHERE id = ?', [$id]) !== false;
    }

    #[\Override]
    public function siteExists(OperatorDatabase $database, string $id): bool
    {
        return preg_match(self::CANONICAL_KEY, $id) === 1 && $this->connection($database)->fetchOne('SELECT id FROM sites WHERE id = ?', [$id]) !== false;
    }

    #[\Override]
    public function sites(OperatorDatabase $database): array
    {
        return array_map(static fn(array $row): CatalogChoice => new CatalogChoice(self::int($row[0]), self::text($row[1])), $this->connection($database)->fetchAllNumeric('SELECT id, name FROM sites ORDER BY id'));
    }

    #[\Override]
    public function devices(OperatorDatabase $database): array
    {
        return array_map(static fn(array $row): CatalogDevice => new CatalogDevice(self::int($row[0]), self::text($row[1]), self::int($row[3]), self::text($row[2])), $this->connection($database)->fetchAllNumeric('SELECT id, hostname, description, host_template_id FROM host ORDER BY id'));
    }

    #[\Override]
    public function trees(OperatorDatabase $database): array
    {
        return array_map(static fn(array $row): TreeSummary => new TreeSummary(self::int($row[0]), self::int($row[1]), self::text($row[2])), $this->connection($database)->fetchAllNumeric('SELECT id, sort_type, name FROM graph_tree ORDER BY id'));
    }

    #[\Override]
    public function nodes(OperatorDatabase $database, string $treeId, string $nodeType, string $parentNode): array
    {
        $rows = [];
        $this->walk($this->connection($database), $treeId, $nodeType, $parentNode, $rows);

        return $rows;
    }

    /**
     * @param int|string $parent the operator's text at the top, a node id below
     * @param list<TreeNodeRow> $rows
     */
    private function walk(Connection $connection, string $treeId, string $nodeType, int|string $parent, array &$rows): void
    {
        // The script compared the parent with 0 by PHP's rules: a node id never matches, and neither does text that is not a number.
        $root = is_int($parent) ? $parent === 0 : is_numeric($parent) && (float) $parent === 0.0;
        $label = $root ? 'N/A' : (string) $parent;
        $children = $connection->fetchAllNumeric('SELECT i.id, i.local_graph_id, i.title, i.host_id, i.host_grouping_type, i.sort_children_type,
                (SELECT g.title_cache FROM graph_templates_graph AS g WHERE g.local_graph_id = i.local_graph_id LIMIT 1),
                (SELECT h.hostname FROM host AS h WHERE h.id = i.host_id)
            FROM graph_tree_items AS i
            WHERE i.graph_tree_id = ? AND i.parent = ?
            ORDER BY i.position', [$treeId, $parent]);
        foreach ($children as $child) {
            $id = self::int($child[0]);
            if (self::int($child[3]) > 0) {
                if ($nodeType === '' || $nodeType === TreeNodeRow::HOST) {
                    $rows[] = new TreeNodeRow(TreeNodeRow::HOST, $id, $label, self::text($child[7]), self::int($child[4]));
                }
            } elseif (self::int($child[1]) > 0) {
                if ($nodeType === '' || $nodeType === TreeNodeRow::GRAPH) {
                    $rows[] = new TreeNodeRow(TreeNodeRow::GRAPH, $id, $label, self::text($child[6]), null);
                }
            } else {
                if ($nodeType === '' || $nodeType === TreeNodeRow::HEADER) {
                    $rows[] = new TreeNodeRow(TreeNodeRow::HEADER, $id, $label, self::text($child[2]), self::int($child[5]));
                }
                $this->walk($connection, $treeId, $nodeType, $id, $rows);
            }
        }
    }

    #[\Override]
    public function deviceGraphs(OperatorDatabase $database, string $deviceId): array
    {
        $rows = $this->connection($database)->fetchAllNumeric('SELECT g.local_graph_id, g.title_cache, t.name
            FROM graph_local AS l
            INNER JOIN graph_templates_graph AS g ON g.local_graph_id = l.id
            LEFT JOIN graph_templates AS t ON t.id = l.graph_template_id
            WHERE l.host_id = ?
            ORDER BY g.local_graph_id', [$deviceId]);

        return array_map(static fn(array $row): HostGraphRow => new HostGraphRow(self::int($row[0]), self::text($row[1]), self::text($row[2])), $rows);
    }

    private function connection(OperatorDatabase $database): Connection
    {
        return $database === OperatorDatabase::Main ? $this->mainConnection : $this->localConnection;
    }

    private static function int(mixed $value): int
    {
        if (!is_numeric($value)) {
            throw new \UnexpectedValueException('The tree tables returned an unexpected row.');
        }

        return (int) $value;
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
