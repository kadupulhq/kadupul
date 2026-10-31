<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Legacy;

use Kadupul\Graphing\Application\Port\GraphTemplateAttacher;
use Kadupul\IdentityAccess\Contract\OperatorDatabase;
use Kadupul\Platform\Contract\LegacyWorkerRunner;

final readonly class LegacyGraphTemplateAttacher implements GraphTemplateAttacher
{
    public const string MARKER = 'KADUPUL_GRAPH_TEMPLATE_ATTACH_RESULT';

    public function __construct(private LegacyWorkerRunner $worker) {}

    #[\Override]
    public function attach(OperatorDatabase $database, int $deviceId, int $graphTemplateId): void
    {
        // No timeout: automation may create graphs for the device, and stopping it half way would leave some missing.
        $run = $this->worker->run('legacy-graph-template-attach.php', self::MARKER, [
            'database' => $database === OperatorDatabase::Main ? 'main' : 'local',
            'host_id' => $deviceId,
            'graph_template_id' => $graphTemplateId,
        ], null);
        if (!$run['ok'] || ($run['result']['status'] ?? null) !== 'ok') {
            throw new \RuntimeException('The graph template could not be associated.');
        }
    }
}
