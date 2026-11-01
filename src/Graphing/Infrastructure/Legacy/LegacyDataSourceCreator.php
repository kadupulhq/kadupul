<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Legacy;

use Kadupul\Graphing\Application\Port\DataSourceCreator;
use Kadupul\IdentityAccess\Contract\OperatorDatabase;
use Kadupul\Platform\Contract\LegacyWorkerRunner;

/** Runs the data source creation in the legacy worker; a worker that does not report a new id counts as a failure. */
final readonly class LegacyDataSourceCreator implements DataSourceCreator
{
    public const string MARKER = 'KADUPUL_DATA_SOURCE_ADD_RESULT';

    public function __construct(private LegacyWorkerRunner $worker) {}

    #[\Override]
    public function create(OperatorDatabase $database, int $deviceId, int $dataTemplateId, bool $pushOut): int
    {
        // No timeout: stopping the template application or the push-out half way would leave a half-built data source.
        $run = $this->worker->run('legacy-data-source-add.php', self::MARKER, [
            'database' => $database === OperatorDatabase::Main ? 'main' : 'local',
            'host_id' => $deviceId,
            'data_template_id' => $dataTemplateId,
            'push_out' => $pushOut,
        ], null);
        $id = $run['result']['id'] ?? null;
        if (!$run['ok'] || ($run['result']['status'] ?? null) !== 'ok' || !is_int($id) || $id < 1) {
            throw new \RuntimeException('The data source could not be added.');
        }

        return $id;
    }
}
