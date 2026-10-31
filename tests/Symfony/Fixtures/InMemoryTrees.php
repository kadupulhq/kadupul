<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests\Fixtures;

use Kadupul\Graphing\Application\Port\CatalogChoice;
use Kadupul\Graphing\Application\Port\CatalogDevice;
use Kadupul\Graphing\Application\Port\HostGraphRow;
use Kadupul\Graphing\Application\Port\TreeCatalog;
use Kadupul\Graphing\Application\Port\TreeItem;
use Kadupul\Graphing\Application\Port\TreeNodeDraft;
use Kadupul\Graphing\Application\Port\TreeNodeRow;
use Kadupul\Graphing\Application\Port\TreeSummary;
use Kadupul\Graphing\Application\Port\TreeWriter;
use Kadupul\IdentityAccess\Contract\OperatorDatabase;

/** Tree 5 "Main" with header 10, graph node 11 and device node 12; device 7, graph 3 and site 2; a writer that records what it is given. */
final class InMemoryTrees implements TreeCatalog, TreeWriter
{
    /** @var list<string> */
    public array $trees = [];

    /** @var list<TreeNodeDraft> */
    public array $nodes = [];

    public function __construct(private readonly bool $fails = false) {}

    #[\Override]
    public function treeIdByName(OperatorDatabase $database, string $name): ?int
    {
        return $name === 'Main' ? 5 : null;
    }

    #[\Override]
    public function treeExists(OperatorDatabase $database, int $treeId): bool
    {
        return $treeId === 5;
    }

    #[\Override]
    public function findItem(OperatorDatabase $database, int $treeId, int $itemId): ?TreeItem
    {
        return match ($itemId) {
            10 => new TreeItem('Core', false, false, false),
            11 => new TreeItem('', true, false, false),
            12 => new TreeItem('', false, true, false),
            default => null,
        };
    }

    #[\Override]
    public function findGraph(OperatorDatabase $database, string $id): ?int
    {
        return is_numeric($id) && (float) $id === 3.0 ? 3 : null;
    }

    #[\Override]
    public function deviceExists(OperatorDatabase $database, string $id): bool
    {
        return $id === '7';
    }

    #[\Override]
    public function siteExists(OperatorDatabase $database, string $id): bool
    {
        return $id === '2';
    }

    #[\Override]
    public function sites(OperatorDatabase $database): array
    {
        return [new CatalogChoice(2, 'Berlin')];
    }

    #[\Override]
    public function devices(OperatorDatabase $database): array
    {
        return [new CatalogDevice(7, 'core1', 2, 'Core router')];
    }

    #[\Override]
    public function trees(OperatorDatabase $database): array
    {
        return [new TreeSummary(5, 2, 'Main')];
    }

    #[\Override]
    public function nodes(OperatorDatabase $database, string $treeId, string $nodeType, string $parentNode): array
    {
        return [new TreeNodeRow(TreeNodeRow::HEADER, 10, 'N/A', 'Core', 2), new TreeNodeRow(TreeNodeRow::GRAPH, 11, '10', 'Traffic', null), new TreeNodeRow(TreeNodeRow::HOST, 12, '10', 'core1', 1)];
    }

    #[\Override]
    public function deviceGraphs(OperatorDatabase $database, string $deviceId): array
    {
        return [new HostGraphRow(3, 'core1 - Traffic', 'Traffic')];
    }

    #[\Override]
    public function createTree(OperatorDatabase $database, string $name, int $sortType): int
    {
        $this->trees[] = $name . ':' . $sortType;
        if ($this->fails) {
            throw new \RuntimeException('The tree could not be created.');
        }

        return 6;
    }

    #[\Override]
    public function createNode(OperatorDatabase $database, TreeNodeDraft $node): int
    {
        $this->nodes[] = $node;
        if ($this->fails) {
            throw new \RuntimeException('The tree node could not be created.');
        }

        return 20;
    }
}
