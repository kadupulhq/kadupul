<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Application\Port;

use Kadupul\IdentityAccess\Contract\OperatorDatabase;

/** Removes graphs through the legacy graph API, which owns their dependent rows and plugin hooks. */
interface GraphRemover
{
    /**
     * Runs api_delete_graphs(). The cli/ tools removed the data sources the graphs
     * reference unless asked to keep them, so that is the default.
     *
     * @param list<int> $localGraphIds
     * @param bool $keepDataSources true keeps them (api_delete_graphs type 1), false removes them where possible (type 2)
     * @return int how many graphs the worker finished
     *
     * @phpstan-impure
     * @throws \RuntimeException when the outcome is unknown
     */
    public function remove(OperatorDatabase $database, array $localGraphIds, bool $keepDataSources = false): int;
}
