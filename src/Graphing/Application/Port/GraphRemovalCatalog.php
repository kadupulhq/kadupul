<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Application\Port;

use Kadupul\Graphing\Domain\GraphRemovalFilter;
use Kadupul\IdentityAccess\Contract\OperatorDatabase;

/** What remove_graphs.php reads: the graphs a filter selects and the ids it can be given. */
interface GraphRemovalCatalog
{
    /**
     * Graphs that belong to a device, as the original joined them; a graph with no
     * device is never selected, not even by --all. Ordered by graph id.
     *
     * @return list<GraphTitle>
     */
    public function graphs(OperatorDatabase $database, GraphRemovalFilter $filter): array;

    /**
     * @param list<int> $hostTemplateIds empty for every device
     * @return list<CatalogDevice> ordered by id
     */
    public function devices(OperatorDatabase $database, array $hostTemplateIds): array;

    /** @return list<CatalogChoice> ordered by id, without the "None" entry the list adds */
    public function deviceTemplates(OperatorDatabase $database): array;

    /**
     * Graph templates attached to a device template, once each, ordered by name.
     *
     * @param list<int> $hostTemplateIds empty for every device template
     * @return list<CatalogChoice>
     */
    public function graphTemplates(OperatorDatabase $database, array $hostTemplateIds): array;
}
