<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Kadupul\Automation\Application\Port\AutomationTemplateAccess;
use Kadupul\Automation\Application\Query\AutomationTemplateAccessDenied;
use Kadupul\Automation\Domain\TreeRuleChange;
use Kadupul\Automation\Domain\TreeRuleRejected;
use Kadupul\Automation\Infrastructure\Persistence\DbalTreeRuleStore;
use Kadupul\IdentityAccess\Contract\AuditEvent;
use Kadupul\IdentityAccess\Contract\AuditTrail;
use Kadupul\Platform\Contract\LegacyConfiguration;
use PHPUnit\Framework\TestCase;

final class TreeRuleStoreTest extends TestCase
{
    private Connection $database;
    private DbalTreeRuleStore $store;
    private bool $revoked = false;
    /** @var list<bool> */
    private array $checkedInTransaction = [];
    /** @var list<AuditEvent> */
    private array $audit = [];

    protected function setUp(): void
    {
        $this->database = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        // The cacti.sql columns, with their defaults, and the rules cacti.sql seeds.
        foreach ([
            "CREATE TABLE automation_tree_rules (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL DEFAULT '', tree_id INT NOT NULL DEFAULT 0,
                tree_item_id INT NOT NULL DEFAULT 0, leaf_type INT NOT NULL DEFAULT 0, host_grouping_type INT NOT NULL DEFAULT 0, enabled TEXT DEFAULT '')",
            "INSERT INTO automation_tree_rules VALUES (1, 'New Device', 1, 0, 3, 1, 'on'), (2, 'New Graph', 1, 0, 2, 1, '')",
            'CREATE TABLE automation_tree_rule_items (id INTEGER PRIMARY KEY, rule_id INT, field TEXT)',
            'CREATE TABLE automation_match_rule_items (id INTEGER PRIMARY KEY, rule_id INT, rule_type INT, field TEXT)',
            'CREATE TABLE graph_tree (id INTEGER PRIMARY KEY, name TEXT)',
            'CREATE TABLE graph_tree_items (id INTEGER PRIMARY KEY, graph_tree_id INT, parent INT, position INT, title TEXT, local_graph_id INT DEFAULT 0, host_id INT DEFAULT 0)',
            "INSERT INTO graph_tree VALUES (1, 'Default Tree'), (2, 'Network <b>')",
            // Tree 2: Core > Routers, Edge; a device and a graph item are not headers.
            "INSERT INTO graph_tree_items (id, graph_tree_id, parent, position, title, local_graph_id, host_id) VALUES
                (10, 2, 0, 2, 'Edge', 0, 0), (11, 2, 0, 1, 'Core', 0, 0), (12, 2, 11, 1, 'Routers', 0, 0), (13, 2, 11, 2, '', 0, 5), (14, 2, 12, 1, '', 9, 0),
                (20, 1, 0, 1, 'Servers', 0, 0)",
        ] as $statement) {
            $this->database->executeStatement($statement);
        }
        $access = $this->createMock(AutomationTemplateAccess::class);
        $access->method('assertCurrent')->willReturnCallback(function (): void {
            $this->checkedInTransaction[] = $this->database->isTransactionActive();
            if ($this->revoked) {
                throw new AutomationTemplateAccessDenied(false);
            }
        });
        $audit = $this->createMock(AuditTrail::class);
        $audit->method('record')->willReturnCallback(function (AuditEvent $event): void {
            $this->audit[] = $event;
        });
        $configuration = $this->createMock(LegacyConfiguration::class);
        $configuration->method('values')->willReturn(['collector_id' => 1]);
        $this->store = new DbalTreeRuleStore($this->database, $access, $audit, $configuration);
    }

    public function testTheSeededRulesLoadAndSaveUnchanged(): void
    {
        foreach ([1, 2] as $id) {
            $rule = $this->store->find($id);
            self::assertNotNull($rule);
            $before = $this->database->fetchAllAssociative('SELECT * FROM automation_tree_rules');
            self::assertSame($id, $this->store->save(7, $id, new TreeRuleChange($rule->name, $rule->treeId, $rule->treeItemId, $rule->leafType, $rule->hostGroupingType, $rule->enabled), $rule->revision));
            self::assertEquals($before, $this->database->fetchAllAssociative('SELECT * FROM automation_tree_rules'));
        }
        $device = $this->store->find(1);
        self::assertSame(['New Device', 1, 0, 3, 1, true], [$device?->name, $device?->treeId, $device?->treeItemId, $device?->leafType, $device?->hostGroupingType, $device?->enabled]);
        self::assertNull($this->store->find(3));
        self::assertSame(['automation.tree_rule.edit', 'automation.tree_rule.edit'], array_map(static fn(AuditEvent $event): string => $event->action, $this->audit));
        self::assertSame([true, true], $this->checkedInTransaction);
    }

    public function testChoicesListEachTreesHeadersInTreeOrder(): void
    {
        $choices = $this->store->choices();
        self::assertSame([1 => 'Default Tree', 2 => 'Network <b>'], $choices['trees']);
        self::assertSame([
            20 => ['tree' => 1, 'title' => 'Servers', 'depth' => 1],
            11 => ['tree' => 2, 'title' => 'Core', 'depth' => 1],
            12 => ['tree' => 2, 'title' => 'Routers', 'depth' => 2],
            10 => ['tree' => 2, 'title' => 'Edge', 'depth' => 1],
        ], $choices['headers']);
        // A parent cycle in damaged data neither loops nor duplicates a header.
        $this->database->executeStatement('UPDATE graph_tree_items SET parent = 12 WHERE id = 11');
        $this->database->executeStatement("INSERT INTO graph_tree_items (id, graph_tree_id, parent, position, title) VALUES (15, 2, 15, 1, 'Self')");
        self::assertSame([20, 10], array_keys($this->store->choices()['headers']));
    }

    public function testRulesAreCreatedAndPlacedUnderAHeaderOfTheirTree(): void
    {
        $id = $this->store->save(7, null, new TreeRuleChange('  Routers <b>  ', 2, 12, 2, 2, false), null);
        self::assertSame(['Routers <b>', '2', '12', '2', '2', ''], array_map('strval', array_values((array) $this->database->fetchAssociative(
            'SELECT name, tree_id, tree_item_id, leaf_type, host_grouping_type, enabled FROM automation_tree_rules WHERE id = ?',
            [$id]
        ))));
        self::assertSame('automation.tree_rule.create', $this->audit[0]->action);
        $before = $this->database->fetchAllAssociative('SELECT * FROM automation_tree_rules');
        $rule = $this->store->find(1);
        // Another tree's header, a device item, a graph item, a missing item and a missing tree are refused.
        foreach ([[1, 12, 'tree_item_id'], [2, 13, 'tree_item_id'], [2, 14, 'tree_item_id'], [2, 99, 'tree_item_id'], [3, 0, 'tree_id']] as [$tree, $item, $field]) {
            foreach ([[1, $rule?->revision], [null, null]] as [$target, $revision]) {
                try {
                    $this->store->save(7, $target, new TreeRuleChange('Rule', $tree, $item, 3, 1, true), $revision);
                    self::fail('A misplaced tree rule was accepted.');
                } catch (TreeRuleRejected $error) {
                    self::assertSame($field, $error->field);
                }
            }
        }
        self::assertEquals($before, $this->database->fetchAllAssociative('SELECT * FROM automation_tree_rules'));
    }

    public function testAChangeToDeviceLeavesRemovesOnlyThisRulesGraphCriteriaWhenConfirmed(): void
    {
        $this->database->executeStatement("INSERT INTO automation_tree_rule_items VALUES (1, 2, 'gtg.title_cache'), (2, 2, 'gt.name'), (3, 2, 'h.description'), (4, 1, 'gt.name')");
        // Match item 8 belongs to graph rule 2, which shares the ID; legacy removed it too.
        $this->database->executeStatement("INSERT INTO automation_match_rule_items VALUES (5, 2, 3, 'gt.name'), (6, 2, 3, 'h.hostname'), (7, 1, 3, 'gtg.title'), (8, 2, 1, 'gt.name')");
        $rule = $this->store->find(2);
        self::assertNotNull($rule);
        try {
            $this->store->save(7, 2, new TreeRuleChange('New Graph', 1, 0, 3, 1, false), $rule->revision);
            self::fail('Criteria were removed without confirmation.');
        } catch (TreeRuleRejected $error) {
            self::assertSame(['remove_graph_criteria', ['%count%' => '3']], [$error->field, $error->parameters]);
        }
        self::assertSame(2, (int) $this->database->fetchOne('SELECT leaf_type FROM automation_tree_rules WHERE id = 2'));
        self::assertSame(8, (int) $this->database->fetchOne('SELECT COUNT(*) FROM automation_tree_rule_items') + (int) $this->database->fetchOne('SELECT COUNT(*) FROM automation_match_rule_items'));

        $this->store->save(7, 2, new TreeRuleChange('New Graph', 1, 0, 3, 1, false, true), $rule->revision);
        self::assertSame(3, (int) $this->database->fetchOne('SELECT leaf_type FROM automation_tree_rules WHERE id = 2'));
        self::assertSame([3, 4], array_map('intval', $this->database->fetchFirstColumn('SELECT id FROM automation_tree_rule_items ORDER BY id')));
        self::assertSame([6, 7, 8], array_map('intval', $this->database->fetchFirstColumn('SELECT id FROM automation_match_rule_items ORDER BY id')));

        // Rule 1 already has Device leaves, so saving it neither asks nor removes anything.
        $device = $this->store->find(1);
        $this->store->save(7, 1, new TreeRuleChange('New Device', 1, 0, 3, 1, true), $device?->revision);
        self::assertSame([3, 4], array_map('intval', $this->database->fetchFirstColumn('SELECT id FROM automation_tree_rule_items ORDER BY id')));
    }

    public function testStaleMissingAndRevokedSavesWriteNothing(): void
    {
        $rule = $this->store->find(1);
        self::assertNotNull($rule);
        $change = new TreeRuleChange('Changed', 1, 0, 3, 1, true);
        $this->database->update('automation_tree_rules', ['host_grouping_type' => 2], ['id' => 1]);
        foreach ([$rule->revision, null, ''] as $stale) {
            try {
                $this->store->save(7, 1, $change, $stale);
                self::fail('A stale tree rule edit was accepted.');
            } catch (\InvalidArgumentException $error) {
                self::assertStringContainsString('changed since', $error->getMessage());
            }
        }
        try {
            $this->store->save(7, 9, $change, $rule->revision);
            self::fail('A missing tree rule was saved.');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('Tree rule not found.', $error->getMessage());
        }
        $current = $this->store->find(1);
        $this->revoked = true;
        try {
            $this->store->save(7, 1, $change, $current?->revision);
            self::fail('A revoked administrator saved a tree rule.');
        } catch (AutomationTemplateAccessDenied) {
        }
        self::assertSame('New Device', $this->database->fetchOne('SELECT name FROM automation_tree_rules WHERE id = 1'));
        self::assertSame(2, (int) $this->database->fetchOne('SELECT COUNT(*) FROM automation_tree_rules'));
        self::assertSame(['failed'], array_values(array_unique(array_map(static fn(AuditEvent $event): string => $event->outcome, array_slice($this->audit, 0, 4)))));
    }

    public function testChangesAreValidatedBeforeTheStoreIsCalled(): void
    {
        foreach ([
            ['', 1, 0, 3, 1, 'name'], ["Two\nlines", 1, 0, 3, 1, 'name'], ["Bad\xff", 1, 0, 3, 1, 'name'], [str_repeat('é', 256), 1, 0, 3, 1, 'name'],
            ['Rule', 0, 0, 3, 1, 'tree_id'], ['Rule', 65536, 0, 3, 1, 'tree_id'], ['Rule', 1, -1, 3, 1, 'tree_item_id'], ['Rule', 1, 16777216, 3, 1, 'tree_item_id'],
            ['Rule', 1, 0, 1, 1, 'leaf_type'], ['Rule', 1, 0, 0, 1, 'leaf_type'], ['Rule', 1, 0, 3, 0, 'host_grouping_type'], ['Rule', 1, 0, 3, 3, 'host_grouping_type'],
        ] as [$name, $tree, $item, $leaf, $grouping, $field]) {
            try {
                new TreeRuleChange($name, $tree, $item, $leaf, $grouping, true);
                self::fail('An invalid tree rule was accepted: ' . json_encode([$name, $tree, $item, $leaf, $grouping], JSON_INVALID_UTF8_SUBSTITUTE));
            } catch (TreeRuleRejected $error) {
                self::assertSame($field, $error->field);
            }
        }
        self::assertSame(255, mb_strlen((new TreeRuleChange(str_repeat('é', 255), 1, 0, 2, 2, false))->name));
    }
}
