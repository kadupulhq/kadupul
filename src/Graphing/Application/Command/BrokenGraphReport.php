<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Application\Command;

use Kadupul\Graphing\Application\Port\BrokenGraphGroup;
use Kadupul\IdentityAccess\Contract\OperatorDatabase;

/** What a remove_broken_graphs.php run found and, when $removing, how many graphs it removed. */
final readonly class BrokenGraphReport
{
    /** @param list<BrokenGraphGroup> $groups */
    public function __construct(
        public OperatorDatabase $database,
        public array $groups,
        public bool $removing,
        public int $removed,
    ) {}

    /** Graphs across all groups. */
    public function graphCount(): int
    {
        return array_sum(array_map(static fn(BrokenGraphGroup $group): int => count($group->graphs), $this->groups));
    }

    /** A report-only run always succeeds; a removal succeeds when every found graph was removed. */
    public function succeeded(): bool
    {
        return !$this->removing || $this->removed === $this->graphCount();
    }
}
