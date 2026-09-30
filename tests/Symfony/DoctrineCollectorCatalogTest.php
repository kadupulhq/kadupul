<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Doctrine\DBAL\DriverManager;
use Kadupul\CollectorAdministration\Domain\CollectorListCriteria;
use Kadupul\CollectorAdministration\Infrastructure\Persistence\DoctrineCollectorCatalog;
use PHPUnit\Framework\TestCase;

final class DoctrineCollectorCatalogTest extends TestCase
{
    public function testItReturnsBoundedSearchResultsAndDoesNotSelectRemoteCredentials(): void
    {
        $database = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $pdo = $database->getNativeConnection();
        $pdo->sqliteCreateFunction('UNIX_TIMESTAMP', static function (...$arguments): int {
            return isset($arguments[0]) && is_string($arguments[0]) ? (int) strtotime($arguments[0]) : (int) strtotime('2026-09-27 10:00:01');
        }, -1);
        $database->executeStatement('CREATE TABLE settings (name TEXT PRIMARY KEY, value TEXT)');
        $database->executeStatement("INSERT INTO settings VALUES ('poller_type', '2')");
        $catalog = new DoctrineCollectorCatalog($database);
        self::assertSame(30, $catalog->defaultPageSize());
        $database->executeStatement("INSERT INTO settings VALUES ('num_rows_table', '50')");
        self::assertSame(50, $catalog->defaultPageSize());
        $database->executeStatement("UPDATE settings SET value = 'invalid' WHERE name = 'num_rows_table'");
        self::assertSame(30, $catalog->defaultPageSize());
        $database->executeStatement('CREATE TABLE poller (
            id INTEGER PRIMARY KEY, name TEXT, hostname TEXT, disabled TEXT, status INTEGER,
            processes INTEGER, threads INTEGER, total_time REAL, avg_time REAL, max_time REAL,
            snmp INTEGER, script INTEGER, server INTEGER, last_update TEXT, last_status TEXT,
            last_sync TEXT, dbpass TEXT
        )');
        $database->executeStatement('CREATE TABLE host (id INTEGER PRIMARY KEY, poller_id INTEGER)');
        $database->executeStatement("INSERT INTO poller VALUES (1, 'Primary % Collector', 'primary.example', '', 1, 2, 4, 9.5, 4.25, 8.5, 12, 3, 1, '2026-09-27 10:00:00', '2026-09-27 10:00:00', '', 'secret-not-selected'), (2, 'Other', 'other.example', '', 1, 1, 2, 1.0, 1.0, 1.0, 0, 0, 0, '2026-09-27 10:00:00', '2026-09-27 10:00:00', '2026-09-27 10:00:00', 'secret-not-selected')");
        $database->executeStatement('INSERT INTO host VALUES (7, 1), (8, 1), (9, 2)');

        $page = (new DoctrineCollectorCatalog($database))->list(new CollectorListCriteria('Primary', 1, 25, 'name', 'asc'));

        self::assertFalse($page->hasNext);
        self::assertCount(1, $page->collectors);
        self::assertSame('Primary % Collector', $page->collectors[0]->name);
        self::assertSame(2, $page->collectors[0]->hosts);
        self::assertSame('Running', $page->collectors[0]->status);
        self::assertSame(4.25, $page->collectors[0]->averageTime);
        self::assertObjectNotHasProperty('dbpass', $page->collectors[0]);

        $wildcardPage = (new DoctrineCollectorCatalog($database))->list(new CollectorListCriteria('%', 1, 25, 'name', 'asc'));
        self::assertCount(2, $wildcardPage->collectors);
    }

    public function testHeartbeatAndDisabledOverridesApplyAfterSafeProjection(): void
    {
        $database = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $pdo = $database->getNativeConnection();
        $pdo->sqliteCreateFunction('UNIX_TIMESTAMP', static function (...$arguments): int {
            return isset($arguments[0]) && is_string($arguments[0]) ? (int) strtotime($arguments[0]) : time();
        }, -1);
        $database->executeStatement('CREATE TABLE settings (name TEXT PRIMARY KEY, value TEXT)');
        $database->executeStatement("INSERT INTO settings VALUES ('poller_type', '1')");
        $database->executeStatement('CREATE TABLE poller (
            id INTEGER PRIMARY KEY, name TEXT, hostname TEXT, disabled TEXT, status INTEGER,
            processes INTEGER, threads INTEGER, total_time REAL, avg_time REAL, max_time REAL,
            snmp INTEGER, script INTEGER, server INTEGER, last_update TEXT, last_status TEXT,
            last_sync TEXT
        )');
        $database->executeStatement('CREATE TABLE host (id INTEGER PRIMARY KEY, poller_id INTEGER)');
        $database->executeStatement("INSERT INTO poller VALUES (1, 'Old', 'old.example', '', 1, 1, 1, 0, 0, 0, 0, 0, 0, '', '2000-01-01 00:00:00', ''), (2, 'Off', 'off.example', 'on', 1, 1, 1, 0, 0, 0, 0, 0, 0, '', '2000-01-01 00:00:00', '')");

        $page = (new DoctrineCollectorCatalog($database))->list(new CollectorListCriteria(sort: 'id'));

        self::assertSame('Heartbeat', $page->collectors[0]->status);
        self::assertSame('Disabled', $page->collectors[1]->status);
    }
}
