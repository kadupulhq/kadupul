<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Legacy;

use Kadupul\Graphing\Application\Port\TreeNodeDraft;
use Kadupul\Graphing\Application\Port\TreeWriter;
use Kadupul\IdentityAccess\Contract\OperatorDatabase;
use Kadupul\Platform\Contract\LegacyWorkerRunner;

/** Runs the tree and tree node saves in the legacy worker; a worker that does not report a new id is a failure. */
final readonly class LegacyTreeWriter implements TreeWriter
{
    public const string MARKER = 'KADUPUL_TREE_SAVE_RESULT';

    public function __construct(private LegacyWorkerRunner $worker) {}

    #[\Override]
    public function createTree(OperatorDatabase $database, string $name, int $sortType): int
    {
        return $this->run(['database' => self::name($database), 'kind' => 'tree', 'name' => $name, 'sort_type' => $sortType], 'The tree could not be created.');
    }

    #[\Override]
    public function createNode(OperatorDatabase $database, TreeNodeDraft $node): int
    {
        return $this->run([
            'database' => self::name($database),
            'kind' => 'node',
            'tree_id' => $node->treeId,
            'item_type' => $node->itemType,
            'parent_id' => $node->parentId,
            'title' => $node->title,
            'graph_id' => $node->graphId,
            'host_id' => $node->deviceId,
            'site_id' => $node->siteId,
            'grouping_type' => $node->groupingType,
            'sort_type' => $node->sortType,
        ], 'The tree node could not be created.');
    }

    /** @param array<string, mixed> $payload */
    private function run(array $payload, string $failure): int
    {
        // No timeout: stopping branch sorting half way would leave the positions inconsistent.
        $run = $this->worker->run('legacy-tree-save.php', self::MARKER, $payload, null);
        $id = $run['result']['id'] ?? null;
        if (!$run['ok'] || ($run['result']['status'] ?? null) !== 'ok' || !is_int($id) || $id < 1) {
            throw new \RuntimeException($failure);
        }

        return $id;
    }

    private static function name(OperatorDatabase $database): string
    {
        return $database === OperatorDatabase::Main ? 'main' : 'local';
    }
}
