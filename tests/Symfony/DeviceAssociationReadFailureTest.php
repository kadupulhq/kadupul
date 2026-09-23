<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Inventory\Infrastructure\Legacy\DeviceAssociationRecords;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DeviceAssociationReadFailureTest extends TestCase
{
    #[DataProvider('failures')]
    public function testReadFailuresNeverBecomeEmptySnapshots(string $kind, string $failure): void
    {
        $db = $this->createMock(\PDO::class);
        $statement = $this->createMock(\PDOStatement::class);
        $db->expects(self::once())->method('prepare')->willReturn($failure === 'prepare' ? false : $statement);
        if ($failure !== 'prepare') {
            $statement->expects(self::once())->method('execute')->with([7])->willReturn($failure !== 'execute');
            if ($failure !== 'execute') {
                $statement->expects(self::once())->method('fetchAll')->willReturn([]);
                $statement->method('errorCode')->willReturn('HY000');
            } else {
                $statement->expects(self::never())->method('fetchAll');
            }
        }
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Association snapshot unavailable');
        (new DeviceAssociationRecords())->snapshot($db, ['id' => 7], $kind);
    }

    public static function failures(): iterable
    {
        foreach (['query', 'graph'] as $kind) {
            foreach (['prepare', 'execute', 'fetch'] as $failure) {
                yield "$kind $failure" => [$kind, $failure];
            }
        }
    }

    public function testSuccessfulEmptyReadsRemainValid(): void
    {
        $db = new \PDO('sqlite::memory:');
        $db->exec('CREATE TABLE host_snmp_query (host_id INTEGER, snmp_query_id INTEGER, reindex_method INTEGER); CREATE TABLE snmp_query (id INTEGER, name TEXT); CREATE TABLE host_graph (host_id INTEGER, graph_template_id INTEGER); CREATE TABLE graph_templates (id INTEGER, name TEXT)');
        $row = ['id' => 7, 'description' => 'fixture', 'site_id' => 0, 'poller_id' => 1, 'host_template_id' => 0, 'snmp_version' => 2];
        foreach (['query', 'graph'] as $kind) {
            $snapshot = (new DeviceAssociationRecords())->snapshot($db, $row, $kind);
            self::assertSame(7, $snapshot->id);
        }
    }
}
