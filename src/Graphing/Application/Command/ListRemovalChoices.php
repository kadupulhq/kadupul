<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Application\Command;

use Kadupul\Graphing\Application\Port\GraphRemovalCatalog;
use Kadupul\Graphing\Application\Port\CatalogChoice;
use Kadupul\Graphing\Application\Port\CatalogDevice;

/** The --list-hosts, --list-host-templates and --list-graph-templates modes of remove_graphs.php. */
final readonly class ListRemovalChoices
{
    private const string ACTION = 'graph.remove.choices';

    public function __construct(private GraphCommandAccess $access, private GraphRemovalCatalog $catalog) {}

    /**
     * @param ?string $operator account to act as; null means the admin_user setting
     * @param list<int> $hostTemplateIds
     * @return list<CatalogDevice>
     * @throws GraphManagementDenied
     */
    public function devices(bool $local, ?string $operator, array $hostTemplateIds): array
    {
        return $this->catalog->devices($this->access->select(self::ACTION, $local, $operator, false)->database, $hostTemplateIds);
    }

    /**
     * @param ?string $operator account to act as; null means the admin_user setting
     * @return list<CatalogChoice>
     * @throws GraphManagementDenied
     */
    public function deviceTemplates(bool $local, ?string $operator): array
    {
        return $this->catalog->deviceTemplates($this->access->select(self::ACTION, $local, $operator, false)->database);
    }

    /**
     * @param ?string $operator account to act as; null means the admin_user setting
     * @param list<int> $hostTemplateIds
     * @return list<CatalogChoice>
     * @throws GraphManagementDenied
     */
    public function graphTemplates(bool $local, ?string $operator, array $hostTemplateIds): array
    {
        return $this->catalog->graphTemplates($this->access->select(self::ACTION, $local, $operator, false)->database, $hostTemplateIds);
    }
}
