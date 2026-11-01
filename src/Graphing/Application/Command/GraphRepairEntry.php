<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Application\Command;

use Kadupul\Graphing\Application\Port\WrongItem;

/** One finding of a repair run: a graph to repoint, or a data source with no RRD item to repoint to. */
final readonly class GraphRepairEntry
{
    /**
     * @param list<int> $itemIds
     * @param list<int> $oldTaskItemIds
     */
    private function __construct(
        public int $hostId,
        public int $graphId,
        public int $dataSourceId,
        public ?int $rrdId,
        public array $itemIds,
        public array $oldTaskItemIds,
        public string $sql,
    ) {}

    /** A finding for a data source that has no RRD item to repoint graph items to. */
    public static function withoutRrd(int $dataSourceId): self
    {
        return new self(0, 0, $dataSourceId, null, [], [], '');
    }

    /**
     * @param list<WrongItem> $items
     */
    public static function repoint(int $hostId, int $graphId, int $graphTemplateId, int $rrdId, array $items): self
    {
        $ids = array_map(static fn(WrongItem $item): int => $item->id, $items);

        return new self(
            $hostId,
            $graphId,
            0,
            $rrdId,
            $ids,
            array_map(static fn(WrongItem $item): int => $item->taskItemId, $items),
            sprintf('UPDATE graph_templates_item SET task_item_id=%1$d WHERE task_item_id!=%1$d and graph_template_id=%2$d and local_graph_id=%3$d and id in (%4$s)', $rrdId, $graphTemplateId, $graphId, implode(',', $ids))
        );
    }

    /** Whether this finding is a data source without an RRD item rather than a repoint. */
    public function isMissingRrd(): bool
    {
        return $this->rrdId === null;
    }
}
