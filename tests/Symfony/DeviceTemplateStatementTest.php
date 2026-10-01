<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Inventory\Infrastructure\Legacy\DeviceTemplateAuthorization;
use Kadupul\Inventory\Infrastructure\Legacy\DeviceTemplateStatement;
use Kadupul\Inventory\Infrastructure\Legacy\DeviceTemplateTransaction;
use Kadupul\Inventory\Infrastructure\Legacy\LegacyDeviceTemplateDefinitions;
use Kadupul\Platform\Contract\DatabaseConnection;
use Kadupul\Platform\Contract\LegacyConfiguration;
use PHPUnit\Framework\TestCase;

final class DeviceTemplateStatementTest extends TestCase
{
    private function definitions(\PDO $db): LegacyDeviceTemplateDefinitions
    {
        $database = $this->createMock(DatabaseConnection::class);
        $database->method('get')->willReturn($db);
        $configuration = $this->createMock(LegacyConfiguration::class);
        $configuration->method('values')->willReturn(['collector_id' => 1]);
        return new LegacyDeviceTemplateDefinitions($database, dirname(__DIR__, 2), $configuration);
    }

    private function lateFailure(string $method, mixed $result): \PDOStatement
    {
        $failed = false;
        $statement = $this->createMock(\PDOStatement::class);
        $statement->method('execute')->willReturn(true);
        $statement->method($method)->willReturnCallback(static function () use (&$failed, $result): mixed {
            $failed = true;
            return $result;
        });
        $statement->method('errorCode')->willReturnCallback(static function () use (&$failed): string {
            return $failed ? 'HY000' : '00000';
        });
        return $statement;
    }

    public function testLateFetchCannotBecomeEmptyDefaults(): void
    {
        $db = $this->createMock(\PDO::class);
        $db->method('query')->willReturn($this->lateFailure('fetchAll', []));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Device template database operation was not confirmed.');
        $this->definitions($db)->defaults(42, true);
    }

    public function testLateFetchCannotBecomeMissingParent(): void
    {
        $db = $this->createMock(\PDO::class);
        $db->method('prepare')->willReturn($this->lateFailure('fetch', false));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Device template database operation was not confirmed.');
        LegacyDeviceTemplateDefinitions::read($db, 7);
    }

    public function testLateFetchCannotBecomeEmptyChildrenAndValidRevision(): void
    {
        $parent = $this->createMock(\PDOStatement::class);
        $parent->method('execute')->willReturn(true);
        $parent->method('errorCode')->willReturn('00000');
        $parent->method('fetch')->willReturn(['id' => 7, 'name' => 'parent', 'class' => 'router']);
        $child = $this->lateFailure('fetchAll', []);
        $db = $this->createMock(\PDO::class);
        $db->method('prepare')->willReturnOnConsecutiveCalls($parent, $child, $child);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Device template database operation was not confirmed.');
        LegacyDeviceTemplateDefinitions::read($db, 7);
    }

    public function testLateFetchCannotBecomeEmptyCatalog(): void
    {
        $db = $this->createMock(\PDO::class);
        $db->method('prepare')->willReturn($this->lateFailure('fetchAll', []));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Device template database operation was not confirmed.');
        $this->definitions($db)->list(\Kadupul\Inventory\Infrastructure\Symfony\DeviceTemplateFilters::parse([]));
    }

    public function testLateFetchCannotBecomeEmptyChoices(): void
    {
        $db = $this->createMock(\PDO::class);
        $db->method('query')->willReturn($this->lateFailure('fetchAll', []));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Device template database operation was not confirmed.');
        $this->definitions($db)->choices();
    }

    public function testLateScalarFetchCannotBecomeLegitimateAbsence(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Device template database operation was not confirmed.');
        DeviceTemplateStatement::fetchColumn($this->lateFailure('fetchColumn', false));
    }

