<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Legacy;

use Kadupul\Graphing\Application\Port\DataSourceTitleReapplier;
use Kadupul\IdentityAccess\Contract\OperatorDatabase;
use Kadupul\Platform\Contract\LegacyWorkerRunner;

final readonly class LegacyDataSourceTitleReapplier implements DataSourceTitleReapplier
{
    public const string MARKER = 'KADUPUL_DATA_SOURCE_TITLES_RESULT';

    public function __construct(private LegacyWorkerRunner $worker) {}

    #[\Override]
    public function reapply(OperatorDatabase $database, array $localDataIds): array
    {
        // No timeout: the original warned never to interrupt a rename.
        $run = $this->worker->run('legacy-data-source-titles.php', self::MARKER, [
            'database' => $database === OperatorDatabase::Main ? 'main' : 'local',
            'data_sources' => $localDataIds,
        ], null);
        $titles = $run['result']['titles'] ?? null;
        if (!$run['ok'] || !is_array($titles) || !array_is_list($titles)) {
            throw new \RuntimeException('Data source title outcome is unknown.');
        }
        foreach ($titles as $title) {
            if (!is_string($title)) {
                throw new \RuntimeException('Data source title outcome is unknown.');
            }
        }

        return $titles;
    }
}
