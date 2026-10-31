<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Application\Command;

use Kadupul\Graphing\Application\Port\TreeCatalog;
use Kadupul\Graphing\Application\Port\TreeNodeDraft;
use Kadupul\Graphing\Application\Port\TreeWriter;

/** The node branch of add_tree.php, checks in the order the script made them. */
final readonly class AddTreeNode
{
    private const string ACTION = 'tree.node.create';

    public function __construct(private GraphCommandAccess $access, private TreeCatalog $catalog, private TreeWriter $writer) {}

    /**
     * @param ?string $operator account to act as; null means the admin_user setting
     * @param string $nodeType a key of TreeLabels::NODE_TYPES
     * @param string $sortMethod a key of TreeLabels::SORT_METHODS; any other text saves the node with inherited sorting, as the script did
     * @param string $treeId, $parentNode, $name, $graphId, $deviceId, $siteId, $groupingStyle as typed
     * @throws GraphManagementDenied
     */
    public function __invoke(bool $local, ?string $operator, string $nodeType, string $sortMethod, string $treeId, string $parentNode, string $name, string $graphId, string $deviceId, string $siteId, string $groupingStyle): TreeNodeCreation
    {
        $scope = $this->access->select(self::ACTION, $local, $operator, true, ManagedArea::Trees);
        $database = $scope->database;
        $refuse = static fn(string $status, ?int $tree = null): TreeNodeCreation => new TreeNodeCreation($database, $status, $tree);
        if (!ctype_digit($treeId) || (int) $treeId <= 0) {
            return $refuse(TreeNodeCreation::BAD_TREE);
        }
        $tree = (int) $treeId;
        if (!$this->catalog->treeExists($database, $tree)) {
            return $refuse(TreeNodeCreation::UNKNOWN_TREE, $tree);
        }
        if (!ctype_digit($parentNode)) {
            return $refuse(TreeNodeCreation::BAD_PARENT, $tree);
        }
        $parent = (int) $parentNode;
        if ($parent > 0) {
            $item = $this->catalog->findItem($database, $tree, $parent);
            if ($item === null) {
                return $refuse(TreeNodeCreation::UNKNOWN_PARENT, $tree);
            }
            if (!$item->isHeader()) {
                return $refuse(TreeNodeCreation::PARENT_NOT_HEADER, $tree);
            }
        }
        // What the node keeps of the operator's options depends on its kind; the others are blanked.
        $title = '';
        $graph = $device = $site = 0;
        $grouping = 1;
        switch ($nodeType) {
            case 'header':
                if ($name === '' || $name === '0') {
                    return $refuse(TreeNodeCreation::NAME_REQUIRED, $tree);
                }
                $title = $name;
                break;
            case 'graph':
                $graph = $this->catalog->findGraph($database, $graphId) ?? 0;
                if ($graph === 0) {
                    return $refuse(TreeNodeCreation::UNKNOWN_GRAPH, $tree);
                }
                break;
            case 'site':
                // The label and the order of a site node come from sites.name through the id.
                if (!$this->catalog->siteExists($database, $siteId)) {
                    return $refuse(TreeNodeCreation::UNKNOWN_SITE, $tree);
                }
                $site = (int) $siteId;
                // The script saved the style as typed, and only a device node checked it.
                $grouping = $groupingStyle === '' ? 1 : (is_numeric($groupingStyle) ? (int) $groupingStyle : 0);
                break;
            default:
                if (!$this->catalog->deviceExists($database, $deviceId)) {
                    return $refuse(TreeNodeCreation::UNKNOWN_DEVICE, $tree);
                }
                if (!is_numeric($groupingStyle) || ((float) $groupingStyle !== 1.0 && (float) $groupingStyle !== 2.0)) {
                    return $refuse(TreeNodeCreation::BAD_GROUPING, $tree);
                }
                $device = (int) $deviceId;
                $grouping = (int) $groupingStyle;
        }
        $correlation = $this->access->correlation();
        $nodeId = null;
        try {
            $nodeId = $this->writer->createNode($database, new TreeNodeDraft($tree, TreeLabels::NODE_TYPES[$nodeType], $parent, $title, $graph, $device, $site, $grouping, TreeLabels::SORT_METHODS[$sortMethod] ?? 0));
        } catch (\RuntimeException) {
            // Reported as the original's "Failed to create the node" line; the audit record below says the same.
        } finally {
            $this->access->change($correlation, $scope, self::ACTION, 'tree-node', $tree . '.' . ($nodeId ?? 0), $nodeId !== null);
        }

        return new TreeNodeCreation($database, $nodeId === null ? TreeNodeCreation::FAILED : TreeNodeCreation::CREATED, $tree, $nodeId);
    }
}
