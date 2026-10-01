<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Graphing\Application\Port\GprintPresetAccess;
use Kadupul\Graphing\Infrastructure\Legacy\LegacyGprintPresetStore;
use Kadupul\IdentityAccess\Contract\AuditTrail;
use Kadupul\IdentityAccess\Contract\AuditEvent;
use Kadupul\Platform\Contract\DatabaseConnection;
use Kadupul\Platform\Contract\LegacyConfiguration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GprintPresetFailureHandoffTest extends TestCase
{
    #[DataProvider('preflightFailures')]
    public function testFalseStoragePreconditionsNeverBeginMutation(string $failure): void
    {
        $db = $this->createMock(\PDO::class);
        $db->method('inTransaction')->willReturn(false);
        $db->method('getAttribute')->with(\PDO::ATTR_DRIVER_NAME)->willReturn('mysql');
        $db->expects(self::never())->method('beginTransaction');
        $access = $this->createMock(GprintPresetAccess::class);
        $access->expects(self::never())->method('assertCurrent');
        if ($failure === 'query') {
            $db->expects(self::once())->method('query')->willReturn(false);
            $db->expects(self::never())->method('exec');
            $message = 'GPRINT mutations require InnoDB tables: graph_templates_gprint';
        } else {
            $statement = $this->createMock(\PDOStatement::class);
            $statement->method('fetch')->willReturn(['table', 'CREATE TABLE `table` (id INT) ENGINE=InnoDB']);
            $db->expects(self::exactly(8))->method('query')->willReturn($statement);
            $db->expects(self::once())->method('exec')
                ->with('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ')->willReturn(false);
            $message = 'GPRINT transaction isolation could not be confirmed.';
        }
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage($message);
        $this->store($db, $access)->delete(42, [1], [1 => str_repeat('0', 64)]);
    }

    public static function preflightFailures(): array
    {
        return [['query'], ['isolation']];
    }

    #[DataProvider('rollbackFailures')]
    public function testUnconfirmedRollbackCannotBecomeAnOrdinaryValidationFailure(string $action, bool $throws): void
    {
        $db = $this->createMock(\PDO::class);
        $db->method('inTransaction')->willReturnOnConsecutiveCalls(false, true);
        $db->method('getAttribute')->with(\PDO::ATTR_DRIVER_NAME)->willReturn('sqlite');
        $db->expects(self::once())->method('beginTransaction')->willReturn(true);
        $db->expects(self::never())->method('commit');
        $db->expects(self::never())->method('prepare');
        $rollback = $db->expects(self::once())->method('rollBack');
        if ($throws) {
            $rollback->willThrowException(new \RuntimeException('Fixture rollback failure'));
        } else {
            $rollback->willReturn(false);
        }
        $access = $this->createMock(GprintPresetAccess::class);
        $access->method('assertCurrent')->willThrowException(new \InvalidArgumentException('Fixture validation refusal'));
        $store = $this->store($db, $access);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('GPRINT rollback could not be confirmed.');
        if ($action === 'save') {
            $store->save(42, null, 'New preset', '%5.2lf', null);
        } else {
            $store->delete(42, [1], [1 => str_repeat('0', 64)]);
        }
    }

    public static function rollbackFailures(): array
    {
        return [['save', false], ['save', true], ['delete', false], ['delete', true]];
    }

    #[DataProvider('lateReadFailures')]
    public function testLateReadFailuresCannotBecomeMissingRowsOrEmptyCounts(string $read): void
    {
        $count = $read === 'count';
        $db = $this->createMock(\PDO::class);
        $db->method('getAttribute')->with(\PDO::ATTR_DRIVER_NAME)->willReturn('sqlite');
        $db->method('inTransaction')->willReturnOnConsecutiveCalls(false, true);
        $db->expects($count ? self::never() : self::once())->method('beginTransaction')->willReturn(true);
        $db->expects($count ? self::never() : self::once())->method('rollBack')->willReturn(true);
        $db->expects(self::never())->method('commit');
        $statement = $this->createMock(\PDOStatement::class);
        $statement->expects(self::once())->method('execute')->willReturn(true);
        $failed = false;
        // The driver succeeds at execution and reports connection loss during
        // the public operation's subsequent fetch, rather than an empty result.
        $statement->method('errorCode')->willReturnCallback(static function () use (&$failed): string {
            return $failed ? '08006' : '00000';
        });
        $method = match ($read) {
            'row' => 'fetch', 'batch' => 'fetchAll', default => 'fetchColumn'
        };
        $statement->expects(self::once())->method($method)->willReturnCallback(static function () use (&$failed, $read): array|false {
            $failed = true;
            return $read === 'batch' ? [] : false;
        });
        $db->expects(self::once())->method('prepare')->willReturn($statement);
        $access = $this->createMock(GprintPresetAccess::class);
        $access->expects($count ? self::never() : self::once())->method('assertCurrent');
        $audit = $this->createMock(AuditTrail::class);
        $audit->expects($count ? self::never() : self::once())->method('record')
            ->with(self::callback(static fn(AuditEvent $event): bool => $event->outcome === AuditEvent::FAILED));
        $store = $this->store($db, $access, $audit);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('GPRINT database result could not be confirmed.');
        match ($read) {
            'row' => $store->save(42, 1, 'Preset', '%6.2lf', str_repeat('0', 64)),
            'batch' => $store->delete(42, [1], [1 => str_repeat('0', 64)]),
            default => $store->list(\Kadupul\Graphing\Domain\GprintPresetFilters::fromQuery([], 25)),
        };
    }

    public static function lateReadFailures(): array
    {
        return [['row'], ['batch'], ['count']];
    }

    private function store(\PDO $db, GprintPresetAccess $access, ?AuditTrail $audit = null): LegacyGprintPresetStore
    {
        $connection = $this->createMock(DatabaseConnection::class);
        $connection->method('get')->willReturn($db);
        $configuration = $this->createMock(LegacyConfiguration::class);
        $configuration->method('values')->willReturn(['collector_id' => 1]);
        return new LegacyGprintPresetStore($connection, $access, $audit ?? $this->createMock(AuditTrail::class), $configuration);
    }
}
