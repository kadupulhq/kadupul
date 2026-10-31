<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Legacy;

use Kadupul\Graphing\Application\Port\GraphRemover;
use Kadupul\IdentityAccess\Contract\OperatorDatabase;
use Kadupul\Platform\Contract\LegacyWorkerRunner;

final readonly class LegacyGraphRemover implements GraphRemover
{
    public const string MARKER = 'KADUPUL_GRAPH_REMOVE_RESULT';

    public function __construct(private LegacyWorkerRunner $worker) {}

    #[\Override]
    public function remove(OperatorDatabase $database, array $localGraphIds, bool $keepDataSources = false): int
    {
        // No timeout: stopping a removal half way would leave a graph without its items.
        $run = $this->worker->run('legacy-graph-remove.php', self::MARKER, [
            'database' => $database === OperatorDatabase::Main ? 'main' : 'local',
            'graphs' => $localGraphIds,
            'keep_data_sources' => $keepDataSources,
        ], null);
        if (!$run['ok'] || !is_int($run['result']['done'] ?? null)) {
            throw new \RuntimeException('Graph removal outcome is unknown.');
        }

        return $run['result']['done'];
    }
}
