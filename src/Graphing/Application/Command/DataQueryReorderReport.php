<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Application\Command;

use Kadupul\Graphing\Application\Port\DataQueryIndexItem;
use Kadupul\IdentityAccess\Contract\OperatorDatabase;

/** What a reorder_data_query.php run selected and, unless it was a dry run, what it indexed. */
final readonly class DataQueryReorderReport
{
    /**
     * @param list<DataQueryIndexItem> $items the items selected
     * @param list<string> $indexOn the field each finished item was indexed on; empty for a dry run
     * @param bool $unresolved the item after the finished ones had no sort field
     */
    public function __construct(
        public OperatorDatabase $database,
        public array $items,
        public array $indexOn,
        public bool $unresolved,
        public bool $dryRun,
    ) {}

    /** A dry run always succeeds; an applied run succeeds when every selected item was indexed. */
    public function succeeded(): bool
    {
        return $this->dryRun || (!$this->unresolved && count($this->indexOn) === count($this->items));
    }

    /** The item the run stopped at, when it stopped for a missing sort field. */
    public function stoppedAt(): ?DataQueryIndexItem
    {
        return $this->unresolved ? ($this->items[count($this->indexOn)] ?? null) : null;
    }
}
