<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Application\Port;

use Kadupul\IdentityAccess\Contract\OperatorDatabase;

/** Reads and repoints the graph items whose data source column no longer matches their device's data source. */
interface GraphItemRepairs
{
    /**
     * Graphs built from a graph template, optionally on one device, in id order.
     *
     * @return list<RepairGraph>
     */
    public function graphs(OperatorDatabase $database, int $graphTemplateId, ?int $hostId): array;

    /** The device's first data source built from the data template, with its first RRD item; null when it has none. */
    public function rrdTarget(OperatorDatabase $database, int $hostId, int $dataTemplateId): ?RepairRrdTarget;

    /**
     * The graph's items that follow the data template but point at another RRD item.
     *
     * @return list<WrongItem>
     */
    public function wrongItems(OperatorDatabase $database, int $graphId, int $graphTemplateId, int $dataTemplateId, int $rrdId): array;

    /**
     * @param list<int> $itemIds
     *
     * @phpstan-impure
     */
    public function repoint(OperatorDatabase $database, int $graphId, int $graphTemplateId, int $rrdId, array $itemIds): void;
}