    public function testGeneratedIdsMustBeConfirmedAndWithinStorageBounds(): void
    {
        foreach ([false, '0', '-1', '7.5', '1x', '16777216'] as $id) {
            $db = $this->createMock(\PDO::class);
            $db->method('lastInsertId')->willReturn($id);
            $db->method('errorCode')->willReturn('00000');
            try {
                DeviceTemplateStatement::insertedId($db);
                self::fail('Invalid generated ID accepted.');
            } catch (\RuntimeException $error) {
                self::assertSame('Device template database operation was not confirmed.', $error->getMessage());
            }
        }
        foreach (['00000', 'HY000'] as $state) {
            $db = $this->createMock(\PDO::class);
            $db->method('lastInsertId')->willReturn('7');
            $db->method('errorCode')->willReturn($state);
            if ($state === '00000') {
                self::assertSame(7, DeviceTemplateStatement::insertedId($db));
            } else {
                try {
                    DeviceTemplateStatement::insertedId($db);
                    self::fail('Unconfirmed generated ID accepted.');
                } catch (\RuntimeException $error) {
                    self::assertSame('Device template database operation was not confirmed.', $error->getMessage());
                }
            }
        }
    }

    public function testLateAuthorizationSettingsFetchCannotGrantAccess(): void
    {
        $permissions = $this->createMock(\PDOStatement::class);
        $permissions->method('execute')->willReturn(true);
        $permissions->method('errorCode')->willReturn('00000');
        $permissions->method('fetch')->willReturn(['id' => 42, 'username' => 'operator', 'enabled' => 'on', 'locked' => '', 'must_change_password' => '']);
        $permissions->method('fetchColumn')->willReturn(8);
        $db = $this->createMock(\PDO::class);
        $db->method('query')->willReturn($this->lateFailure('fetchAll', []));
        $db->method('prepare')->willReturn($permissions);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Device template database operation was not confirmed.');
        DeviceTemplateAuthorization::authorize($db, 42);
    }

    public function testLateEngineMetadataFetchCannotBeginTransaction(): void
    {
        $db = $this->createMock(\PDO::class);
        $db->method('getAttribute')->willReturn('mysql');
        $db->method('inTransaction')->willReturn(false);
        $db->method('errorCode')->willReturn('00000');
        $db->method('query')->willReturn($this->lateFailure('fetch', ['table', "CREATE TABLE table (\n id INT\n) ENGINE=InnoDB"]));
        $db->method('exec')->willReturn(0);
        $db->expects(self::never())->method('beginTransaction');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Storage inspection was not confirmed.');
        DeviceTemplateTransaction::begin($db, ['collector_id' => 1], []);
    }

    public function testStatementFailuresAndLegitimateEndOfResultRemainDistinct(): void
    {
        $db = $this->createMock(\PDO::class);
        $db->method('query')->willReturn(false);
        $db->method('prepare')->willReturn(false);
        foreach (['query', 'prepare'] as $operation) {
            try {
                DeviceTemplateStatement::$operation($db, 'SELECT missing');
                self::fail('False statement accepted.');
            } catch (\RuntimeException $error) {
                self::assertSame('Device template database operation was not confirmed.', $error->getMessage());
            }
        }
        foreach ([false, true] as $executed) {
            $statement = $this->createMock(\PDOStatement::class);
            $statement->method('execute')->willReturn($executed);
            $statement->method('errorCode')->willReturn('HY000');
            try {
                DeviceTemplateStatement::execute($statement, []);
                self::fail('Unconfirmed execution accepted.');
            } catch (\RuntimeException $error) {
                self::assertSame('Device template database operation was not confirmed.', $error->getMessage());
            }
        }
        $valid = $this->createMock(\PDOStatement::class);
        $valid->method('errorCode')->willReturn('00000');
        $valid->method('fetch')->willReturn(false);
        $valid->method('fetchColumn')->willReturn(false);
        $valid->method('fetchAll')->willReturn([]);
        self::assertFalse(DeviceTemplateStatement::fetch($valid, \PDO::FETCH_ASSOC));
        self::assertFalse(DeviceTemplateStatement::fetchColumn($valid));
        self::assertSame([], DeviceTemplateStatement::fetchAll($valid, \PDO::FETCH_ASSOC));
    }
}
