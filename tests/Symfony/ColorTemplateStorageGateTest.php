<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\ColorTemplates\Application\Port\ColorTemplateAccess;
use Kadupul\ColorTemplates\Infrastructure\Legacy\LegacyColorTemplateStore;
use Kadupul\IdentityAccess\Contract\AuditTrail;
use Kadupul\Platform\Contract\DatabaseConnection;
use Kadupul\Platform\Contract\LegacyConfiguration;
use PHPUnit\Framework\TestCase;

final class ColorTemplateStorageGateTest extends TestCase
{
    /** @dataProvider invalidCollectors */
    public function testRejectsRemoteOrMissingCollectorBeforeStartingTransaction(?int $collector): void
    {
        $db = $this->database();
        $db->expects(self::never())->method('prepare');
        $db->expects(self::never())->method('query');
        $db->expects(self::never())->method('exec');
        $this->expectExceptionMessage('primary collector');
        $this->store($db, $collector)->saveTemplate(42, null, 'Storage guard', null);
    }

    public static function invalidCollectors(): array
    {
        return [[null], [0], [2]];
    }

    /** @dataProvider requiredTables */
    public function testRejectsEachNontransactionalDependencyBeforeAnyWrite(string $invalidTable): void
    {
        $db = $this->database();
        $current = '';
        $query = $this->createMock(\PDOStatement::class);
        $query->method('errorCode')->willReturn('00000');
        $query->method('fetch')->with(\PDO::FETCH_NUM)->willReturnCallback(static function () use (&$current, $invalidTable): array {
            return [$current, "CREATE TABLE `{$current}` (\n `id` bigint\n) ENGINE=" . ($current === $invalidTable ? 'MyISAM' : 'InnoDB')];
        });
        $db->method('query')->willReturnCallback(static function (string $sql) use (&$current, $query): \PDOStatement {
            preg_match('/\ASHOW CREATE TABLE `([a-z_]+)`\z/', $sql, $matches);
            self::assertNotEmpty($matches);
            $current = $matches[1];
            return $query;
        });
        $db->expects(self::never())->method('exec');
        $this->expectExceptionMessage('transactional tables');
        $this->store($db)->saveTemplate(42, null, 'Storage guard', null);
    }

    public static function requiredTables(): array
    {
        return array_map(static fn(string $table): array => [$table], ['color_templates', 'color_template_items', 'colors', 'aggregate_graphs_graph_item', 'aggregate_graph_templates_item', 'user_auth', 'user_auth_realm', 'user_auth_group', 'user_auth_group_realm', 'user_auth_group_members', 'settings']);
    }

    public function testMissingStorageMetadataRefusesRatherThanAssumingAnEngine(): void
    {
        $db = $this->database();
        $query = $this->createMock(\PDOStatement::class);
        $query->method('errorCode')->willReturn('00000');
        $query->method('fetch')->willReturn(false);
        $db->method('query')->willReturn($query);
        $db->expects(self::never())->method('exec');
        $this->expectExceptionMessage('transactional tables');
        $this->store($db)->saveTemplate(42, null, 'Storage guard', null);
    }

    public function testStorageQueryFailureRefusesBeforeStartingTransaction(): void
    {
        $db = $this->database();
        $db->method('query')->willReturn(false);
        $db->expects(self::never())->method('exec');
        $this->expectExceptionMessage('could not be verified');
        $this->store($db)->saveTemplate(42, null, 'Storage guard', null);
    }

    public function testIsolationFailureRefusesBeforeStartingTransaction(): void
    {
        $db = $this->database();
        $query = $this->createMock(\PDOStatement::class);
        $query->method('errorCode')->willReturn('00000');
        $query->method('fetch')->willReturn(['fixture', "CREATE TABLE `fixture` (\n `id` bigint\n) ENGINE=InnoDB"]);
        $db->method('query')->willReturn($query);
        $db->expects(self::once())->method('exec')->with('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ')->willReturn(false);
        $this->expectExceptionMessage('isolation could not be confirmed');
        $this->store($db)->saveTemplate(42, null, 'Storage guard', null);
    }

    public function testLateStorageMetadataFailureRefusesBeforeStartingTransaction(): void
    {
        $db = $this->database();
        $query = $this->createMock(\PDOStatement::class);
        $query->method('fetch')->willReturn(['fixture', "CREATE TABLE `fixture` (\n `id` bigint\n) ENGINE=InnoDB"]);
        $query->method('errorCode')->willReturn('08006');
        $db->method('query')->willReturn($query);
        $db->expects(self::never())->method('exec');
        $this->expectExceptionMessage('database result could not be confirmed');
        $this->store($db)->saveTemplate(42, null, 'Storage guard', null);
    }

    private function database(): \PDO
    {
        $db = $this->createMock(\PDO::class);
        $db->method('getAttribute')->with(\PDO::ATTR_DRIVER_NAME)->willReturn('mysql');
        $db->method('inTransaction')->willReturn(false);
        $db->expects(self::never())->method('beginTransaction');
        $db->expects(self::never())->method('commit');
        $db->expects(self::never())->method('rollBack');
        return $db;
    }

    private function store(\PDO $db, ?int $collector = 1): LegacyColorTemplateStore
    {
        $connection = $this->createMock(DatabaseConnection::class);
        $connection->method('get')->willReturn($db);
        $access = $this->createMock(ColorTemplateAccess::class);
        $access->expects(self::never())->method('assertCurrent');
        $configuration = $this->createMock(LegacyConfiguration::class);
        $configuration->method('values')->willReturn(['collector_id' => $collector]);
        return new LegacyColorTemplateStore($connection, $access, $this->createMock(AuditTrail::class), $configuration);
    }
}
