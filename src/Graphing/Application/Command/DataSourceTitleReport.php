<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Application\Command;

use Kadupul\Graphing\Application\Port\DataSourceTitle;
use Kadupul\IdentityAccess\Contract\OperatorDatabase;

/** What a data source title reapply run selected and, unless it was a dry run, what it renamed. */
final readonly class DataSourceTitleReport
{
    /**
     * @param list<DataSourceTitle> $dataSources the data sources selected
     * @param list<string> $renamed the title each finished data source ended with; empty for a dry run
     */
    public function __construct(
        public OperatorDatabase $database,
        public array $dataSources,
        public array $renamed,
        public bool $dryRun,
    ) {}

    /** A dry run always succeeds; an applied run succeeds when every selected data source was renamed. */
    public function succeeded(): bool
    {
        return $this->dryRun || count($this->renamed) === count($this->dataSources);
    }
}
