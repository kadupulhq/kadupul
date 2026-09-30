<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Doctrine\DBAL\DriverManager;
use Kadupul\Collection\Domain\AutomationGraphRuleCriteria;
use Kadupul\Collection\Domain\AutomationTemplateCriteria;
use Kadupul\Collection\Domain\AutomationTreeRuleCriteria;
use Kadupul\Collection\Domain\DiscoveredDeviceCriteria;
use Kadupul\Collection\Infrastructure\Persistence\DoctrineAutomationGraphRuleCatalog;
use Kadupul\Collection\Infrastructure\Persistence\DoctrineAutomationTemplateCatalog;
use Kadupul\Collection\Infrastructure\Persistence\DoctrineAutomationTreeRuleCatalog;
use Kadupul\Collection\Infrastructure\Persistence\DoctrineDiscoveredDeviceCatalog;
use PHPUnit\Framework\TestCase;

final class DoctrineCollectionCatalogTest extends TestCase
{
    public function testAutomationTemplatesJoinHostTemplateAndApplySearch(): void
    {
        $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $db->executeStatement('CREATE TABLE automation_templates (id INTEGER, host_template INTEGER, availability_method INTEGER, sysDescr TEXT, sysName TEXT, sysOid TEXT, sequence INTEGER)');
        $db->executeStatement('CREATE TABLE host_template (id INTEGER, name TEXT)');
        $db->executeStatement("INSERT INTO automation_templates VALUES (1, 5, 3, '<desc>', 'sys', 'oid', 2)");
        $db->executeStatement("INSERT INTO host_template VALUES (5, 'Router')");

        $page = (new DoctrineAutomationTemplateCatalog($db))->list(new AutomationTemplateCriteria('Router'));
        self::assertCount(1, $page->templates);
        self::assertSame('Router', $page->templates[0]->hostTemplate);
        self::assertSame('Ping', $page->templates[0]->availabilityMethod);
        self::assertSame('<desc>', $page->templates[0]->systemDescription);
    }

    public function testAutomationTreeRulesJoinSubtreeWithinItsOwningTree(): void
    {
        $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $db->executeStatement('CREATE TABLE automation_tree_rules (id INTEGER, name TEXT, tree_id INTEGER, tree_item_id INTEGER, leaf_type INTEGER, host_grouping_type INTEGER, enabled TEXT)');
        $db->executeStatement('CREATE TABLE graph_tree (id INTEGER, name TEXT)');
        $db->executeStatement('CREATE TABLE graph_tree_items (id INTEGER, graph_tree_id INTEGER, title TEXT)');
        $db->executeStatement("INSERT INTO automation_tree_rules VALUES (1, 'rule', 2, 7, 2, 1, 'on')");
        $db->executeStatement("INSERT INTO graph_tree VALUES (2, 'right tree'), (3, 'wrong tree')");
        $db->executeStatement("INSERT INTO graph_tree_items VALUES (7, 3, 'wrong subtree')");

        $page = (new DoctrineAutomationTreeRuleCatalog($db))->list(new AutomationTreeRuleCriteria());
        self::assertSame('right tree', $page->rules[0]->tree);
        self::assertSame('', $page->rules[0]->subtree);
        self::assertSame('Graph', $page->rules[0]->leafType);
        self::assertTrue($page->rules[0]->enabled);
    }

    public function testAutomationGraphRulesFilterAndResolveJoinedLabels(): void
    {
        $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $db->executeStatement('CREATE TABLE automation_graph_rules (id INTEGER, name TEXT, enabled TEXT, snmp_query_id INTEGER, graph_type_id INTEGER)');
        $db->executeStatement('CREATE TABLE snmp_query (id INTEGER, name TEXT)');
        $db->executeStatement('CREATE TABLE snmp_query_graph (id INTEGER, name TEXT)');
        $db->executeStatement("INSERT INTO automation_graph_rules VALUES (1, 'CPU rule', 'on', 4, 8), (2, 'other', '', 9, 9)");
        $db->executeStatement("INSERT INTO snmp_query VALUES (4, 'CPU query')");
        $db->executeStatement("INSERT INTO snmp_query_graph VALUES (8, 'CPU graph')");

        $page = (new DoctrineAutomationGraphRuleCatalog($db))->list(new AutomationGraphRuleCriteria('CPU', 'enabled', 4));
        self::assertCount(1, $page->rules);
        self::assertSame('CPU query', $page->rules[0]->dataQuery);
        self::assertSame('CPU graph', $page->rules[0]->graphType);
        self::assertTrue($page->rules[0]->enabled);
    }

    public function testDiscoveredDevicesFilterAndExposeUnfilteredChoiceLists(): void
    {
        $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $db->executeStatement('CREATE TABLE automation_devices (id INTEGER, network_id INTEGER, hostname TEXT, ip TEXT, sysName TEXT, sysLocation TEXT, sysContact TEXT, sysDescr TEXT, os TEXT, sysUptime INTEGER, snmp INTEGER, up INTEGER, time INTEGER)');
        $db->executeStatement('CREATE TABLE automation_networks (id INTEGER, name TEXT)');
        $db->executeStatement("INSERT INTO automation_networks VALUES (1, 'Office')");
        $db->executeStatement("INSERT INTO automation_devices VALUES (1, 1, '<router>', '192.0.2.1', '', '', '', 'Linux', 'Linux', 12000, 1, 1, 0), (2, 1, 'down', '192.0.2.2', '', '', '', 'Other', 'OtherOS', 0, 0, 0, 0)");
        $db->getNativeConnection()->sqliteCreateFunction('FROM_UNIXTIME', static fn(int $time): string => gmdate('Y-m-d H:i:s', $time));

        $page = (new DoctrineDiscoveredDeviceCatalog($db))->list(new DiscoveredDeviceCriteria(status: 'up', networkId: 1));
        self::assertCount(1, $page->devices);
        self::assertSame('<router>', $page->devices[0]->hostname);
        self::assertSame(120, $page->devices[0]->uptimeSeconds);
        self::assertCount(1, $page->networks);
        self::assertSame(['Linux', 'OtherOS'], $page->operatingSystems);
    }
}
