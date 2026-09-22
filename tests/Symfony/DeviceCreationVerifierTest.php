<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Inventory\Infrastructure\Legacy\DeviceCreationVerifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DeviceCreationVerifierTest extends TestCase
{
    private function database(): \PDO
    {
        $database = new \PDO('sqlite::memory:');
        $database->exec('CREATE TABLE host (id INTEGER PRIMARY KEY, description TEXT)');
        $database->exec("INSERT INTO host VALUES (7, 'Device')");
        $database->exec('CREATE TABLE host_template_graph (host_template_id INTEGER, graph_template_id INTEGER)');
        $database->exec('CREATE TABLE host_template_snmp_query (host_template_id INTEGER, snmp_query_id INTEGER)');
        $database->exec('CREATE TABLE host_graph (host_id INTEGER, graph_template_id INTEGER)');
        $database->exec('CREATE TABLE host_snmp_query (host_id INTEGER, snmp_query_id INTEGER, reindex_method INTEGER)');
        $database->exec('INSERT INTO host_template_graph VALUES (3, 11)');
        $database->exec('INSERT INTO host_template_snmp_query VALUES (3, 12)');
        $database->exec('INSERT INTO host_graph VALUES (7, 11)');
        $database->exec('INSERT INTO host_snmp_query VALUES (7, 12, 1)');

        return $database;
    }

    public function testCurrentAssociationVerificationRequiresAnOwningTransaction(): void
    {
        $this->expectException(\LogicException::class);
        (new DeviceCreationVerifier())->verify($this->database(), null, 7, 3, true);
    }

    public function testCompleteLocalAndRemoteCreationPasses(): void
    {
        $primary = $this->database();
        $verifier = new DeviceCreationVerifier();
        $verifier->verify($primary, null, 7, 3);
        $verifier->verify($primary, $this->database(), 7, 3);
        $this->addToAssertionCount(2);
    }

    public static function incompleteWrites(): array
    {
        return [
            ['remote', 'DELETE FROM host_graph'],
            ['remote', 'DELETE FROM host_snmp_query'],
            ['remote', 'UPDATE host_snmp_query SET reindex_method = 2'],
            ['primary', 'DELETE FROM host_graph'],
            ['primary', 'DELETE FROM host_snmp_query'],
            ['both', 'DELETE FROM host_graph'],
            ['both', 'DELETE FROM host_snmp_query'],
        ];
    }

    #[DataProvider('incompleteWrites')]
    public function testIncompleteAssociationsCannotReachCommit(string $target, string $sql): void
    {
        $primary = $this->database();
        $remote = $this->database();
        $primary->beginTransaction();
        if ($target !== 'remote') {
            $primary->exec($sql);
        }
        if ($target !== 'primary') {
            $remote->exec($sql);
        }
        // The old host-only comparison still succeeds in each fault case.
        self::assertSame($primary->query('SELECT * FROM host')->fetchAll(), $remote->query('SELECT * FROM host')->fetchAll());
        try {
            (new DeviceCreationVerifier())->verify($primary, $remote, 7, 3);
            self::fail('Incomplete creation must not reach commit');
        } catch (\RuntimeException) {
            self::assertTrue($primary->inTransaction());
        } finally {
            $primary->rollBack();
        }
        // Primary rollback does not undo independently committed remote changes.
        if ($target !== 'primary' && str_starts_with($sql, 'DELETE')) {
            $table = substr($sql, strlen('DELETE FROM '));
            self::assertSame(0, (int) $remote->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn());
        }
    }

    public function testSilentDatabaseErrorsFailClosed(): void
    {
        $primary = $this->database();
        $remote = $this->database();
        $remote->exec('DROP TABLE host_graph');
        $remote->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_SILENT);
        $this->expectException(\RuntimeException::class);
        (new DeviceCreationVerifier())->verify($primary, $remote, 7, 3);
    }

    public function testLocalMissingAssociationFailsWithoutACollector(): void
    {
        $primary = $this->database();
        $primary->exec('DELETE FROM host_graph');
        $this->expectException(\RuntimeException::class);
        (new DeviceCreationVerifier())->verify($primary, null, 7, 3);
    }

    public function testCreationWithoutATemplateAllowsEmptyAssociations(): void
    {
        $primary = $this->database();
        $remote = $this->database();
        foreach ([$primary, $remote] as $database) {
            $database->exec('DELETE FROM host_graph');
            $database->exec('DELETE FROM host_snmp_query');
        }
        (new DeviceCreationVerifier())->verify($primary, $remote, 7, 0);
        $this->addToAssertionCount(1);
    }

    public function testUnrelatedDevicesAndTemplatesAreNotCompared(): void
    {
        $primary = $this->database();
        $remote = $this->database();
        $primary->exec('INSERT INTO host_template_graph VALUES (4, 99)');
        $remote->exec('INSERT INTO host_graph VALUES (8, 99)');
        (new DeviceCreationVerifier())->verify($primary, $remote, 7, 3);
        $this->addToAssertionCount(1);
    }
}
