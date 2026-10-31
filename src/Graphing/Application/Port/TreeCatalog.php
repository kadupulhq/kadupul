<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Application\Port;

use Kadupul\IdentityAccess\Contract\OperatorDatabase;

/** What add_tree.php reads: the trees, nodes, devices, sites and graphs its options name. */
interface TreeCatalog
{
    public function treeIdByName(OperatorDatabase $database, string $name): ?int;

    public function treeExists(OperatorDatabase $database, int $treeId): bool;

    public function findItem(OperatorDatabase $database, int $treeId, int $itemId): ?TreeItem;

    /**
     * @param string $id as typed; compared as a number, so "1e0" is graph 1
     * @return ?int the graph's id
     */
    public function findGraph(OperatorDatabase $database, string $id): ?int;

    /** The original looked the id up as an array key, so only a canonical integer such as "7" names a device. */
    public function deviceExists(OperatorDatabase $database, string $id): bool;

    /** As deviceExists(), for sites. */
    public function siteExists(OperatorDatabase $database, string $id): bool;

    /** @return list<CatalogChoice> every site, ordered by id */
    public function sites(OperatorDatabase $database): array;

    /** @return list<CatalogDevice> every device, ordered by id */
    public function devices(OperatorDatabase $database): array;

    /** @return list<TreeSummary> every tree, ordered by id */
    public function trees(OperatorDatabase $database): array;

    /**
     * Depth first, in node position order, as add_tree.php walked them.
     *
     * @param string $treeId as typed; compared as a number
     * @param string $nodeType '', 'header', 'graph' or 'host': which kind to list
     * @param string $parentNode as typed; compared as a number
     * @return list<TreeNodeRow>
     */
    public function nodes(OperatorDatabase $database, string $treeId, string $nodeType, string $parentNode): array;

    /**
     * @param string $deviceId as typed; compared as a number
     * @return list<HostGraphRow>
     */
    public function deviceGraphs(OperatorDatabase $database, string $deviceId): array;
}
