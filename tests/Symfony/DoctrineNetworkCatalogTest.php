<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Doctrine\DBAL\DriverManager;
use Kadupul\Collection\Domain\NetworkListCriteria;
use Kadupul\Collection\Infrastructure\Persistence\DoctrineNetworkCatalog;
use PHPUnit\Framework\TestCase;

final class DoctrineNetworkCatalogTest extends TestCase
{
    public function testItProjectsProgressWithoutMutatingStaleProcessRows(): void
    {
        $database = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $database->executeStatement('CREATE TABLE poller (id INTEGER PRIMARY KEY, name TEXT)');
        $database->executeStatement('CREATE TABLE automation_networks (
            id INTEGER PRIMARY KEY, name TEXT, poller_id INTEGER, sched_type INTEGER, total_ips INTEGER,
            enabled TEXT, up_hosts INTEGER, snmp_hosts INTEGER, threads INTEGER, last_runtime REAL,
            start_at TEXT, next_start TEXT, last_started TEXT
        )');
        $database->executeStatement('CREATE TABLE automation_processes (
            pid INTEGER, network_id INTEGER, status TEXT, up_hosts INTEGER, snmp_hosts INTEGER
        )');
        $database->executeStatement('CREATE TABLE automation_ips (ip_address TEXT, network_id INTEGER, status INTEGER)');
        $database->executeStatement("INSERT INTO poller VALUES (1, 'Primary')");
        $database->executeStatement("INSERT INTO automation_networks VALUES
            (1, 'Active subnet', 1, 2, 4, 'on', 2, 1, 3, 5.231, '2026-09-28 08:00', '2026-09-28 08:00:00', '2026-09-27 08:30:00'),
            (2, 'Idle subnet', 1, 1, 12, 'on', 7, 4, 2, 0, '2026-09-27 08:00', '0000-00-00 00:00:00', '0000-00-00 00:00:00'),
            (3, 'Disabled subnet', 1, 2, 8, '', 8, 5, 1, 0, '2026-09-27 08:00', '2026-09-27 08:00:00', '0000-00-00 00:00:00')");
        $database->executeStatement("INSERT INTO automation_processes VALUES (9, 1, 'running', 2, 1), (10, 2, 'done', 50, 30)");
        $database->executeStatement("INSERT INTO automation_ips VALUES ('192.0.2.1', 1, 0), ('192.0.2.2', 1, 1), ('192.0.2.3', 1, 2)");

        $page = (new DoctrineNetworkCatalog($database))->list(new NetworkListCriteria());
        self::assertCount(3, $page->networks);
        $byName = [];
        foreach ($page->networks as $network) {
            $byName[$network->name] = $network;
        }
        self::assertSame('Running', $byName['Active subnet']->status);
        self::assertSame('1/1/1', $byName['Active subnet']->progress);
        self::assertSame(2, $byName['Active subnet']->upHosts);
        self::assertSame('2026-09-28 08:00', $byName['Active subnet']->nextStart);
        self::assertSame('Idle', $byName['Idle subnet']->status);
        self::assertSame('7/4', $byName['Idle subnet']->upHosts . '/' . $byName['Idle subnet']->snmpHosts);
        self::assertNull($byName['Idle subnet']->nextStart);
        self::assertSame('Disabled', $byName['Disabled subnet']->status);
        self::assertSame('0/0/0', $byName['Disabled subnet']->progress);
        self::assertSame(2, (int) $database->fetchOne('SELECT COUNT(*) FROM automation_processes'));
    }
}
