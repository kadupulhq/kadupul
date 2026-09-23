<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Legacy;

use Kadupul\Graphing\Contract\DeviceTreePlacement;
use Kadupul\IdentityAccess\Contract\ResourceAccess;
use Kadupul\Platform\Contract\DatabaseConnection;
use PDO;

final readonly class LegacyDeviceTreePlacement implements DeviceTreePlacement
{
    public function __construct(private DatabaseConnection $database, private ResourceAccess $access) {}
    public function destinations(int $actorId): array
    {
        $db = $this->database->get();
        $result = [];
        foreach ($db->query('SELECT id, name, user_id, locked, modified_by FROM graph_tree ORDER BY name, id')->fetchAll(PDO::FETCH_ASSOC) as $tree) {
            if (!$this->available($actorId, $tree)) {
                continue;
            }
            $id = (int) $tree['id'];
            $result[$id . ':0'] = $tree['name'] . ' (#' . $id . ')';
            $query = $db->prepare("SELECT id, title FROM graph_tree_items WHERE graph_tree_id = ? AND host_id = 0 AND local_graph_id = 0 AND site_id = 0 AND title <> '' ORDER BY title, id");
            $query->execute([$id]);
            foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $branch) {
                $result[$id . ':' . $branch['id']] = $tree['name'] . ' / ' . $branch['title'] . ' (#' . $branch['id'] . ')';
            }
        }
        return $result;
    }
    public function place(int $actorId, array $deviceIds, int $treeId, int $parentId): void
    {
        $db = $this->database->get();
        if (!$db->inTransaction()) {
            throw new \RuntimeException('Tree placement requires a transaction');
        }
        $query = $db->prepare('SELECT id, user_id, locked, modified_by FROM graph_tree WHERE id = ? FOR UPDATE');
        $query->execute([$treeId]);
        $tree = $query->fetch(PDO::FETCH_ASSOC);
        if (!$tree || !$this->available($actorId, $tree)) {
            throw new \RuntimeException('Tree destination unavailable');
        }
        if ($parentId > 0) {
            $query = $db->prepare("SELECT id FROM graph_tree_items WHERE id = ? AND graph_tree_id = ? AND host_id = 0 AND local_graph_id = 0 AND site_id = 0 AND title <> '' FOR UPDATE");
            $query->execute([$parentId, $treeId]);
            if ($query->fetchColumn() === false) {
                throw new \RuntimeException('Tree branch unavailable');
            }
        }
        $query = $db->prepare('SELECT id FROM graph_tree_items WHERE graph_tree_id = ? AND parent = ? AND host_id = ? FOR UPDATE');
        foreach ($deviceIds as $deviceId) {
            $query->execute([$treeId, $parentId, $deviceId]);
            if ($query->fetchColumn() === false && !api_tree_item_save(0, $treeId, TREE_ITEM_TYPE_HOST, $parentId, '', 0, $deviceId, 0, 1, 1, false)) {
                throw new \RuntimeException('Tree placement failed');
            }
            $query->execute([$treeId, $parentId, $deviceId]);
            if ($query->fetchColumn() === false) {
                throw new \RuntimeException('Tree placement could not be confirmed');
            }
        }
    }
    private function available(int $actorId, array $tree): bool
    {
        return $this->access->canManageTree($actorId, (int) $tree['user_id']) && (!(bool) $tree['locked'] || (int) $tree['modified_by'] === $actorId);
    }
}
