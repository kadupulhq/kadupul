<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Legacy;

use Kadupul\Graphing\Application\Port\CreatedGraph;
use Kadupul\Graphing\Application\Port\GraphDraft;
use Kadupul\Graphing\Application\Port\GraphWriter;
use Kadupul\IdentityAccess\Contract\OperatorDatabase;
use Kadupul\Platform\Contract\LegacyWorkerRunner;

/** Runs the add_graphs.php writes in the legacy worker; each call is one worker run and a worker that does not report success is a failure. */
final readonly class LegacyGraphWriter implements GraphWriter
{
    public const string MARKER = 'KADUPUL_GRAPH_ADD_RESULT';

    public function __construct(private LegacyWorkerRunner $worker) {}

    #[\Override]
    public function associateQuery(OperatorDatabase $database, int $deviceId, int $queryId, int $reindexMethod): void
    {
        $this->run(['database' => self::name($database), 'kind' => 'query', 'host_id' => $deviceId, 'query_id' => $queryId, 'reindex_method' => $reindexMethod], 'The data query could not be associated.');
    }

    #[\Override]
    public function createGraph(OperatorDatabase $database, GraphDraft $graph): ?CreatedGraph
    {
        $result = $this->run([
            'database' => self::name($database),
            'kind' => 'graph',
            'template_id' => $graph->templateId,
            'host_id' => $graph->deviceId,
            'query_id' => $graph->queryId,
            'query_type_id' => $graph->queryTypeId,
            'query_index' => $graph->queryIndex,
            'custom_data' => $graph->customData,
            'title' => $graph->title,
        ], 'The graph could not be created.');
        if (($result['refused'] ?? false) === true) {
            return null;
        }
        $id = $result['graph_id'] ?? null;
        $first = $result['first_data_source'] ?? null;
        $reported = $result['data_source_ids'] ?? null;
        $sources = [];
        foreach (is_array($reported) && array_is_list($reported) ? $reported : [null] as $source) {
            if (!is_int($source)) {
                throw new \RuntimeException('The graph could not be created.');
            }
            $sources[] = $source;
        }
        if (!is_int($id) || $id < 1 || !($first === null || is_int($first))) {
            throw new \RuntimeException('The graph could not be created.');
        }

        return new CreatedGraph($id, $first, $sources);
    }

    #[\Override]
    public function retitle(OperatorDatabase $database, int $graphId, string $title): void
    {
        $this->run(['database' => self::name($database), 'kind' => 'title', 'graph_id' => $graphId, 'title' => $title], 'The graph title could not be saved.');
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<mixed>
     */
    private function run(array $payload, string $failure): array
    {
        // No timeout: a data query run or a graph creation stopped half way leaves rows behind.
        $run = $this->worker->run('legacy-graph-add.php', self::MARKER, $payload, null);
        if (!$run['ok'] || ($run['result']['status'] ?? null) !== 'ok') {
            throw new \RuntimeException($failure);
        }

        return $run['result'];
    }

    private static function name(OperatorDatabase $database): string
    {
        return $database === OperatorDatabase::Main ? 'main' : 'local';
    }
}
