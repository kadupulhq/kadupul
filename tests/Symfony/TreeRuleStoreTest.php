<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Logging\Middleware;
use Kadupul\Automation\Application\Port\AutomationTemplateAccess;
use Kadupul\Automation\Application\Query\AutomationTemplateAccessDenied;
use Kadupul\Automation\Domain\TreeRule;
use Kadupul\Automation\Domain\TreeRuleChange;
use Kadupul\Automation\Domain\TreeRuleRejected;
use Kadupul\Automation\Infrastructure\Persistence\DbalTreeRuleStore;
use Kadupul\IdentityAccess\Contract\AuditEvent;
use Kadupul\IdentityAccess\Contract\AuditTrail;
use Kadupul\Platform\Contract\LegacyConfiguration;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

final class TreeRuleStoreTest extends TestCase
{
    private Connection $database;
    /** @var object{sql: list<string>} every statement the store's connection ran */
    private object $statements;
    private DbalTreeRuleStore $store;
    private bool $revoked = false;
    /** @var list<bool> */
    private array $checkedInTransaction = [];
    /** @var list<AuditEvent> */
    private array $audit = [];

    protected function setUp(): void
    {
        $statements = new class extends AbstractLogger {
            /** @var list<string> */
            public array $sql = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                if (is_string($context['sql'] ?? null)) {
                    $this->sql[] = $context['sql'];
                }
            }
        };
        $this->statements = $statements;
        $this->database = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true, 'wrapperClass' => TreeRuleIdentityLessConnection::class], (new Configuration())->setMiddlewares([new Middleware($statements)]));
        // The cacti.sql columns, with their defaults, and the rules cacti.sql seeds.
        foreach ([
            "CREATE TABLE automation_tree_rules (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL DEFAULT '', tree_id INT NOT NULL DEFAULT 0,
                tree_item_id INT NOT NULL DEFAULT 0, leaf_type INT NOT NULL DEFAULT 0, host_grouping_type INT NOT NULL DEFAULT 0, enabled TEXT DEFAULT '')",
            "INSERT INTO automation_tree_rules VALUES (1, 'New Device', 1, 0, 3, 1, 'on'), (2, 'New Graph', 1, 0, 2, 1, '')",
            "CREATE TABLE automation_tree_rule_items (id INTEGER PRIMARY KEY AUTOINCREMENT, rule_id INT NOT NULL DEFAULT 0, sequence INT NOT NULL DEFAULT 0,
                field TEXT NOT NULL DEFAULT '', sort_type INT NOT NULL DEFAULT 0, propagate_changes TEXT DEFAULT '', search_pattern TEXT NOT NULL DEFAULT '',
                replace_pattern TEXT NOT NULL DEFAULT '')",
            "CREATE TABLE automation_match_rule_items (id INTEGER PRIMARY KEY AUTOINCREMENT, rule_id INT NOT NULL DEFAULT 0, rule_type INT NOT NULL DEFAULT 0,
                sequence INT NOT NULL DEFAULT 0, operation INT NOT NULL DEFAULT 0, field TEXT NOT NULL DEFAULT '', operator INT NOT NULL DEFAULT 0, pattern TEXT NOT NULL DEFAULT '')",
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
        $this->database->executeStatement("INSERT INTO automation_tree_rule_items (id, rule_id, field) VALUES (1, 2, 'gtg.title_cache'), (2, 2, 'gt.name'), (3, 2, 'h.description'), (4, 1, 'gt.name')");
        // Match item 8 belongs to graph rule 2, which shares the ID; legacy removed it too.
        $this->database->executeStatement("INSERT INTO automation_match_rule_items (id, rule_id, rule_type, field) VALUES (5, 2, 3, 'gt.name'), (6, 2, 3, 'h.hostname'), (7, 1, 3, 'gtg.title'), (8, 2, 1, 'gt.name')");
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

    public function testBulkDeleteRemovesRulesWithTheirCriteriaAndKeepsGraphRuleItems(): void
    {
        [$devices, $graphs, $kept] = $this->rules(['Devices', 'Graphs', 'Kept']);
        // #1533: a graph rule with the same ID keeps its Device Selection Criteria; they are rule_type 1.
        $this->database->insert('automation_match_rule_items', ['rule_id' => $devices, 'rule_type' => 1, 'sequence' => 1, 'field' => 'h.description', 'pattern' => 'graph']);
        $this->database->insert('automation_match_rule_items', ['rule_id' => $graphs, 'rule_type' => 1, 'sequence' => 1, 'field' => 'ht.name', 'pattern' => 'Linux']);
        $revisions = $this->revisions([$devices, $graphs]);
        self::assertSame([$devices, $graphs], array_keys($this->store->findRules([$graphs, 999, $devices])));
        self::assertSame([], $this->store->findRules([]));

        $before = count($this->statements->sql);
        $this->store->deleteRules(7, [$devices, $graphs], $revisions);
        $two = count($this->statements->sql) - $before;
        self::assertSame(['1', '2', (string) $kept], $this->column('SELECT id FROM automation_tree_rules ORDER BY id'));
        self::assertSame([(string) $kept], $this->column('SELECT DISTINCT rule_id FROM automation_tree_rule_items ORDER BY rule_id'));
        self::assertSame([$devices . ':1', $graphs . ':1', $kept . ':3'], $this->column("SELECT DISTINCT rule_id || ':' || rule_type FROM automation_match_rule_items ORDER BY rule_id, rule_type"));
        self::assertSame([['h.description', 'graph'], ['ht.name', 'Linux']], array_map(
            static fn(array $row): array => [(string) $row['field'], (string) $row['pattern']],
            $this->database->fetchAllAssociative('SELECT field, pattern FROM automation_match_rule_items WHERE rule_type = 1 ORDER BY rule_id')
        ));
        $event = $this->audit[array_key_last($this->audit)];
        self::assertSame(['automation.tree_rule.delete', AuditEvent::SUCCEEDED], [$event->action, $event->outcome]);
        self::assertTrue($this->checkedInTransaction[array_key_last($this->checkedInTransaction)]);

        // Fifty rules cost the same statements as two: nothing runs per selected row.
        $many = $this->rules(array_map(static fn(int $number): string => 'Rule ' . $number, range(1, 50)));
        $revisions = $this->revisions($many);
        $before = count($this->statements->sql);
        $this->store->deleteRules(7, $many, $revisions);
        self::assertSame($two, count($this->statements->sql) - $before);
        self::assertSame(3, (int) $this->database->fetchOne('SELECT COUNT(*) FROM automation_tree_rules'));
    }

    public function testBulkActionsRefuseStaleMissingAndMalformedSelectionsWithoutWriting(): void
    {
        [$devices, $graphs] = $this->rules(['Devices', 'Graphs']);
        $revisions = $this->revisions([$devices, $graphs]);
        $checks = count($this->checkedInTransaction);
        $writes = [
            'delete' => fn(array $ids, array $given) => $this->store->deleteRules(7, $ids, $given),
            'duplicate' => fn(array $ids, array $given) => $this->store->duplicateRules(7, $ids, $given, TreeRule::NAME_FORMAT),
            'enable' => fn(array $ids, array $given) => $this->store->setEnabled(7, $ids, $given, true),
        ];
        $before = $this->state();
        foreach ($writes as $operation => $write) {
            foreach ([
                'empty' => [[], []],
                'unsorted' => [[$graphs, $devices], $revisions],
                'repeated' => [[$devices, $devices], [$devices => $revisions[$devices]]],
                'zero' => [[0], [0 => 'x']],
                'beyond the column' => [[16777216], [16777216 => 'x']],
                'text id' => [['1'], [1 => 'x']],
                'too many' => [range(1, 101), array_fill(1, 101, 'x')],
                'missing revision' => [[$devices, $graphs], [$devices => $revisions[$devices]]],
                'non-text revision' => [[$devices], [$devices => 1]],
            ] as $case => [$ids, $given]) {
                try {
                    $write($ids, $given);
                    self::fail('Accepted selection: ' . $operation . ' ' . $case);
                } catch (\InvalidArgumentException) {
                }
            }
        }
        // A malformed selection is refused before a transaction or an actor check starts.
        self::assertCount($checks, $this->checkedInTransaction);

        $this->database->update('automation_tree_rules', ['tree_item_id' => 20], ['id' => $graphs]);
        $moved = $this->state();
        foreach ($writes as $operation => $write) {
            foreach ([[[$devices, $graphs], $revisions, 'changed since'], [[$devices, 999], [$devices => $revisions[$devices], 999 => 'x'], 'no longer exist']] as [$ids, $given, $message]) {
                try {
                    $write($ids, $given);
                    self::fail('Accepted a selection that should report: ' . $operation . ' ' . $message);
                } catch (\InvalidArgumentException $error) {
                    self::assertStringContainsString($message, $error->getMessage());
                }
            }
        }
        self::assertSame($moved, $this->state());
        self::assertNotSame($before, $moved);

        // The criteria go first; when the rule rows cannot be removed, they come back.
        $this->database->executeStatement("CREATE TRIGGER refuse_rule_delete BEFORE DELETE ON automation_tree_rules BEGIN SELECT RAISE(ABORT, 'refused'); END");
        try {
            $this->store->deleteRules(7, [$devices], [$devices => $revisions[$devices]]);
            self::fail('A failed deletion reported success.');
        } catch (\InvalidArgumentException $error) {
            self::fail('A database failure was reported as a correctable refusal: ' . $error->getMessage());
        } catch (\Throwable) {
        }
        self::assertSame($moved, $this->state());
        self::assertSame(AuditEvent::FAILED, $this->audit[array_key_last($this->audit)]->outcome);

        $this->revoked = true;
        foreach (['delete' => fn() => $this->store->deleteRules(7, [$devices], [$devices => $revisions[$devices]]),
            'disable' => fn() => $this->store->setEnabled(7, [$devices], [$devices => $revisions[$devices]], false)] as $operation => $write) {
            try {
                $write();
                self::fail('A revoked actor could ' . $operation . '.');
            } catch (AutomationTemplateAccessDenied) {
            }
            self::assertSame(AuditEvent::DENIED, $this->audit[array_key_last($this->audit)]->outcome);
        }
        self::assertSame($moved, $this->state());
    }

    public function testUnconfirmedDeletionAndDuplicationRollBackAndAreAudited(): void
    {
        [$devices, $graphs] = $this->rules(['Devices', 'Graphs']);
        $revisions = $this->revisions([$devices, $graphs]);
        $before = $this->state();

        // A rule row that survives its DELETE leaves the count short of the selection.
        $this->database->executeStatement('CREATE TRIGGER keep_devices BEFORE DELETE ON automation_tree_rules WHEN OLD.id = ' . $devices . ' BEGIN SELECT RAISE(IGNORE); END');
        try {
            $this->store->deleteRules(7, [$devices, $graphs], $revisions);
            self::fail('An unconfirmed deletion was reported as done.');
        } catch (\RuntimeException $error) {
            self::assertSame('Tree Rule deletion was not confirmed.', $error->getMessage());
        }
        self::assertSame($before, $this->state());
        self::assertSame(AuditEvent::FAILED, $this->audit[array_key_last($this->audit)]->outcome);

        // MySQL answers 0 when an insert generated no ID; that is not a copy.
        $this->database->executeStatement('DROP TRIGGER keep_devices');
        self::assertInstanceOf(TreeRuleIdentityLessConnection::class, $this->database);
        $this->database->identityLess = true;
        try {
            $this->store->duplicateRules(7, [$devices], [$devices => $revisions[$devices]], TreeRule::NAME_FORMAT);
            self::fail('An unconfirmed duplication was reported as done.');
        } catch (\RuntimeException $error) {
            self::assertSame('Tree Rule duplication was not confirmed.', $error->getMessage());
        }
        self::assertSame($before, $this->state());
        self::assertSame(AuditEvent::FAILED, $this->audit[array_key_last($this->audit)]->outcome);
    }

    public function testDuplicateCopiesBothKindsOfCriteriaAndStartsDisabled(): void
    {
        [$devices, $empty] = $this->rules(['Devices', 'Empty']);
        $this->database->executeStatement('DELETE FROM automation_tree_rule_items WHERE rule_id = ?', [$empty]);
        $this->database->executeStatement('DELETE FROM automation_match_rule_items WHERE rule_id = ?', [$empty]);
        // The graph rule of the same ID owns this item; it is not a criterion of the tree rule.
        $this->database->insert('automation_match_rule_items', ['rule_id' => $devices, 'rule_type' => 1, 'sequence' => 1, 'field' => 'h.description', 'pattern' => 'graph']);
        self::assertSame('on', $this->database->fetchOne('SELECT enabled FROM automation_tree_rules WHERE id = ?', [$devices]));
        $matches = $this->items('automation_match_rule_items', $devices, 3);
        $actions = $this->items('automation_tree_rule_items', $devices);

        $revisions = $this->revisions([$devices, $empty]);
        $before = count($this->statements->sql);
        $this->store->duplicateRules(7, [$devices, $empty], $revisions, TreeRule::NAME_FORMAT);
        $two = count($this->statements->sql) - $before;
        $copies = $this->database->fetchAllAssociative('SELECT id, name, tree_id, tree_item_id, leaf_type, host_grouping_type, enabled FROM automation_tree_rules WHERE id > ? ORDER BY id', [$empty]);
        self::assertSame([['Devices (1)', '2', '12', '3', '2', ''], ['Empty (1)', '2', '12', '3', '2', '']], array_map(
            static fn(array $row): array => array_map('strval', [$row['name'], $row['tree_id'], $row['tree_item_id'], $row['leaf_type'], $row['host_grouping_type'], $row['enabled']]),
            $copies
        ));
        $copy = (int) $copies[0]['id'];
        self::assertSame($matches, $this->items('automation_match_rule_items', $copy, 3));
        self::assertSame([], $this->items('automation_match_rule_items', $copy, 1));
        self::assertSame($actions, $this->items('automation_tree_rule_items', $copy));
        self::assertSame([], $this->items('automation_tree_rule_items', (int) $copies[1]['id']));
        // The originals are untouched.
        self::assertSame($matches, $this->items('automation_match_rule_items', $devices, 3));
        self::assertSame('on', $this->database->fetchOne('SELECT enabled FROM automation_tree_rules WHERE id = ?', [$devices]));
        $event = $this->audit[array_key_last($this->audit)];
        self::assertSame(['automation.tree_rule.duplicate', AuditEvent::SUCCEEDED], [$event->action, $event->outcome]);

        // One insert per copied rule, and nothing else grows with the selection.
        $many = $this->rules(array_map(static fn(int $number): string => 'Rule ' . $number, range(1, 40)));
        $revisions = $this->revisions($many);
        $before = count($this->statements->sql);
        $this->store->duplicateRules(7, $many, $revisions, '<rule_name> copy');
        // Lock, one insert per rule and one statement for each kind of criteria.
        self::assertSame(5, $two);
        self::assertSame(43, count($this->statements->sql) - $before);
        self::assertSame(40, (int) $this->database->fetchOne("SELECT COUNT(*) FROM automation_tree_rules WHERE name LIKE 'Rule % copy'"));
        self::assertSame(80, (int) $this->database->fetchOne("SELECT COUNT(*) FROM automation_match_rule_items i INNER JOIN automation_tree_rules r ON r.id = i.rule_id WHERE r.name LIKE 'Rule % copy'"));
        self::assertSame(80, (int) $this->database->fetchOne("SELECT COUNT(*) FROM automation_tree_rule_items i INNER JOIN automation_tree_rules r ON r.id = i.rule_id WHERE r.name LIKE 'Rule % copy'"));
    }

    public function testDuplicateNamesFollowTheLegacyFormatAndMustFitTheColumn(): void
    {
        self::assertSame('Devices (1)', TreeRule::copyName('<rule_name> (1)', 'Devices'));
        self::assertSame('Devices/Devices', TreeRule::copyName('<rule_name>/<rule_name>', 'Devices'));
        // Without the token every copy gets the same name, as on the legacy page; other tokens are literal text.
        self::assertSame('Fixed <name>', TreeRule::copyName('Fixed <name>', 'Devices'));
        self::assertSame(str_repeat('é', 251) . ' (1)', TreeRule::copyName('<rule_name> (1)', str_repeat('é', 251)));
        foreach ([['<rule_name> (1)', str_repeat('n', 252)], ['', 'Devices'], ['<rule_name>', ''], [str_repeat('x', 256), 'Devices'], ["<rule_name>\n", 'Devices'], ["<rule_name>\0", 'Devices'], ["\xff<rule_name>", 'Devices']] as [$format, $name]) {
            try {
                TreeRule::copyName($format, $name);
                self::fail('Accepted the name format ' . json_encode($format, JSON_INVALID_UTF8_SUBSTITUTE));
            } catch (\InvalidArgumentException) {
            }
        }

        [$short, $long] = $this->rules(['Short', str_repeat('n', 252)]);
        $revisions = $this->revisions([$short, $long]);
        $before = $this->state();
        foreach ([
            'one name too long for the column' => fn() => $this->store->duplicateRules(7, [$short, $long], $revisions, '<rule_name> (1)'),
            'empty format' => fn() => $this->store->duplicateRules(7, [$short], [$short => $revisions[$short]], ''),
            'stale' => fn() => $this->store->duplicateRules(7, [$short], [$short => 'stale'], '<rule_name> (1)'),
        ] as $case => $write) {
            try {
                $write();
                self::fail('Duplicated with ' . $case);
            } catch (\InvalidArgumentException) {
            }
        }
        // A refusal for one rule leaves no copy of another.
        self::assertSame($before, $this->state());
    }

    public function testEnableAndDisableWriteTheLegacyValuesInOneStatement(): void
    {
        [$devices, $graphs] = $this->rules(['Devices', 'Graphs']);
        $this->database->update('automation_tree_rules', ['enabled' => ''], ['id' => $graphs]);
        $items = $this->state();

        $revisions = $this->revisions([$devices, $graphs]);
        $before = count($this->statements->sql);
        $this->store->setEnabled(7, [$devices, $graphs], $revisions, true);
        self::assertSame(2, count($this->statements->sql) - $before);
        self::assertSame(['on', 'on'], $this->column('SELECT enabled FROM automation_tree_rules WHERE id IN (?, ?) ORDER BY id', [$devices, $graphs]));
        self::assertSame(['automation.tree_rule.enable', AuditEvent::SUCCEEDED], [$this->audit[array_key_last($this->audit)]->action, $this->audit[array_key_last($this->audit)]->outcome]);
        // A rule that already had the value is not an error.
        $this->store->setEnabled(7, [$devices], $this->revisions([$devices]), true);

        $this->store->setEnabled(7, [$graphs], $this->revisions([$graphs]), false);
        self::assertSame(['on', ''], $this->column('SELECT enabled FROM automation_tree_rules WHERE id IN (?, ?) ORDER BY id', [$devices, $graphs]));
        self::assertSame('automation.tree_rule.disable', $this->audit[array_key_last($this->audit)]->action);
        // Only the enabled column changes; the criteria and the other settings stay.
        self::assertSame(array_diff_key($items, ['automation_tree_rules' => true]), array_diff_key($this->state(), ['automation_tree_rules' => true]));
        self::assertSame(['Devices', 'Graphs'], $this->column('SELECT name FROM automation_tree_rules WHERE id IN (?, ?) ORDER BY id', [$devices, $graphs]));
    }

    /**
     * Each rule is a Device rule under Routers of tree 2 with two Object
     * Selection Criteria and two Tree Creation Criteria.
     *
     * @param list<string> $names
     * @return list<int>
     */
    private function rules(array $names): array
    {
        $ids = [];
        foreach ($names as $name) {
            $this->database->insert('automation_tree_rules', ['name' => $name, 'tree_id' => 2, 'tree_item_id' => 12, 'leaf_type' => 3, 'host_grouping_type' => 2, 'enabled' => 'on']);
            $ids[] = $id = (int) $this->database->lastInsertId();
            $this->database->insert('automation_match_rule_items', ['rule_id' => $id, 'rule_type' => 3, 'sequence' => 1, 'operation' => 0, 'field' => 'h.description', 'operator' => 1, 'pattern' => $name]);
            $this->database->insert('automation_match_rule_items', ['rule_id' => $id, 'rule_type' => 3, 'sequence' => 2, 'operation' => 1, 'field' => 'ht.name', 'operator' => 7, 'pattern' => 'Linux']);
            $this->database->insert('automation_tree_rule_items', ['rule_id' => $id, 'sequence' => 1, 'field' => 'ht.name', 'sort_type' => 1, 'propagate_changes' => '',
                'search_pattern' => '^(.*)\s*Linux\s*(.*)$', 'replace_pattern' => '${1}\n${2}']);
            $this->database->insert('automation_tree_rule_items', ['rule_id' => $id, 'sequence' => 2, 'field' => '0', 'sort_type' => 2, 'propagate_changes' => 'on',
                'search_pattern' => $name, 'replace_pattern' => '']);
        }
        return $ids;
    }

    /**
     * @param list<int> $ids
     * @return array<int, string>
     */
    private function revisions(array $ids): array
    {
        return array_map(static fn(TreeRule $rule): string => $rule->revision, $this->store->findRules($ids));
    }

    /** @return list<array<string, string>> a rule's criteria without their ID and rule */
    private function items(string $table, int $rule, ?int $type = null): array
    {
        return array_map(
            static fn(array $row): array => array_map(static fn(mixed $value): string => (string) $value, array_diff_key($row, ['id' => true, 'rule_id' => true])),
            $this->database->fetchAllAssociative('SELECT * FROM ' . $table . ' WHERE rule_id = ?' . ($type === null ? '' : ' AND rule_type = ' . $type) . ' ORDER BY sequence, id', [$rule]),
        );
    }

    /**
     * @param list<mixed> $parameters
     * @return list<string>
     */
    private function column(string $sql, array $parameters = []): array
    {
        return array_map(static fn(mixed $value): string => (string) $value, $this->database->fetchFirstColumn($sql, $parameters));
    }

    /** @return array<string, list<array<string, mixed>>> every row of the three tables */
    private function state(): array
    {
        $state = [];
        foreach (['automation_tree_rules', 'automation_tree_rule_items', 'automation_match_rule_items'] as $table) {
            $state[$table] = $this->database->fetchAllAssociative('SELECT * FROM ' . $table . ' ORDER BY id');
        }
        return $state;
    }
}

/** A connection whose last insert ID can be made to read 0, as MySQL reports an insert that generated none. */
final class TreeRuleIdentityLessConnection extends Connection
{
    public bool $identityLess = false;

    public function lastInsertId(): int|string
    {
        return $this->identityLess ? 0 : parent::lastInsertId();
    }
}
