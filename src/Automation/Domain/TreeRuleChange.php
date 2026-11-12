<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Automation\Domain;

/** A validated edit of the automation_tree_rules settings; criteria items are edited separately. */
final readonly class TreeRuleChange
{
    /** The width of automation_tree_rules.name in cacti.sql. */
    public const int NAME_LENGTH = 255;
    /** TREE_ITEM_TYPE_GRAPH and TREE_ITEM_TYPE_HOST, the leaf types automation offers. */
    public const array LEAF_TYPES = [2, 3];
    public const int LEAF_DEVICE = 3;
    /** HOST_GROUPING_GRAPH_TEMPLATE and HOST_GROUPING_DATA_QUERY_INDEX. */
    public const array GROUPING_TYPES = [1, 2];
    /** tree_id is an unsigned smallint, tree_item_id an unsigned mediumint. */
    private const int TREE_MAX = 65535;
    private const int ITEM_MAX = 16777215;

    public string $name;

    /**
     * @param int $treeItemId the header to add items under, or 0 for the tree's root
     * @param bool $removeGraphCriteria confirms that a change to Device leaves may remove graph-based criteria
     */
    public function __construct(
        string $name,
        public int $treeId,
        public int $treeItemId,
        public int $leafType,
        public int $hostGroupingType,
        public bool $enabled,
        public bool $removeGraphCriteria = false,
    ) {
        if (preg_match('//u', $name) !== 1 || preg_match('/[\x00-\x1f\x7f]/', $name) === 1) {
            throw new TreeRuleRejected('Enter single-line text.', [], 'name');
        }
        $name = trim($name);
        if ($name === '') {
            throw new TreeRuleRejected('Enter a name.', [], 'name');
        }
        if (mb_strlen($name) > self::NAME_LENGTH) {
            throw new TreeRuleRejected('Enter at most %count% characters.', ['%count%' => (string) self::NAME_LENGTH], 'name');
        }
        if ($treeId < 1 || $treeId > self::TREE_MAX) {
            throw new TreeRuleRejected('Choose an existing Tree.', [], 'tree_id');
        }
        if ($treeItemId < 0 || $treeItemId > self::ITEM_MAX) {
            throw new TreeRuleRejected('Choose a header of the selected Tree.', [], 'tree_item_id');
        }
        if (!in_array($leafType, self::LEAF_TYPES, true)) {
            throw new TreeRuleRejected('Choose a listed Leaf Item Type.', [], 'leaf_type');
        }
        if (!in_array($hostGroupingType, self::GROUPING_TYPES, true)) {
            throw new TreeRuleRejected('Choose a listed Graph Grouping Style.', [], 'host_grouping_type');
        }
        $this->name = $name;
    }

    /** @return array{name: string, tree_id: int, tree_item_id: int, leaf_type: int, host_grouping_type: int, enabled: string} */
    public function columns(): array
    {
        return ['name' => $this->name, 'tree_id' => $this->treeId, 'tree_item_id' => $this->treeItemId, 'leaf_type' => $this->leafType,
            'host_grouping_type' => $this->hostGroupingType, 'enabled' => $this->enabled ? 'on' : ''];
    }
}
