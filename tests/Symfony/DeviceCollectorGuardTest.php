<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Inventory\Infrastructure\Legacy\DeviceCollectorGuard;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

final class DeviceCollectorGuardTest extends TestCase
{
    public function testTransactionSetupVerifiesMariaDbCurrentReadSemanticsAndLeavesMysqlAlone(): void
    {
        foreach ([false, ['Variable_name' => 'innodb_snapshot_isolation', 'Value' => 'ON']] as $initial) {
            $db = $this->createMock(PDO::class);
            $db->method('inTransaction')->willReturn(false);
            $first = $this->createMock(PDOStatement::class);
            $first->method('fetch')->willReturn($initial);
            $first->method('errorCode')->willReturn('00000');
            $second = $this->createMock(PDOStatement::class);
            $second->method('fetch')->willReturn(['Value' => 'OFF']);
            $second->method('errorCode')->willReturn('00000');
            $db->expects(self::exactly($initial === false ? 1 : 2))->method('query')->with("SHOW SESSION VARIABLES LIKE 'innodb_snapshot_isolation'")->willReturnOnConsecutiveCalls($first, $second);
            $db->expects($initial === false ? self::never() : self::once())->method('exec')->with('SET SESSION innodb_snapshot_isolation = OFF')->willReturn(0);
            DeviceCollectorGuard::prepareTransaction($db);
        }
    }

    public function testTransactionSetupRejectsActiveTransactionAndUnconfirmedConfiguration(): void
    {
        foreach (['active', 'query', 'fetch-error', 'set', 'verify'] as $failure) {
            $db = $this->createMock(PDO::class);
            $db->method('inTransaction')->willReturn($failure === 'active');
            $first = $this->createMock(PDOStatement::class);
            $first->method('fetch')->willReturn(['Value' => 'ON']);
            $first->method('errorCode')->willReturn($failure === 'fetch-error' ? 'HY000' : '00000');
            $second = $this->createMock(PDOStatement::class);
            $second->method('fetch')->willReturn(['Value' => 'ON']);
            $second->method('errorCode')->willReturn('00000');
            $db->method('query')->willReturnOnConsecutiveCalls($failure === 'query' ? false : $first, $second);
            $db->method('exec')->willReturn($failure === 'set' ? false : 0);
            try {
                DeviceCollectorGuard::prepareTransaction($db);
                self::fail('Unconfirmed transaction configuration accepted');
            } catch (\LogicException|\RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    private function statement(array|false $row): PDOStatement
    {
        $query = $this->createMock(PDOStatement::class);
        $query->method('execute')->with([3])->willReturn(true);
        $query->method('fetch')->with(PDO::FETCH_ASSOC)->willReturn($row);
        $query->method('errorCode')->willReturn('00000');
        return $query;
    }

    public function testHeartbeatProgressIsAcceptedAndFinalReadLocksCurrentConfiguration(): void
    {
        $db = $this->createMock(PDO::class);
        $db->method('inTransaction')->willReturn(true);
        $calls = [];
        $row = ['id' => 3, 'disabled' => '', 'dbhost' => 'collector.invalid', 'heartbeat_age' => 10];
        $statements = [$this->statement($row), $this->statement(array_replace($row, ['heartbeat_age' => 0]))];
        $db->method('prepare')->willReturnCallback(static function (string $sql) use (&$calls, &$statements): PDOStatement {
            $calls[] = $sql;
            return array_shift($statements);
        });
        $guard = DeviceCollectorGuard::capture($db, 3, 600);
        $guard->assertCurrent();
        self::assertStringNotContainsString('FOR UPDATE', $calls[0]);
        self::assertStringContainsString('FOR UPDATE', $calls[1]);
    }

    public function testCurrentMetadataRemovalDisablementAndLostHeartbeatAreRejected(): void
    {
        $row = ['id' => 3, 'disabled' => '', 'dbhost' => 'collector.invalid', 'heartbeat_age' => 0];
        foreach ([false, array_replace($row, ['disabled' => 'on']), array_replace($row, ['heartbeat_age' => 600]), array_replace($row, ['dbhost' => 'changed.invalid'])] as $after) {
            $db = $this->createMock(PDO::class);
            $db->method('inTransaction')->willReturn(true);
            $db->method('prepare')->willReturnOnConsecutiveCalls($this->statement($row), $this->statement($after));
            $guard = DeviceCollectorGuard::capture($db, 3, 600);
            try {
                $guard->assertCurrent();
                self::fail('Changed or unavailable collector was accepted');
            } catch (\RuntimeException $error) {
                self::assertStringNotContainsString('changed.invalid', $error->getMessage());
            }
        }
    }

    public function testUnconfirmedDriverReadsCannotEstablishACollectorSnapshot(): void
    {
        foreach (['prepare', 'execute', 'fetch', 'late-error'] as $stage) {
            $db = $this->createMock(PDO::class);
            $db->method('inTransaction')->willReturn(true);
            $query = $this->createMock(PDOStatement::class);
            $query->method('execute')->willReturn($stage !== 'execute');
            $query->method('fetch')->willReturn($stage === 'fetch' ? false : ['disabled' => '', 'heartbeat_age' => 0]);
            $query->method('errorCode')->willReturn($stage === 'late-error' ? 'HY000' : '00000');
            $db->method('prepare')->willReturn($stage === 'prepare' ? false : $query);
            try {
                DeviceCollectorGuard::capture($db, 3);
                self::fail('Unconfirmed collector read succeeded');
            } catch (\RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testCaptureRequiresTheExistingWorkerTransaction(): void
    {
        $db = $this->createMock(PDO::class);
        $db->method('inTransaction')->willReturn(false);
        $db->expects(self::never())->method('prepare');
        $this->expectException(\LogicException::class);
        DeviceCollectorGuard::capture($db, 3);
    }
}
