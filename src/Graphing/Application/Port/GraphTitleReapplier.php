<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Application\Port;

use Kadupul\IdentityAccess\Contract\OperatorDatabase;

/** Reapplies each graph's suggested title, which the legacy graph API owns. */
interface GraphTitleReapplier
{
    /**
     * Runs api_reapply_suggested_graph_title() and update_graph_title_cache() for
     * each graph, keeping their plugin hooks.
     *
     * @param list<int> $localGraphIds
     * @return int how many graphs the worker finished
     *
     * @phpstan-impure
     * @throws \RuntimeException when the outcome is unknown
     */
    public function reapply(OperatorDatabase $database, array $localGraphIds): int;
}
