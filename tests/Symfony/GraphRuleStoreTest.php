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
use Kadupul\Automation\Domain\GraphRule;
use Kadupul\Automation\Domain\GraphRuleChange;
use Kadupul\Automation\Domain\GraphRuleRejected;
use Kadupul\Automation\Infrastructure\Persistence\DbalGraphRuleStore;
use Kadupul\IdentityAccess\Contract\AuditEvent;
use Kadupul\IdentityAccess\Contract\AuditTrail;
use Kadupul\Platform\Contract\LegacyConfiguration;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

final class GraphRuleStoreTest extends TestCase
{
    private Connection $database;
    /** @var object{sql: list<string>} every statement the store's connection ran */
    private object $statements;
    private DbalGraphRuleStore $store;
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
        $this->database = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true, 'wrapperClass' => GraphRuleIdentityLessConnection::class], (new Configuration())->setMiddlewares([new Middleware($statements)]));
        // The cacti.sql columns, with their defaults, and the rule cacti.sql seeds.
        foreach ([
            "CREATE TABLE automation_graph_rules (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL DEFAULT '', snmp_query_id INT NOT NULL DEFAULT 0,
                graph_type_id INT NOT NULL DEFAULT 0, enabled TEXT DEFAULT '')",
            "INSERT INTO automation_graph_rules VALUES (1, 'Traffic 64 bit Server', 1, 12, 'on')",
            "CREATE TABLE automation_graph_rule_items (id INTEGER PRIMARY KEY AUTOINCREMENT, rule_id INT NOT NULL DEFAULT 0, sequence INT NOT NULL DEFAULT 0,
                operation INT NOT NULL DEFAULT 0, field TEXT NOT NULL DEFAULT '', operator INT NOT NULL DEFAULT 0, pattern TEXT NOT NULL DEFAULT '')",
            "INSERT INTO automation_graph_rule_items (id, rule_id, field) VALUES (5, 1, 'ifOperStatus')",
            "CREATE TABLE automation_match_rule_items (id INTEGER PRIMARY KEY AUTOINCREMENT, rule_id INT NOT NULL DEFAULT 0, rule_type INT NOT NULL DEFAULT 0,
                sequence INT NOT NULL DEFAULT 0, operation INT NOT NULL DEFAULT 0, field TEXT NOT NULL DEFAULT '', operator INT NOT NULL DEFAULT 0, pattern TEXT NOT NULL DEFAULT '')",
            'CREATE TABLE snmp_query (id INTEGER PRIMARY KEY, name TEXT)',
            'CREATE TABLE snmp_query_graph (id INTEGER PRIMARY KEY, snmp_query_id INT, name TEXT)',
            "INSERT INTO snmp_query VALUES (1, 'SNMP - Interface Statistics'), (3, 'Host MIB - Disks')",
            "INSERT INTO snmp_query_graph VALUES (12, 1, 'In/Out Bits (64-bit)'), (2, 1, 'In/Out Bits'), (17, 3, 'Used Space')",
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
        $this->store = new DbalGraphRuleStore($this->database, $access, $audit, $configuration);
    }

    public function testTheSeededRuleLoadsAndSavesUnchanged(): void
    {
        $rule = $this->store->find(1);
        self::assertNotNull($rule);
        self::assertSame(['Traffic 64 bit Server', 1, 12, true], [$rule->name, $rule->snmpQueryId, $rule->graphTypeId, $rule->enabled]);
        $before = $this->database->fetchAllAssociative('SELECT * FROM automation_graph_rules');
        self::assertSame(1, $this->store->save(7, 1, new GraphRuleChange($rule->name, $rule->snmpQueryId, $rule->graphTypeId, $rule->enabled), $rule->revision));
        self::assertEquals($before, $this->database->fetchAllAssociative('SELECT * FROM automation_graph_rules'));
        self::assertNull($this->store->find(2));
        self::assertSame('automation.graph_rule.edit', $this->audit[0]->action);
        self::assertSame([true], $this->checkedInTransaction);
        // Ordered by name, as the legacy drop-down was.
        self::assertSame([3 => 'Host MIB - Disks', 1 => 'SNMP - Interface Statistics'], $this->store->choices()['queries']);
        self::assertSame([2, 12, 17], array_keys($this->store->choices()['graphTypes']));
    }

    public function testRulesAreCreatedAndEditedInTheLegacyStorageFormats(): void
    {
        $id = $this->store->save(7, null, new GraphRuleChange('  Disks <b>  ', 3, 17, false), null);
        self::assertSame(['Disks <b>', '3', '17', ''], array_map('strval', array_values((array) $this->database->fetchAssociative(
            'SELECT name, snmp_query_id, graph_type_id, enabled FROM automation_graph_rules WHERE id = ?',
            [$id]
        ))));
        self::assertSame('automation.graph_rule.create', $this->audit[0]->action);
        $rule = $this->store->find(1);
        $this->store->save(7, 1, new GraphRuleChange('Traffic', 1, 2, false), $rule?->revision);
        self::assertSame(['Traffic', '1', '2', ''], array_map('strval', array_values((array) $this->database->fetchAssociative(
            'SELECT name, snmp_query_id, graph_type_id, enabled FROM automation_graph_rules WHERE id = 1'
        ))));
        // Criteria items are edited on the legacy page and stay untouched.
        self::assertSame('ifOperStatus', $this->database->fetchOne('SELECT field FROM automation_graph_rule_items WHERE id = 5'));
    }

    public function testAGraphTypeMustBelongToAnExistingDataQuery(): void
    {
        $rule = $this->store->find(1);
        $before = $this->database->fetchAllAssociative('SELECT * FROM automation_graph_rules');
        foreach ([[1, 17, 'graph_type_id'], [1, 99, 'graph_type_id'], [2, 12, 'snmp_query_id'], [3, 12, 'graph_type_id']] as [$query, $type, $field]) {
            foreach ([[1, $rule?->revision], [null, null]] as [$id, $revision]) {
                try {
                    $this->store->save(7, $id, new GraphRuleChange('Rule', $query, $type, true), $revision);
                    self::fail('A mismatched Data Query and Graph Type were accepted.');
                } catch (GraphRuleRejected $error) {
                    self::assertSame($field, $error->field);
                }
            }
        }
        self::assertEquals($before, $this->database->fetchAllAssociative('SELECT * FROM automation_graph_rules'));
        // Each refusal is audited as a failed attempt.
        self::assertCount(8, $this->audit);
        self::assertSame(['failed'], array_values(array_unique(array_map(static fn(AuditEvent $event): string => $event->outcome, $this->audit))));
    }

    public function testStaleMissingAndRevokedSavesWriteNothing(): void
    {
        $rule = $this->store->find(1);
        self::assertNotNull($rule);
        $change = new GraphRuleChange('Changed', 1, 12, true);
        $this->database->update('automation_graph_rules', ['enabled' => ''], ['id' => 1]);
        foreach ([$rule->revision, null, ''] as $stale) {
            try {
                $this->store->save(7, 1, $change, $stale);
                self::fail('A stale graph rule edit was accepted.');
            } catch (\InvalidArgumentException $error) {
                self::assertStringContainsString('changed since', $error->getMessage());
            }
        }
        try {
            $this->store->save(7, 9, $change, $rule->revision);
            self::fail('A missing graph rule was saved.');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('Graph rule not found.', $error->getMessage());
        }
        $current = $this->store->find(1);
        $this->revoked = true;
        try {
            $this->store->save(7, 1, $change, $current?->revision);
            self::fail('A revoked administrator saved a graph rule.');
        } catch (AutomationTemplateAccessDenied) {
        }
        self::assertSame('Traffic 64 bit Server', $this->database->fetchOne('SELECT name FROM automation_graph_rules WHERE id = 1'));
        self::assertSame(1, (int) $this->database->fetchOne('SELECT COUNT(*) FROM automation_graph_rules'));
    }

    public function testChangesAreValidatedBeforeTheStoreIsCalled(): void
    {
        foreach ([
            ['', 1, 12, 'name'], ['   ', 1, 12, 'name'], ["Two\nlines", 1, 12, 'name'], ["Bad\xff", 1, 12, 'name'], [str_repeat('é', 256), 1, 12, 'name'],
            ['Rule', 0, 12, 'snmp_query_id'], ['Rule', 65536, 12, 'snmp_query_id'], ['Rule', 1, 0, 'graph_type_id'], ['Rule', 1, -1, 'graph_type_id'],
        ] as [$name, $query, $type, $field]) {
            try {
                new GraphRuleChange($name, $query, $type, true);
                self::fail('An invalid graph rule was accepted: ' . json_encode([$name, $query, $type], JSON_INVALID_UTF8_SUBSTITUTE));
            } catch (GraphRuleRejected $error) {
                self::assertSame($field, $error->field);
            }
        }
        self::assertSame(255, mb_strlen((new GraphRuleChange(str_repeat('é', 255), 1, 12, true))->name));
    }

    public function testBulkDeleteRemovesRulesWithTheirCriteriaAndKeepsTreeRuleItems(): void
    {
        [$traffic, $disks, $kept] = $this->rules(['Traffic', 'Disks', 'Kept']);
        // A tree rule with the same ID keeps its match items: they are rule_type 3.
        $this->database->insert('automation_match_rule_items', ['rule_id' => $traffic, 'rule_type' => 3, 'sequence' => 1, 'field' => 'h.description', 'pattern' => 'tree']);
        $revisions = $this->revisions([$traffic, $disks]);
        self::assertSame([$traffic, $disks], array_keys($this->store->findRules([$disks, 999, $traffic])));
        self::assertSame([], $this->store->findRules([]));

        $before = count($this->statements->sql);
        $this->store->deleteRules(7, [$traffic, $disks], $revisions);
        $two = count($this->statements->sql) - $before;
        self::assertSame(['1', (string) $kept], $this->column('SELECT id FROM automation_graph_rules ORDER BY id'));
        self::assertSame(['1', (string) $kept], $this->column('SELECT DISTINCT rule_id FROM automation_graph_rule_items ORDER BY rule_id'));
        self::assertSame([(string) $traffic . ':3', (string) $kept . ':1'], $this->column("SELECT DISTINCT rule_id || ':' || rule_type FROM automation_match_rule_items ORDER BY rule_id, rule_type"));
        $event = $this->audit[array_key_last($this->audit)];
        self::assertSame(['automation.graph_rule.delete', AuditEvent::SUCCEEDED], [$event->action, $event->outcome]);
        self::assertTrue($this->checkedInTransaction[array_key_last($this->checkedInTransaction)]);

        // Fifty rules cost the same statements as two: nothing runs per selected row.
        $many = $this->rules(array_map(static fn(int $number): string => 'Rule ' . $number, range(1, 50)));
        $revisions = $this->revisions($many);
        $before = count($this->statements->sql);
        $this->store->deleteRules(7, $many, $revisions);
        self::assertSame($two, count($this->statements->sql) - $before);
        self::assertSame(2, (int) $this->database->fetchOne('SELECT COUNT(*) FROM automation_graph_rules'));
    }

    public function testBulkActionsRefuseStaleMissingAndMalformedSelectionsWithoutWriting(): void
    {
        [$traffic, $disks] = $this->rules(['Traffic', 'Disks']);
        $revisions = $this->revisions([$traffic, $disks]);
        $checks = count($this->checkedInTransaction);
        $writes = [
            'delete' => fn(array $ids, array $given) => $this->store->deleteRules(7, $ids, $given),
            'duplicate' => fn(array $ids, array $given) => $this->store->duplicateRules(7, $ids, $given, GraphRule::NAME_FORMAT),
            'enable' => fn(array $ids, array $given) => $this->store->setEnabled(7, $ids, $given, true),
        ];
        $before = $this->state();
        foreach ($writes as $operation => $write) {
            foreach ([
                'empty' => [[], []],
                'unsorted' => [[$disks, $traffic], $revisions],
                'repeated' => [[$traffic, $traffic], [$traffic => $revisions[$traffic]]],
                'zero' => [[0], [0 => 'x']],
                'beyond the column' => [[16777216], [16777216 => 'x']],
                'text id' => [['1'], [1 => 'x']],
                'too many' => [range(1, 101), array_fill(1, 101, 'x')],
                'missing revision' => [[$traffic, $disks], [$traffic => $revisions[$traffic]]],
                'non-text revision' => [[$traffic], [$traffic => 1]],
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

        $this->database->update('automation_graph_rules', ['name' => 'Renamed'], ['id' => $disks]);
        $renamed = $this->state();
        foreach ($writes as $operation => $write) {
            foreach ([[[$traffic, $disks], $revisions, 'changed since'], [[$traffic, 999], [$traffic => $revisions[$traffic], 999 => 'x'], 'no longer exist']] as [$ids, $given, $message]) {
                try {
                    $write($ids, $given);
                    self::fail('Accepted a selection that should report: ' . $operation . ' ' . $message);
                } catch (\InvalidArgumentException $error) {
                    self::assertStringContainsString($message, $error->getMessage());
                }
            }
        }
        self::assertSame($renamed, $this->state());
        self::assertNotSame($before, $renamed);

        // The criteria go first; when the rule rows cannot be removed, they come back.
        $this->database->executeStatement("CREATE TRIGGER refuse_rule_delete BEFORE DELETE ON automation_graph_rules BEGIN SELECT RAISE(ABORT, 'refused'); END");
        try {
            $this->store->deleteRules(7, [$traffic], [$traffic => $revisions[$traffic]]);
            self::fail('A failed deletion reported success.');
        } catch (\InvalidArgumentException $error) {
            self::fail('A database failure was reported as a correctable refusal: ' . $error->getMessage());
        } catch (\Throwable) {
        }
        self::assertSame($renamed, $this->state());
        self::assertSame(AuditEvent::FAILED, $this->audit[array_key_last($this->audit)]->outcome);

        $this->revoked = true;
        foreach (['delete' => fn() => $this->store->deleteRules(7, [$traffic], [$traffic => $revisions[$traffic]]),
            'disable' => fn() => $this->store->setEnabled(7, [$traffic], [$traffic => $revisions[$traffic]], false)] as $operation => $write) {
            try {
                $write();
                self::fail('A revoked actor could ' . $operation . '.');
            } catch (AutomationTemplateAccessDenied) {
            }
            self::assertSame(AuditEvent::DENIED, $this->audit[array_key_last($this->audit)]->outcome);
        }
        self::assertSame($renamed, $this->state());
    }

    public function testUnconfirmedDeletionAndDuplicationRollBackAndAreAudited(): void
    {
        [$traffic, $disks] = $this->rules(['Traffic', 'Disks']);
        $revisions = $this->revisions([$traffic, $disks]);
        $before = $this->state();

        // A rule row that survives its DELETE leaves the count short of the selection.
        $this->database->executeStatement('CREATE TRIGGER keep_traffic BEFORE DELETE ON automation_graph_rules WHEN OLD.id = ' . $traffic . ' BEGIN SELECT RAISE(IGNORE); END');
        try {
            $this->store->deleteRules(7, [$traffic, $disks], $revisions);
            self::fail('An unconfirmed deletion was reported as done.');
        } catch (\RuntimeException $error) {
            self::assertSame('Graph Rule deletion was not confirmed.', $error->getMessage());
        }
        self::assertSame($before, $this->state());
        self::assertSame(AuditEvent::FAILED, $this->audit[array_key_last($this->audit)]->outcome);

        // MySQL answers 0 when an insert generated no ID; that is not a copy.
        $this->database->executeStatement('DROP TRIGGER keep_traffic');
        self::assertInstanceOf(GraphRuleIdentityLessConnection::class, $this->database);
        $this->database->identityLess = true;
        try {
            $this->store->duplicateRules(7, [$traffic], [$traffic => $revisions[$traffic]], GraphRule::NAME_FORMAT);
            self::fail('An unconfirmed duplication was reported as done.');
        } catch (\RuntimeException $error) {
            self::assertSame('Graph Rule duplication was not confirmed.', $error->getMessage());
        }
        self::assertSame($before, $this->state());
        self::assertSame(AuditEvent::FAILED, $this->audit[array_key_last($this->audit)]->outcome);
    }

    public function testDuplicateCopiesBothKindsOfCriteriaAndStartsDisabled(): void
    {
        [$traffic, $empty] = $this->rules(['Traffic', 'Empty']);
        $this->database->executeStatement('DELETE FROM automation_graph_rule_items WHERE rule_id = ?', [$empty]);
        $this->database->executeStatement('DELETE FROM automation_match_rule_items WHERE rule_id = ?', [$empty]);
        // The tree rule of the same ID owns this item; it is not a criterion of the graph rule.
        $this->database->insert('automation_match_rule_items', ['rule_id' => $traffic, 'rule_type' => 3, 'sequence' => 1, 'field' => 'h.description', 'pattern' => 'tree']);
        self::assertSame('on', $this->database->fetchOne('SELECT enabled FROM automation_graph_rules WHERE id = ?', [$traffic]));
        $matches = $this->items('automation_match_rule_items', $traffic, 1);
        $actions = $this->items('automation_graph_rule_items', $traffic);

        $revisions = $this->revisions([$traffic, $empty]);
        $before = count($this->statements->sql);
        $this->store->duplicateRules(7, [$traffic, $empty], $revisions, GraphRule::NAME_FORMAT);
        $two = count($this->statements->sql) - $before;
        $copies = $this->database->fetchAllAssociative('SELECT id, name, snmp_query_id, graph_type_id, enabled FROM automation_graph_rules WHERE id > ? ORDER BY id', [$empty]);
        self::assertSame([['Traffic (1)', '1', '12', ''], ['Empty (1)', '1', '12', '']], array_map(static fn(array $row): array => array_map('strval', [$row['name'], $row['snmp_query_id'], $row['graph_type_id'], $row['enabled']]), $copies));
        $copy = (int) $copies[0]['id'];
        self::assertSame($matches, $this->items('automation_match_rule_items', $copy, 1));
        self::assertSame([], $this->items('automation_match_rule_items', $copy, 3));
        self::assertSame($actions, $this->items('automation_graph_rule_items', $copy));
        self::assertSame([], $this->items('automation_graph_rule_items', (int) $copies[1]['id']));
        // The originals are untouched.
        self::assertSame($matches, $this->items('automation_match_rule_items', $traffic, 1));
        self::assertSame('on', $this->database->fetchOne('SELECT enabled FROM automation_graph_rules WHERE id = ?', [$traffic]));
        $event = $this->audit[array_key_last($this->audit)];
        self::assertSame(['automation.graph_rule.duplicate', AuditEvent::SUCCEEDED], [$event->action, $event->outcome]);

        // One insert per copied rule, and nothing else grows with the selection.
        $many = $this->rules(array_map(static fn(int $number): string => 'Rule ' . $number, range(1, 40)));
        $revisions = $this->revisions($many);
        $before = count($this->statements->sql);
        $this->store->duplicateRules(7, $many, $revisions, '<rule_name> copy');
        // Lock, one insert per rule and one statement for each kind of criteria.
        self::assertSame(5, $two);
        self::assertSame(43, count($this->statements->sql) - $before);
        self::assertSame(40, (int) $this->database->fetchOne("SELECT COUNT(*) FROM automation_graph_rules WHERE name LIKE 'Rule % copy'"));
        self::assertSame(80, (int) $this->database->fetchOne("SELECT COUNT(*) FROM automation_match_rule_items i INNER JOIN automation_graph_rules r ON r.id = i.rule_id WHERE r.name LIKE 'Rule % copy'"));
        self::assertSame(40, (int) $this->database->fetchOne("SELECT COUNT(*) FROM automation_graph_rule_items i INNER JOIN automation_graph_rules r ON r.id = i.rule_id WHERE r.name LIKE 'Rule % copy'"));
    }

    public function testDuplicateNamesFollowTheLegacyFormatAndMustFitTheColumn(): void
    {
        self::assertSame('Traffic (1)', GraphRule::copyName('<rule_name> (1)', 'Traffic'));
        self::assertSame('Traffic/Traffic', GraphRule::copyName('<rule_name>/<rule_name>', 'Traffic'));
        // Without the token every copy gets the same name, as on the legacy page; other tokens are literal text.
        self::assertSame('Fixed <name>', GraphRule::copyName('Fixed <name>', 'Traffic'));
        self::assertSame(str_repeat('é', 251) . ' (1)', GraphRule::copyName('<rule_name> (1)', str_repeat('é', 251)));
        foreach ([['<rule_name> (1)', str_repeat('n', 252)], ['', 'Traffic'], ['<rule_name>', ''], [str_repeat('x', 256), 'Traffic'], ["<rule_name>\n", 'Traffic'], ["<rule_name>\0", 'Traffic'], ["\xff<rule_name>", 'Traffic']] as [$format, $name]) {
            try {
                GraphRule::copyName($format, $name);
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
        [$traffic, $disks] = $this->rules(['Traffic', 'Disks']);
        $this->database->update('automation_graph_rules', ['enabled' => ''], ['id' => $disks]);
        $items = $this->state();

        $revisions = $this->revisions([$traffic, $disks]);
        $before = count($this->statements->sql);
        $this->store->setEnabled(7, [$traffic, $disks], $revisions, true);
        self::assertSame(2, count($this->statements->sql) - $before);
        self::assertSame(['on', 'on'], $this->column('SELECT enabled FROM automation_graph_rules WHERE id IN (?, ?) ORDER BY id', [$traffic, $disks]));
        self::assertSame(['automation.graph_rule.enable', AuditEvent::SUCCEEDED], [$this->audit[array_key_last($this->audit)]->action, $this->audit[array_key_last($this->audit)]->outcome]);
        // A rule that already had the value is not an error.
        $this->store->setEnabled(7, [$traffic], $this->revisions([$traffic]), true);

        $this->store->setEnabled(7, [$disks], $this->revisions([$disks]), false);
        self::assertSame(['on', ''], $this->column('SELECT enabled FROM automation_graph_rules WHERE id IN (?, ?) ORDER BY id', [$traffic, $disks]));
        self::assertSame('automation.graph_rule.disable', $this->audit[array_key_last($this->audit)]->action);
        // Only the enabled column changes; the criteria and the other settings stay.
        self::assertSame(array_diff_key($items, ['automation_graph_rules' => true]), array_diff_key($this->state(), ['automation_graph_rules' => true]));
        self::assertSame(['Traffic', 'Disks'], $this->column('SELECT name FROM automation_graph_rules WHERE id IN (?, ?) ORDER BY id', [$traffic, $disks]));
    }

    /**
     * Each rule gets the seeded rule's settings, one Device Selection Criterion,
     * one Graph Creation Criterion and a second criterion after a bracket.
     *
     * @param list<string> $names
     * @return list<int>
     */
    private function rules(array $names): array
    {
        $ids = [];
        foreach ($names as $name) {
            $this->database->insert('automation_graph_rules', ['name' => $name, 'snmp_query_id' => 1, 'graph_type_id' => 12, 'enabled' => 'on']);
            $ids[] = $id = (int) $this->database->lastInsertId();
            $this->database->insert('automation_match_rule_items', ['rule_id' => $id, 'rule_type' => 1, 'sequence' => 1, 'operation' => 0, 'field' => 'h.description', 'operator' => 1, 'pattern' => $name]);
            $this->database->insert('automation_match_rule_items', ['rule_id' => $id, 'rule_type' => 1, 'sequence' => 2, 'operation' => 1, 'field' => 'ht.name', 'operator' => 7, 'pattern' => 'Linux']);
            $this->database->insert('automation_graph_rule_items', ['rule_id' => $id, 'sequence' => 1, 'operation' => 0, 'field' => 'ifOperStatus', 'operator' => 7, 'pattern' => 'Up']);
        }
        return $ids;
    }

    /**
     * @param list<int> $ids
     * @return array<int, string>
     */
    private function revisions(array $ids): array
    {
        return array_map(static fn(GraphRule $rule): string => $rule->revision, $this->store->findRules($ids));
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
        foreach (['automation_graph_rules', 'automation_graph_rule_items', 'automation_match_rule_items'] as $table) {
            $state[$table] = $this->database->fetchAllAssociative('SELECT * FROM ' . $table . ' ORDER BY id');
        }
        return $state;
    }
}

/** A connection whose last insert ID can be made to read 0, as MySQL reports an insert that generated none. */
final class GraphRuleIdentityLessConnection extends Connection
{
    public bool $identityLess = false;

    public function lastInsertId(): int|string
    {
        return $this->identityLess ? 0 : parent::lastInsertId();
    }
}
