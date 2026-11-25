<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Automation\Infrastructure\Persistence;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Kadupul\Automation\Application\Port\AutomationTemplateAccess;
use Kadupul\Automation\Application\Port\TreeRuleStore;
use Kadupul\Automation\Domain\TreeRule;
use Kadupul\Automation\Domain\TreeRuleChange;
use Kadupul\Automation\Domain\TreeRuleRejected;
use Kadupul\IdentityAccess\Contract\AuditTrail;
use Kadupul\Platform\Contract\LegacyConfiguration;
use Kadupul\Platform\Infrastructure\Persistence\AuditedPrimaryWrite;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * automation_tree_rules. lib/api_automation.php reads enabled rules when it
 * adds devices or graphs to trees, placing them under tree_item_id of tree_id.
 * A rule's Tree Creation Criteria are its automation_tree_rule_items, and its
 * Object Selection Criteria are the automation_match_rule_items of rule_type 3;
 * graph rules keep theirs in the same table under rule_type 1. A bulk action
 * locks its rules in ID order.
 */
#[AsAlias(TreeRuleStore::class)]
final readonly class DbalTreeRuleStore implements TreeRuleStore
{
    /** AUTOMATION_RULE_TYPE_TREE_MATCH; graph rules keep their match items in the same table. */
    private const int TREE_MATCH = 3;
    /** Criteria on graph and graph template columns, which Device leaves cannot evaluate. */
    private const string GRAPH_FIELD = "(field LIKE 'gtg.%' OR field LIKE 'gt.%')";
    // Every table a write changes or locks, with the permission tables the actor recheck reads.
    private const array WRITE_TABLES = ['automation_tree_rules', 'automation_tree_rule_items', 'automation_match_rule_items', 'graph_tree', 'graph_tree_items',
        'user_auth', 'user_auth_realm', 'user_auth_group', 'user_auth_group_realm', 'user_auth_group_members', 'settings'];
    private const string STALE = 'The tree rule changed since you opened this form. Reload before saving.';
    private const string SELECTION_INVALID = 'Invalid Tree Rule selection.';
    private const string SELECTION_MISSING = 'One or more selected Tree Rules no longer exist.';
    /** automation_tree_rules.id is an unsigned mediumint column. */
    private const int MAX_ID = 16777215;
    // Every criteria column except the item's own ID and its rule.
    private const array RULE_ITEM_COLUMNS = ['sequence', 'field', 'sort_type', 'propagate_changes', 'search_pattern', 'replace_pattern'];
    private const array MATCH_ITEM_COLUMNS = ['rule_type', 'sequence', 'operation', 'field', 'operator', 'pattern'];

    private AuditedPrimaryWrite $writes;

    public function __construct(
        #[Autowire(service: 'doctrine.dbal.web_connection')]
        private Connection $database,
        AutomationTemplateAccess $access,
        AuditTrail $audit,
        LegacyConfiguration $configuration,
    ) {
        // Tree Rules share the automation realm, so the Device Rules recheck applies unchanged.
        $this->writes = new AuditedPrimaryWrite(
            $database,
            $audit,
            $configuration,
            $access->assertCurrent(...),
            self::WRITE_TABLES,
            'Tree Rule',
            'Tree Rules',
            'automation.tree_rule',
            'automation_tree_rule'
        );
    }

    public function choices(): array
    {
        $trees = [];
        foreach ($this->database->fetchAllAssociative('SELECT id, name FROM graph_tree ORDER BY name, id') as $row) {
            $trees[self::int($row['id'])] = self::text($row['name']);
        }
        // The headers grow_dropdown_tree() offered: items without a device or graph, in tree order.
        $children = [];
        foreach ($this->database->fetchAllAssociative(
            'SELECT id, graph_tree_id, parent, title FROM graph_tree_items WHERE host_id = 0 AND local_graph_id = 0 ORDER BY graph_tree_id, parent, position, id'
        ) as $row) {
            $children[self::int($row['graph_tree_id'])][self::int($row['parent'])][] = ['id' => self::int($row['id']), 'title' => self::text($row['title'])];
        }
        $headers = [];
        foreach (array_keys($trees) as $tree) {
            $pending = array_map(static fn(array $item): array => $item + ['depth' => 1], array_reverse($children[$tree][0] ?? []));
            while ($pending !== []) {
                $item = array_pop($pending);
                if (isset($headers[$item['id']])) {
                    continue; // A parent cycle in damaged data must not loop.
                }
                $headers[$item['id']] = ['tree' => $tree, 'title' => $item['title'], 'depth' => $item['depth']];
                foreach (array_reverse($children[$tree][$item['id']] ?? []) as $child) {
                    $pending[] = $child + ['depth' => $item['depth'] + 1];
                }
            }
        }
        return ['trees' => $trees, 'headers' => $headers];
    }

    public function find(int $id): ?TreeRule
    {
        $row = $this->database->fetchAssociative('SELECT ' . implode(', ', TreeRule::COLUMNS) . ' FROM automation_tree_rules WHERE id = ?', [$id]);
        if ($row === false) {
            return null;
        }
        return self::rule($row);
    }

    public function findRules(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $rules = [];
        foreach ($this->database->fetchAllAssociative('SELECT ' . implode(', ', TreeRule::COLUMNS) . ' FROM automation_tree_rules WHERE id IN (?) ORDER BY id', [$ids], [ArrayParameterType::INTEGER]) as $row) {
            $rules[self::int($row['id'])] = self::rule($row);
        }
        return $rules;
    }

    public function save(int $actorId, ?int $id, TreeRuleChange $change, ?string $revision): int
    {
        return $this->writes->run($actorId, $id === null ? 'create' : 'edit', 'automation_tree_rule', function () use ($id, $change, $revision): int {
            $this->assertPlacement($change);
            if ($id === null) {
                $this->database->insert('automation_tree_rules', $change->columns());
                $created = (int) $this->database->lastInsertId();
                if ($created < 1) {
                    throw new \RuntimeException('Tree rule creation was not confirmed.');
                }
                return $created;
            }
            $row = $this->database->fetchAssociative('SELECT ' . implode(', ', TreeRule::COLUMNS) . ' FROM automation_tree_rules WHERE id = ?' . $this->lock(' FOR UPDATE'), [$id]);
            if ($row === false) {
                throw new \InvalidArgumentException('Tree rule not found.');
            }
            if ($revision === null || !hash_equals(TreeRule::revisionOf($row), $revision)) {
                throw new \InvalidArgumentException(self::STALE);
            }
            if ($change->leafType === TreeRuleChange::LEAF_DEVICE && self::int($row['leaf_type']) !== TreeRuleChange::LEAF_DEVICE) {
                $this->removeGraphCriteria($id, $change->removeGraphCriteria);
            }
            $this->database->update('automation_tree_rules', $change->columns(), ['id' => $id]);
            return $id;
        });
    }

    public function deleteRules(int $actorId, array $ids, array $revisions): void
    {
        AuditedPrimaryWrite::assertSelection($ids, $revisions, self::MAX_SELECTION, self::MAX_ID, self::SELECTION_INVALID, self::STALE);
        $this->writes->run($actorId, 'delete', 'automation_tree_rule', function () use ($ids, $revisions): void {
            $this->lockRules($ids, $revisions);
            // The legacy bulk delete removed every match item with these rule IDs, a graph
            // rule's too (#1533); only this rule type's items belong to a tree rule.
            $this->database->executeStatement(
                'DELETE FROM automation_match_rule_items WHERE rule_id IN (?) AND rule_type = ?',
                [$ids, self::TREE_MATCH],
                [ArrayParameterType::INTEGER, ParameterType::INTEGER],
            );
            $this->database->executeStatement('DELETE FROM automation_tree_rule_items WHERE rule_id IN (?)', [$ids], [ArrayParameterType::INTEGER]);
            if ($this->database->executeStatement('DELETE FROM automation_tree_rules WHERE id IN (?)', [$ids], [ArrayParameterType::INTEGER]) !== count($ids)) {
                throw new \RuntimeException('Tree Rule deletion was not confirmed.');
            }
        });
    }

    public function duplicateRules(int $actorId, array $ids, array $revisions, string $nameFormat): void
    {
        AuditedPrimaryWrite::assertSelection($ids, $revisions, self::MAX_SELECTION, self::MAX_ID, self::SELECTION_INVALID, self::STALE);
        // Refuse a malformed format before the transaction; the names it produces are checked under the lock.
        TreeRule::copyName($nameFormat, 'x');
        $this->writes->run($actorId, 'duplicate', 'automation_tree_rule', function () use ($ids, $revisions, $nameFormat): void {
            $copies = [];
            foreach ($this->lockRules($ids, $revisions) as $id => $row) {
                $copies[$id] = [
                    'name' => TreeRule::copyName($nameFormat, self::text($row['name'])),
                    'tree_id' => self::int($row['tree_id']),
                    'tree_item_id' => self::int($row['tree_item_id']),
                    'leaf_type' => self::int($row['leaf_type']),
                    'host_grouping_type' => self::int($row['host_grouping_type']),
                    // As on the legacy page, no copy takes action before it is reviewed.
                    'enabled' => '',
                ];
            }
            $pairs = [];
            foreach ($copies as $id => $columns) {
                // One insert per copy: the new ID is only reliable for a single-row insert.
                $this->database->insert('automation_tree_rules', $columns);
                $copy = (int) $this->database->lastInsertId();
                if ($copy < 1) {
                    throw new \RuntimeException('Tree Rule duplication was not confirmed.');
                }
                array_push($pairs, $id, $copy);
            }
            // Each kind of criteria is copied for every selected rule by one statement.
            $map = implode(' UNION ALL ', array_fill(0, count($copies), 'SELECT ? AS source, ? AS copy'));
            $types = array_fill(0, count($pairs), ParameterType::INTEGER);
            $this->database->executeStatement(
                'INSERT INTO automation_match_rule_items (rule_id, ' . implode(', ', self::MATCH_ITEM_COLUMNS) . ')
                SELECT copies.copy, items.' . implode(', items.', self::MATCH_ITEM_COLUMNS) . "
                FROM automation_match_rule_items items INNER JOIN ($map) copies ON copies.source = items.rule_id
                WHERE items.rule_type = ?
                ORDER BY items.rule_id, items.sequence, items.id",
                [...$pairs, self::TREE_MATCH],
                [...$types, ParameterType::INTEGER],
            );
            $this->database->executeStatement(
                'INSERT INTO automation_tree_rule_items (rule_id, ' . implode(', ', self::RULE_ITEM_COLUMNS) . ')
                SELECT copies.copy, items.' . implode(', items.', self::RULE_ITEM_COLUMNS) . "
                FROM automation_tree_rule_items items INNER JOIN ($map) copies ON copies.source = items.rule_id
                ORDER BY items.rule_id, items.sequence, items.id",
                $pairs,
                $types,
            );
        });
    }

    public function setEnabled(int $actorId, array $ids, array $revisions, bool $enabled): void
    {
        AuditedPrimaryWrite::assertSelection($ids, $revisions, self::MAX_SELECTION, self::MAX_ID, self::SELECTION_INVALID, self::STALE);
        $this->writes->run($actorId, $enabled ? 'enable' : 'disable', 'automation_tree_rule', function () use ($ids, $revisions, $enabled): void {
            $this->lockRules($ids, $revisions);
            // The legacy values: 'on' and the empty string. Every row is locked and
            // present, so a rule that already had the value is not an error.
            $this->database->executeStatement(
                'UPDATE automation_tree_rules SET enabled = ? WHERE id IN (?)',
                [$enabled ? 'on' : '', $ids],
                [ParameterType::STRING, ArrayParameterType::INTEGER],
            );
        });
    }

    /**
     * Lock the selected rules in ID order and compare each with the revision the form showed.
     *
     * @param non-empty-list<int> $ids
     * @param array<int, string> $revisions
     * @return array<int, array<string, mixed>> ID => stored row
     */
    private function lockRules(array $ids, array $revisions): array
    {
        $rows = [];
        foreach ($this->database->fetchAllAssociative('SELECT ' . implode(', ', TreeRule::COLUMNS) . ' FROM automation_tree_rules WHERE id IN (?) ORDER BY id' . $this->lock(' FOR UPDATE'), [$ids], [ArrayParameterType::INTEGER]) as $row) {
            $id = self::int($row['id']);
            if (!hash_equals(TreeRule::revisionOf($row), $revisions[$id] ?? '')) {
                throw new \InvalidArgumentException(self::STALE);
            }
            $rows[$id] = $row;
        }
        if (count($rows) !== count($ids)) {
            throw new \InvalidArgumentException(self::SELECTION_MISSING);
        }
        return $rows;
    }

    /** @param array<string, mixed> $row */
    private static function rule(array $row): TreeRule
    {
        return new TreeRule(
            self::int($row['id']),
            self::text($row['name']),
            self::int($row['tree_id']),
            self::int($row['tree_item_id']),
            self::int($row['leaf_type']),
            self::int($row['host_grouping_type']),
            self::text($row['enabled']) === 'on',
            TreeRule::revisionOf($row)
        );
    }

    /**
     * Device leaves cannot evaluate graph columns, so legacy removed those
     * criteria on the change. Match items are limited to this tree rule's
     * own type: a graph rule with the same ID keeps its criteria.
     */
    private function removeGraphCriteria(int $id, bool $confirmed): void
    {
        $lock = $this->lock(' FOR UPDATE');
        $actions = $this->database->fetchFirstColumn('SELECT id FROM automation_tree_rule_items WHERE rule_id = ? AND ' . self::GRAPH_FIELD . $lock, [$id]);
        $matches = $this->database->fetchFirstColumn(
            'SELECT id FROM automation_match_rule_items WHERE rule_id = ? AND rule_type = ? AND ' . self::GRAPH_FIELD . $lock,
            [$id, self::TREE_MATCH]
        );
        $count = count($actions) + count($matches);
        if ($count === 0) {
            return;
        }
        if (!$confirmed) {
            throw new TreeRuleRejected(
                'Device leaves cannot use graph-based criteria. Confirm the removal of %count% criteria to save.',
                ['%count%' => (string) $count],
                'remove_graph_criteria'
            );
        }
        $this->database->executeStatement('DELETE FROM automation_tree_rule_items WHERE rule_id = ? AND ' . self::GRAPH_FIELD, [$id]);
        $this->database->executeStatement('DELETE FROM automation_match_rule_items WHERE rule_id = ? AND rule_type = ? AND ' . self::GRAPH_FIELD, [$id, self::TREE_MATCH]);
    }

    /** Shared locks keep the tree and its header from being deleted before commit. */
    private function assertPlacement(TreeRuleChange $change): void
    {
        $lock = $this->lock(' LOCK IN SHARE MODE');
        if ($this->database->fetchOne('SELECT id FROM graph_tree WHERE id = ?' . $lock, [$change->treeId]) === false) {
            throw new TreeRuleRejected('Choose an existing Tree.', [], 'tree_id');
        }
        if ($change->treeItemId !== 0 && $this->database->fetchOne(
            'SELECT id FROM graph_tree_items WHERE id = ? AND graph_tree_id = ? AND host_id = 0 AND local_graph_id = 0' . $lock,
            [$change->treeItemId, $change->treeId]
        ) === false) {
            throw new TreeRuleRejected('Choose a header of the selected Tree.', [], 'tree_item_id');
        }
    }

    private function lock(string $clause): string
    {
        return $this->database->getDatabasePlatform() instanceof AbstractMySQLPlatform ? $clause : '';
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    /** NULL columns read as empty text, as the legacy form showed them. */
    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : (is_int($value) || is_float($value) ? (string) $value : '');
    }
}
