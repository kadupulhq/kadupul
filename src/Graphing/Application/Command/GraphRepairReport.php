<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Application\Command;

use Kadupul\IdentityAccess\Contract\OperatorDatabase;

final readonly class GraphRepairReport
{
    /**
     * @param int $checked graphs of the template that were looked at
     * @param list<GraphRepairEntry> $entries in the order the original printed them
     */
    public function __construct(
        public OperatorDatabase $database,
        public int $checked,
        public array $entries,
        public bool $executed,
    ) {}

    /** Graphs that were, or with a dry run would be, repointed. */
    public function repointed(): int
    {
        return count(array_filter($this->entries, static fn(GraphRepairEntry $entry): bool => !$entry->isMissingRrd()));
    }
}
