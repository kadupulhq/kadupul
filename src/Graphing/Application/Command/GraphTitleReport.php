<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Application\Command;

use Kadupul\Graphing\Application\Port\GraphTitle;
use Kadupul\IdentityAccess\Contract\OperatorDatabase;

/** What a graph title reapply run selected and, unless it was a dry run, how many it finished. */
final readonly class GraphTitleReport
{
    /**
     * @param list<GraphTitle> $graphs the graphs selected
     * @param int $reapplied how many the run finished; 0 for a dry run
     */
    public function __construct(
        public OperatorDatabase $database,
        public array $graphs,
        public int $reapplied,
        public bool $dryRun,
    ) {}

    /** A dry run always succeeds; an applied run succeeds when every selected graph was finished. */
    public function succeeded(): bool
    {
        return $this->dryRun || $this->reapplied === count($this->graphs);
    }
}
