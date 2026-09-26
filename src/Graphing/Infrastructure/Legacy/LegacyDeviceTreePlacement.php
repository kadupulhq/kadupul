<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Legacy;

use Closure;
use Kadupul\Graphing\Contract\DeviceTreePlacement;
use Kadupul\IdentityAccess\Contract\ResourceAccess;
use Kadupul\Platform\Contract\DatabaseConnection;
use PDO;

final readonly class LegacyDeviceTreePlacement implements DeviceTreePlacement
{
    public function __construct(private DatabaseConnection $database, private ResourceAccess $access, private ?Closure $treeItemWriter = null) {}
    public function destinations(int $actorId): array
    {
        $db = $this->database->get();
        $result = [];
        $trees = [];
        $own = $this->access->canManageTree($actorId, $actorId);
        $others = $this->access->canManageTree($actorId, 0);
        foreach ($db->query('SELECT id, name, user_id, locked, modified_by FROM graph_tree ORDER BY name, id')->fetchAll(PDO::FETCH_ASSOC) as $tree) {
            if (!((int) $tree['user_id'] === $actorId ? $own : $others)
                || ((bool) $tree['locked'] && (int) $tree['modified_by'] !== $actorId)) {
                continue;
            }
            $trees[(int) $tree['id']] = $tree['name'];
        }
        if ($trees === []) {
            return [];
        }
        $branches = [];
        $query = $db->query("SELECT id, title, graph_tree_id FROM graph_tree_items WHERE host_id = 0 AND local_graph_id = 0 ORDER BY graph_tree_id, title, id");
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $branch) {
            if (isset($trees[(int) $branch['graph_tree_id']])) {
                $branches[(int) $branch['graph_tree_id']][] = $branch;
            }
        }
        foreach ($trees as $id => $name) {
            $result[$id . ':0'] = $name . ' (#' . $id . ')';
            foreach ($branches[$id] ?? [] as $branch) {
                $result[$id . ':' . $branch['id']] = $name . ' / ' . $branch['title'] . ' (#' . $branch['id'] . ')';
            }
        }
        return $result;
    }
    public function place(int $actorId, array $deviceIds, int $treeId, int $parentId): array
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
            $query = $db->prepare("SELECT id FROM graph_tree_items WHERE id = ? AND graph_tree_id = ? AND host_id = 0 AND local_graph_id = 0 FOR UPDATE");
            $query->execute([$parentId, $treeId]);
            if ($query->fetchColumn() === false) {
                throw new \RuntimeException('Tree branch unavailable');
            }
        }
        $query = $db->prepare('SELECT id FROM graph_tree_items WHERE graph_tree_id = ? AND parent = ? AND host_id = ? FOR UPDATE');
        foreach ($deviceIds as $deviceId) {
            $query->execute([$treeId, $parentId, $deviceId]);
            if ($query->fetchColumn() === false && !$this->saveTreeItem($treeId, $parentId, $deviceId)) {
                throw new \RuntimeException('Tree placement failed');
            }
            $query->execute([$treeId, $parentId, $deviceId]);
            if ($query->fetchColumn() === false) {
                throw new \RuntimeException('Tree placement could not be confirmed');
            }
        }
        return $this->records($deviceIds, $treeId, $parentId);
    }
    private function saveTreeItem(int $treeId, int $parentId, int $deviceId): bool
    {
        if ($this->treeItemWriter !== null) {
            return ($this->treeItemWriter)($treeId, $parentId, $deviceId);
        }
        return api_tree_item_save(0, $treeId, TREE_ITEM_TYPE_HOST, $parentId, '', 0, $deviceId, 0, 1, 1, false);
    }
    public function verify(int $actorId, array $deviceIds, int $treeId, int $parentId, array $expected): void
    {
        if ($this->records($deviceIds, $treeId, $parentId) !== $expected) {
            throw new \RuntimeException('Placement changed during callbacks');
        }
        $db = $this->database->get();
        $query = $db->prepare('SELECT id, user_id, locked, modified_by FROM graph_tree WHERE id = ?');
        $query->execute([$treeId]);
        $tree = $query->fetch(PDO::FETCH_ASSOC);
        if (!$tree || !$this->available($actorId, $tree)) {
            throw new \RuntimeException('Tree destination changed');
        }
        if ($parentId > 0) {
            $query = $db->prepare("SELECT id FROM graph_tree_items WHERE id = ? AND graph_tree_id = ? AND host_id = 0 AND local_graph_id = 0");
            $query->execute([$parentId, $treeId]);
            if ($query->fetchColumn() === false) {
                throw new \RuntimeException('Tree branch changed');
            }
        }
        $query = $db->prepare('SELECT COUNT(*) FROM graph_tree_items WHERE graph_tree_id = ? AND parent = ? AND host_id = ?');
        foreach ($deviceIds as $deviceId) {
            $query->execute([$treeId, $parentId, $deviceId]);
            if ((int) $query->fetchColumn() < 1) {
                throw new \RuntimeException('Tree placement could not be confirmed');
            }
        }
    }
    private function available(int $actorId, array $tree): bool
    {
        return $this->access->canManageTree($actorId, (int) $tree['user_id']) && (!(bool) $tree['locked'] || (int) $tree['modified_by'] === $actorId);
    }
    private function records(array $deviceIds, int $treeId, int $parentId): array
    {
        $placeholders = implode(',', array_fill(0, count($deviceIds), '?'));
        $query = $this->database->get()->prepare("SELECT id, graph_tree_id, parent, host_id, local_graph_id, site_id, title, host_grouping_type, sort_children_type FROM graph_tree_items WHERE graph_tree_id = ? AND parent = ? AND host_id IN ($placeholders) ORDER BY id");
        $query->execute([$treeId, $parentId, ...$deviceIds]);
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }
}
