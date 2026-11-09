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
use Kadupul\Automation\Domain\GraphRuleChange;
use Kadupul\Automation\Domain\GraphRuleRejected;
use Kadupul\Automation\Infrastructure\Persistence\DbalGraphRuleStore;
use Kadupul\IdentityAccess\Contract\AuditEvent;
use Kadupul\IdentityAccess\Contract\AuditTrail;
use Kadupul\Platform\Contract\LegacyConfiguration;
use PHPUnit\Framework\TestCase;

final class GraphRuleStoreTest extends TestCase
{
    private Connection $database;
    private DbalGraphRuleStore $store;
    private bool $revoked = false;
    /** @var list<bool> */
    private array $checkedInTransaction = [];
    /** @var list<AuditEvent> */
    private array $audit = [];

    protected function setUp(): void
    {
        $this->database = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        // The cacti.sql columns, with their defaults, and the rule cacti.sql seeds.
        foreach ([
            "CREATE TABLE automation_graph_rules (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL DEFAULT '', snmp_query_id INT NOT NULL DEFAULT 0,
                graph_type_id INT NOT NULL DEFAULT 0, enabled TEXT DEFAULT '')",
            "INSERT INTO automation_graph_rules VALUES (1, 'Traffic 64 bit Server', 1, 12, 'on')",
            'CREATE TABLE automation_graph_rule_items (id INTEGER PRIMARY KEY, rule_id INT, field TEXT)',
            "INSERT INTO automation_graph_rule_items VALUES (5, 1, 'ifOperStatus')",
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
}
