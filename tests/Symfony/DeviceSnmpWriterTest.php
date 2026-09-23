<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Inventory\Domain\DeviceSnmpConfiguration;
use Kadupul\Inventory\Domain\DeviceState;
use Kadupul\Inventory\Infrastructure\Legacy\DeviceSnmpWriter;
use PHPUnit\Framework\TestCase;

final class DeviceSnmpWriterTest extends TestCase
{
    public static function failures(): array
    {
        return [[false], [true]];
    }
    #[\PHPUnit\Framework\Attributes\DataProvider('failures')]
    public function testDisablingSnmpAtomicallyCleansReindexState(bool $reject): void
    {
        $change = new DeviceSnmpConfiguration(['snmp_version' => '0'] + DeviceSnmpConfiguration::PUBLIC_DEFAULTS + DeviceSnmpConfiguration::CREDENTIAL_DEFAULTS);
        $pdo = new \PDO('sqlite::memory:');
        $columns = implode(', ', array_map(static fn($field) => $field . ' TEXT', array_keys($change->fields)));
        $pdo->exec("CREATE TABLE host (id INT, poller_id INT, deleted TEXT, $columns); CREATE TABLE host_snmp_query (host_id INT,reindex_method INT); CREATE TABLE poller_reindex (host_id INT)");
        $pdo->exec("INSERT INTO host (id,poller_id,deleted,snmp_version) VALUES (7,2,'','2'); INSERT INTO host_snmp_query VALUES (7,1),(8,2); INSERT INTO poller_reindex VALUES (7),(8)");
        if ($reject) {
            $pdo->exec("CREATE TRIGGER reject_cleanup BEFORE DELETE ON poller_reindex BEGIN SELECT RAISE(ABORT,'fixture cleanup rejection'); END");
        }
        try {
            (new DeviceSnmpWriter())->apply($pdo, new DeviceState(7, 'Router', 'router.invalid', true, 0, 2, 0), $change);
            self::assertFalse($reject);
        } catch (\PDOException) {
            self::assertTrue($reject);
        }
        self::assertFalse($pdo->inTransaction());
        self::assertSame($reject ? '2' : '0', $pdo->query('SELECT snmp_version FROM host WHERE id=7')->fetchColumn());
        self::assertSame($reject ? 1 : 0, (int) $pdo->query('SELECT reindex_method FROM host_snmp_query WHERE host_id=7')->fetchColumn());
        self::assertSame($reject ? 1 : 0, (int) $pdo->query('SELECT COUNT(*) FROM poller_reindex WHERE host_id=7')->fetchColumn());
        self::assertSame(2, (int) $pdo->query('SELECT reindex_method FROM host_snmp_query WHERE host_id=8')->fetchColumn());
        self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM poller_reindex WHERE host_id=8')->fetchColumn());
    }
}
