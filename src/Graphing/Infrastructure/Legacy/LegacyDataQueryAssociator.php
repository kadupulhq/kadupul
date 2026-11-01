<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Legacy;

use Kadupul\Graphing\Application\Port\DataQueryAssociator;
use Kadupul\Graphing\Domain\ReindexMethod;
use Kadupul\IdentityAccess\Contract\OperatorDatabase;
use Kadupul\Platform\Contract\LegacyWorkerRunner;

/** Runs the data query association in the legacy worker, which owns run_data_query() and its plugin hooks. */
final readonly class LegacyDataQueryAssociator implements DataQueryAssociator
{
    public const string MARKER = 'KADUPUL_DATA_QUERY_ASSOCIATE_RESULT';

    public function __construct(private LegacyWorkerRunner $worker) {}

    #[\Override]
    public function associate(OperatorDatabase $database, int $deviceId, int $dataQueryId, ReindexMethod $method): void
    {
        // No timeout: the run queries the device over SNMP, and stopping it half way would leave its cache partly filled.
        $run = $this->worker->run('legacy-data-query-associate.php', self::MARKER, [
            'database' => $database === OperatorDatabase::Main ? 'main' : 'local',
            'host_id' => $deviceId,
            'data_query_id' => $dataQueryId,
            'reindex_method' => $method->value,
        ], null);
        if (!$run['ok'] || ($run['result']['status'] ?? null) !== 'ok') {
            throw new \RuntimeException('The data query could not be associated.');
        }
    }
}
