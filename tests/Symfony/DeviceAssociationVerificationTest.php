<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Inventory\Domain\DeviceAssociations;
use Kadupul\Inventory\Domain\DeviceAssociationChange;
use Kadupul\Inventory\Infrastructure\Legacy\DeviceAssociationWriter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DeviceAssociationVerificationTest extends TestCase
{
    private function database(bool $template, bool $mapping): \PDO
    {
        $db = new \PDO('sqlite::memory:');
        $db->exec('CREATE TABLE host (id INTEGER, site_id INTEGER, poller_id INTEGER, host_template_id INTEGER, deleted TEXT)');
        $db->exec("INSERT INTO host VALUES (7, 0, 3, 0, '')");
        $db->exec('CREATE TABLE graph_templates (id INTEGER)');
        $db->exec('CREATE TABLE host_graph (host_id INTEGER, graph_template_id INTEGER)');
        if ($template) {
            $db->exec('INSERT INTO graph_templates VALUES (9)');
        }
        if ($mapping) {
            $db->exec('INSERT INTO host_graph VALUES (7, 9)');
        }
        return $db;
    }

    #[DataProvider('outcomes')]
    public function testAddsRequireCatalogAndRemovalsRejectOrphanMappings(string $operation, bool $template, bool $mapping, bool $remote, bool $accepted): void
    {
        $tested = $this->database($template, $mapping);
        $primary = $remote ? $this->database(true, $operation === 'add') : $tested;
        if (!$accepted) {
            $this->expectException(\RuntimeException::class);
        }
        (new DeviceAssociationWriter())->verify(
            $primary,
            $remote ? $tested : null,
            new DeviceAssociations(7, 'fixture', 0, 3, 0, []),
            new DeviceAssociationChange('graph', $operation, 9)
        );
        if ($accepted) {
            $this->addToAssertionCount(1);
        }
    }

    public function testRemovalUsesCallerTransactionsOnBothDatabases(): void
    {
        $primary = $this->database(true, true);
        $remote = $this->database(true, true);
        $primary->beginTransaction();
        $remote->beginTransaction();
        $writer = new DeviceAssociationWriter();
        $device = new DeviceAssociations(7, 'fixture', 0, 3, 0, []);
        $change = new DeviceAssociationChange('graph', 'remove', 9);
        $writer->apply($primary, $remote, $device, $change);
        $writer->verify($primary, $remote, $device, $change);
        foreach ([$primary, $remote] as $db) {
            self::assertTrue($db->inTransaction());
            $db->rollBack();
            self::assertSame(1, (int) $db->query('SELECT COUNT(*) FROM host_graph')->fetchColumn());
        }
    }

    #[DataProvider('failedQueryChecks')]
    public function testSilentReadFailuresCannotConfirmQueryRemoval(string $table, bool $remote): void
    {
        $valid = $this->database(false, false);
        $valid->exec('CREATE TABLE host_snmp_query (host_id INTEGER, snmp_query_id INTEGER, reindex_method INTEGER); CREATE TABLE host_snmp_cache (host_id INTEGER, snmp_query_id INTEGER); CREATE TABLE poller_reindex (host_id INTEGER, data_query_id INTEGER)');
        $failed = $this->createMock(\PDOStatement::class);
        $failed->method('execute')->willReturn(false);
        $failed->expects(self::never())->method('fetchColumn');
        $db = $this->createMock(\PDO::class);
        $db->method('prepare')->willReturnCallback(static fn(string $sql): \PDOStatement => str_contains($sql, 'FROM ' . $table . ' ') ? $failed : $valid->prepare($sql));
        $this->expectException(\RuntimeException::class);
        (new DeviceAssociationWriter())->verify($remote ? $valid : $db, $remote ? $db : null, new DeviceAssociations(7, 'fixture', 0, 3, 0, []), new DeviceAssociationChange('query', 'remove', 9));
    }

    public static function failedQueryChecks(): iterable
    {
        foreach (['host_snmp_query', 'host_snmp_cache', 'poller_reindex'] as $table) {
            foreach ([false, true] as $remote) {
                yield [$table, $remote];
            }
        }
    }

    public static function outcomes(): iterable
    {
        foreach ([false, true] as $remote) {
            $side = $remote ? 'remote' : 'primary';
            yield "$side orphaned mapping must not confirm removal" => ['remove', false, true, $remote, false];
            yield "$side remaining mapping must not confirm removal" => ['remove', true, true, $remote, false];
            yield "$side removed orphan confirms removal" => ['remove', false, false, $remote, true];
            yield "$side valid addition" => ['add', true, true, $remote, true];
            yield "$side addition requires catalog entry" => ['add', false, true, $remote, false];
            yield "$side addition requires mapping" => ['add', true, false, $remote, false];
        }
    }
}
