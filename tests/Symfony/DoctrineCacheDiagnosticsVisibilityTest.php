<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Doctrine\DBAL\DriverManager;
use Kadupul\Inventory\Infrastructure\Persistence\DoctrineCacheDiagnostics;
use PHPUnit\Framework\TestCase;

final class DoctrineCacheDiagnosticsVisibilityTest extends TestCase
{
    public function testSimpleHostPolicyDoesNotBypassRestrictiveAndGraphTemplateModes(): void
    {
        $database = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        foreach ([
            'CREATE TABLE settings (name TEXT PRIMARY KEY, value TEXT NOT NULL)',
            'CREATE TABLE user_auth (id INTEGER PRIMARY KEY, policy_graphs INTEGER NOT NULL, policy_hosts INTEGER NOT NULL, policy_graph_templates INTEGER NOT NULL)',
            'CREATE TABLE user_auth_group (id INTEGER PRIMARY KEY, enabled TEXT NOT NULL, policy_graphs INTEGER NOT NULL, policy_hosts INTEGER NOT NULL, policy_graph_templates INTEGER NOT NULL)',
            'CREATE TABLE user_auth_group_members (group_id INTEGER NOT NULL, user_id INTEGER NOT NULL)',
            'CREATE TABLE user_auth_perms (user_id INTEGER NOT NULL, item_id INTEGER NOT NULL, type INTEGER NOT NULL)',
            'CREATE TABLE user_auth_group_perms (group_id INTEGER NOT NULL, item_id INTEGER NOT NULL, type INTEGER NOT NULL)',
            "CREATE TABLE host (id INTEGER PRIMARY KEY, description TEXT NOT NULL, disabled TEXT NOT NULL DEFAULT '')",
            'CREATE TABLE graph_local (id INTEGER PRIMARY KEY, host_id INTEGER NOT NULL, graph_template_id INTEGER NOT NULL)',
            'CREATE TABLE data_local (id INTEGER PRIMARY KEY, host_id INTEGER NOT NULL, data_template_id INTEGER NOT NULL)',
            "CREATE TABLE data_template_data (local_data_id INTEGER PRIMARY KEY, name_cache TEXT NOT NULL, active TEXT NOT NULL DEFAULT 'on')",
            "CREATE TABLE poller_item (local_data_id INTEGER NOT NULL, host_id INTEGER NOT NULL, action INTEGER NOT NULL DEFAULT 0, rrd_name TEXT NOT NULL, rrd_path TEXT NOT NULL, rrd_step INTEGER NOT NULL DEFAULT 300, rrd_next_step INTEGER NOT NULL DEFAULT 0)",
            'CREATE TABLE snmp_query (id INTEGER PRIMARY KEY, name TEXT NOT NULL)',
            'CREATE TABLE host_snmp_cache (host_id INTEGER NOT NULL, snmp_query_id INTEGER NOT NULL, snmp_index TEXT NOT NULL, field_name TEXT NOT NULL, field_value TEXT NOT NULL, oid TEXT NOT NULL)',
        ] as $ddl) {
            $database->executeStatement($ddl);
        }
        $database->executeStatement("INSERT INTO host VALUES (1, 'Host One', ''), (2, 'Host Two', ''), (3, 'Host Three', '')");
        $database->executeStatement('INSERT INTO graph_local VALUES (101, 1, 10), (102, 2, 20)');
        $database->executeStatement('INSERT INTO data_local VALUES (10, 1, 1), (20, 2, 1), (30, 3, 1), (40, 0, 0)');
        $database->executeStatement("INSERT INTO data_template_data VALUES (10, 'Source 1', 'on'), (20, 'Source 2', 'on'), (30, 'Source 3', 'on'), (40, 'Source 0', 'on')");
        $database->executeStatement("INSERT INTO poller_item VALUES (10, 1, 0, 'in', '/1.rrd', 300, 0), (20, 2, 0, 'in', '/2.rrd', 300, 0), (30, 3, 0, 'in', '/3.rrd', 300, 0), (40, 0, 0, 'in', '/0.rrd', 300, 0)");
        $database->executeStatement("INSERT INTO snmp_query VALUES (1, 'Interfaces')");
        $database->executeStatement("INSERT INTO host_snmp_cache VALUES (1, 1, '1', 'ifName', 'eth0', '.1'), (2, 1, '1', 'ifName', 'eth0', '.1'), (3, 1, '1', 'ifName', 'eth0', '.1')");
        // policy_hosts = 1 with zero type=3 exceptions, but graph/template defaults deny.
        $database->executeStatement('INSERT INTO user_auth VALUES (42, 2, 1, 2)');
        $database->executeStatement('INSERT INTO user_auth_perms VALUES (42, 10, 4)');

        $reader = new DoctrineCacheDiagnostics($database);
        $pollerFilters = ['host_id' => '-1', 'template_id' => '-1', 'poller_action' => '-1', 'status' => '-1', 'filter' => ''];
        $snmpFilters = ['host_id' => '-1', 'snmp_query_id' => '-1', 'with_index' => '0', 'filter' => ''];

        foreach ([
            1 => [['Source 0', 'Source 1', 'Source 2', 'Source 3'], ['Host One', 'Host Two', 'Host Three']],
            2 => [['Source 0', 'Source 1'], ['Host One']],
            3 => [['Source 0', 'Source 1', 'Source 2', 'Source 3'], ['Host One', 'Host Two', 'Host Three']],
            4 => [['Source 0', 'Source 1'], ['Host One']],
        ] as $mode => [$expectedPoller, $expectedSnmp]) {
            $database->executeStatement("DELETE FROM settings WHERE name = 'graph_auth_method'");
            $database->executeStatement("INSERT INTO settings VALUES ('graph_auth_method', ?)", [(string) $mode]);

            self::assertSame($expectedPoller, array_column($reader->read(true, 42, $pollerFilters, 50, 0), 0), 'poller mode=' . $mode);
            self::assertSame($expectedSnmp, array_column($reader->read(false, 42, $snmpFilters, 50, 0), 0), 'snmp mode=' . $mode);
        }

        // Without any template exceptions, modes 2 and 4 must not expose any device rows.
        $database->executeStatement('DELETE FROM user_auth_perms WHERE user_id = 42');
        foreach ([2, 4] as $mode) {
            $database->executeStatement("UPDATE settings SET value = ? WHERE name = 'graph_auth_method'", [(string) $mode]);
            self::assertSame(['Source 0'], array_column($reader->read(true, 42, $pollerFilters, 50, 0), 0), 'poller no-exc mode=' . $mode);
            self::assertSame([], $reader->read(false, 42, $snmpFilters, 50, 0), 'snmp no-exc mode=' . $mode);
        }
    }
}
