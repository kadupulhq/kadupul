<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Application\Command;

use Kadupul\Graphing\Application\Port\CatalogChoice;
use Kadupul\Graphing\Application\Port\CatalogDevice;
use Kadupul\Graphing\Application\Port\HostGraphRow;
use Kadupul\Graphing\Application\Port\TreeCatalog;
use Kadupul\Graphing\Application\Port\TreeNodeRow;
use Kadupul\Graphing\Application\Port\TreeSummary;

/** The --list-* modes of add_tree.php. */
final readonly class ListTreeChoices
{
    private const string ACTION = 'tree.choices';

    public function __construct(private GraphCommandAccess $access, private TreeCatalog $catalog) {}

    /**
     * @param ?string $operator account to act as; null means the admin_user setting
     * @return list<CatalogChoice>
     * @throws GraphManagementDenied
     */
    public function sites(bool $local, ?string $operator): array
    {
        return $this->catalog->sites($this->database($local, $operator));
    }

    /**
     * @param ?string $operator account to act as; null means the admin_user setting
     * @return list<CatalogDevice>
     * @throws GraphManagementDenied
     */
    public function devices(bool $local, ?string $operator): array
    {
        return $this->catalog->devices($this->database($local, $operator));
    }

    /**
     * @param ?string $operator account to act as; null means the admin_user setting
     * @return list<TreeSummary>
     * @throws GraphManagementDenied
     */
    public function trees(bool $local, ?string $operator): array
    {
        return $this->catalog->trees($this->database($local, $operator));
    }

    /**
     * @param ?string $operator account to act as; null means the admin_user setting
     * @return list<TreeNodeRow>
     * @throws GraphManagementDenied
     */
    public function nodes(bool $local, ?string $operator, string $treeId, string $nodeType, string $parentNode): array
    {
        return $this->catalog->nodes($this->database($local, $operator), $treeId, $nodeType, $parentNode);
    }

    /**
     * @param ?string $operator account to act as; null means the admin_user setting
     * @return list<HostGraphRow>
     * @throws GraphManagementDenied
     */
    public function deviceGraphs(bool $local, ?string $operator, string $deviceId): array
    {
        return $this->catalog->deviceGraphs($this->database($local, $operator), $deviceId);
    }

    /** @throws GraphManagementDenied */
    private function database(bool $local, ?string $operator): \Kadupul\IdentityAccess\Contract\OperatorDatabase
    {
        return $this->access->select(self::ACTION, $local, $operator, false, ManagedArea::Trees)->database;
    }
}
