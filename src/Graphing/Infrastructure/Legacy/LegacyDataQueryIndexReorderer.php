<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Legacy;

use Kadupul\Graphing\Application\Port\DataQueryIndexItem;
use Kadupul\Graphing\Application\Port\DataQueryIndexReorderer;
use Kadupul\Graphing\Application\Port\DataQueryReorderResult;
use Kadupul\IdentityAccess\Contract\OperatorDatabase;
use Kadupul\Platform\Contract\LegacyWorkerRunner;

final readonly class LegacyDataQueryIndexReorderer implements DataQueryIndexReorderer
{
    public const string MARKER = 'KADUPUL_DATA_QUERY_REORDER_RESULT';

    /** A worker reads at most 20000 bytes of JSON; leave room for the envelope. */
    private const int PAYLOAD_BYTES = 18000;

    public function __construct(private LegacyWorkerRunner $worker) {}

    #[\Override]
    public function reorder(OperatorDatabase $database, array $items): DataQueryReorderResult
    {
        $indexOn = [];
        foreach ($this->batches($items) as $batch) {
            // No timeout: the original warned never to interrupt a reorder.
            $run = $this->worker->run('legacy-data-query-reorder.php', self::MARKER, [
                'database' => $database === OperatorDatabase::Main ? 'main' : 'local',
                'items' => array_map(static fn(DataQueryIndexItem $item): array => [$item->hostId, $item->snmpQueryId, $item->snmpIndex, $item->dataTemplateDataId, $item->outputType], $batch),
            ], null);
            $done = $run['result']['index_on'] ?? null;
            $unresolved = $run['result']['unresolved'] ?? null;
            if (!$run['ok'] || !is_array($done) || !array_is_list($done) || !is_bool($unresolved) || count($done) > count($batch)
                || (!$unresolved && count($done) !== count($batch))) {
                throw new \RuntimeException('Data query reorder outcome is unknown.');
            }
            foreach ($done as $field) {
                if (!is_string($field)) {
                    throw new \RuntimeException('Data query reorder outcome is unknown.');
                }
                $indexOn[] = $field;
            }
            if ($unresolved) {
                return new DataQueryReorderResult($indexOn, true);
            }
        }

        return new DataQueryReorderResult($indexOn, false);
    }

    /**
     * Groups of items whose JSON fits one worker payload, so a large selection
     * is neither one oversized payload nor one process per item.
     *
     * @param list<DataQueryIndexItem> $items
     * @return list<list<DataQueryIndexItem>>
     */
    private function batches(array $items): array
    {
        $batches = [];
        $batch = [];
        $size = 0;
        foreach ($items as $item) {
            $bytes = strlen(json_encode([$item->hostId, $item->snmpQueryId, $item->snmpIndex, $item->dataTemplateDataId, $item->outputType], JSON_THROW_ON_ERROR)) + 1;
            if ($batch !== [] && $size + $bytes > self::PAYLOAD_BYTES) {
                $batches[] = $batch;
                $batch = [];
                $size = 0;
            }
            $batch[] = $item;
            $size += $bytes;
        }

        return $batch === [] ? $batches : [...$batches, $batch];
    }
}
