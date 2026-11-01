<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Legacy;

use Kadupul\Graphing\Application\Port\GraphTitleReapplier;
use Kadupul\IdentityAccess\Contract\OperatorDatabase;
use Kadupul\Platform\Contract\LegacyWorkerRunner;

/** Runs the graph title reapply in the legacy worker. */
final readonly class LegacyGraphTitleReapplier implements GraphTitleReapplier
{
    public const string MARKER = 'KADUPUL_GRAPH_TITLES_RESULT';

    public function __construct(private LegacyWorkerRunner $worker) {}

    #[\Override]
    public function reapply(OperatorDatabase $database, array $localGraphIds): int
    {
        // No timeout: the original warned never to interrupt a rename.
        $run = $this->worker->run('legacy-graph-titles.php', self::MARKER, [
            'database' => $database === OperatorDatabase::Main ? 'main' : 'local',
            'graphs' => $localGraphIds,
        ], null);
        if (!$run['ok'] || !is_int($run['result']['done'] ?? null)) {
            throw new \RuntimeException('Graph title outcome is unknown.');
        }

        return $run['result']['done'];
    }
}
