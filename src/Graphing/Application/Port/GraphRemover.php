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
     * Runs api_delete_graphs() with the delete-data-sources option the original used.
     *
     * @param list<int> $localGraphIds
     * @return int how many graphs the worker finished
     *
     * @phpstan-impure
     * @throws \RuntimeException when the outcome is unknown
     */
    public function remove(OperatorDatabase $database, array $localGraphIds): int;
}
