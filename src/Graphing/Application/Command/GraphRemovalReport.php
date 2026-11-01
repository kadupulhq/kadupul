<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Application\Command;

use Kadupul\Graphing\Application\Port\GraphTitle;
use Kadupul\IdentityAccess\Contract\OperatorDatabase;

/** What a remove_graphs.php run selected and, in Remove mode, how many graphs it removed; $refused is set when no selector and no --all was given. */
final readonly class GraphRemovalReport
{
    /** @param list<GraphTitle> $graphs */
    public function __construct(
        public OperatorDatabase $database,
        public GraphRemovalMode $mode,
        public array $graphs,
        public bool $keepDataSources,
        public int $removed,
        public bool $refused = false,
    ) {}

    /** Fails when refused, when nothing matched, or when a removal did not finish every selected graph. */
    public function succeeded(): bool
    {
        return !$this->refused && $this->graphs !== [] && ($this->mode !== GraphRemovalMode::Remove || $this->removed === count($this->graphs));
    }
}
