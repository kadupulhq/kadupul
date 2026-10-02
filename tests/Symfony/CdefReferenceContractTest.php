<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Platform\Infrastructure\Legacy\CdefReferenceContract;
use PHPUnit\Framework\TestCase;

final class CdefReferenceContractTest extends TestCase
{
    public function testSchemaOperationsPreserveTheCallerTransactionAndWrites(): void
    {
        $database = new \PDO('sqlite::memory:');
        $database->exec('CREATE TABLE sentinel (id INTEGER PRIMARY KEY)');
        $database->beginTransaction();
        $database->exec('INSERT INTO sentinel VALUES (1)');
        foreach (['ready', 'install', 'preflight'] as $method) {
            try {
                (new CdefReferenceContract($database, 1))->{$method}();
                self::fail('Caller transaction was accepted by ' . $method);
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('caller transaction', $error->getMessage());
            }
            self::assertTrue($database->inTransaction());
            self::assertSame(1, (int) $database->query('SELECT COUNT(*) FROM sentinel')->fetchColumn());
        }
        $database->commit();
        self::assertSame(1, (int) $database->query('SELECT COUNT(*) FROM sentinel')->fetchColumn());
    }

    public function testPrimaryRoleMustBeExplicitAndTypedBeforeAnySchemaAccess(): void
    {
        $database = new \PDO('sqlite::memory:');
        foreach ([null, false, true, '1', 0, 2, 1.0] as $role) {
            try {
                (new CdefReferenceContract($database, $role))->install();
                self::fail('Unestablished primary role accepted.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('explicitly configured primary collector', $error->getMessage());
            }
            self::assertFalse($database->inTransaction());
            self::assertSame(0, (int) $database->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table'")->fetchColumn());
        }
    }

    public function testUnsupportedNativeDriverFailsWithoutChangingErrorMode(): void
    {
        $database = new \PDO('sqlite::memory:', options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_SILENT]);
        try {
            (new CdefReferenceContract($database, 1))->install();
            self::fail('SQLite schema installation accepted.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('native MySQL or MariaDB', $error->getMessage());
        }
        self::assertSame(\PDO::ERRMODE_SILENT, $database->getAttribute(\PDO::ATTR_ERRMODE));
        self::assertFalse($database->inTransaction());
    }
}
