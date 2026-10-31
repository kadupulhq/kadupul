<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Application\Command;

use Kadupul\Graphing\Application\Port\CatalogChoice;
use Kadupul\Graphing\Application\Port\CatalogDevice;
use Kadupul\Graphing\Application\Port\GraphTemplateAssignmentCatalog;

/** The --list-hosts and --list-graph-templates modes of add_graph_template.php. */
final readonly class ListAttachmentChoices
{
    private const string ACTION = 'device.graph-template.choices';

    public function __construct(private GraphCommandAccess $access, private GraphTemplateAssignmentCatalog $catalog) {}

    /**
     * @param ?string $operator account to act as; null means the admin_user setting
     * @return list<CatalogDevice>
     * @throws GraphManagementDenied
     */
    public function devices(bool $local, ?string $operator): array
    {
        return $this->catalog->devices($this->access->select(self::ACTION, $local, $operator, false, ManagedArea::Devices)->database);
    }

    /**
     * @param ?string $operator account to act as; null means the admin_user setting
     * @return list<CatalogChoice>
     * @throws GraphManagementDenied
     */
    public function graphTemplates(bool $local, ?string $operator): array
    {
        return $this->catalog->graphTemplates($this->access->select(self::ACTION, $local, $operator, false, ManagedArea::Devices)->database);
    }
}
