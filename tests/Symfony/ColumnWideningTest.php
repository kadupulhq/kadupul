<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\AuditEvent;
use Kadupul\IdentityAccess\Contract\AuditTrail;
use Kadupul\IdentityAccess\Contract\ConsoleOperator;
use Kadupul\Platform\Application\Command\MaintenanceTarget;
use Kadupul\Platform\Application\Command\SchemaChangeAudit;
use Kadupul\Platform\Application\Command\WidenIdColumns;
use Kadupul\Platform\Application\Port\ColumnCatalog;
use Kadupul\Platform\Application\Port\DatabaseMaintenance;
use Kadupul\Platform\Application\Port\DatabaseTarget;
use Kadupul\Platform\Application\ReadModel\WideningEvent;
use Kadupul\Platform\Domain\Schema\ColumnChange;
use Kadupul\Platform\Domain\Schema\ColumnDefinition;
use Kadupul\Platform\Domain\Schema\IdColumns;
use Kadupul\Platform\Infrastructure\Legacy\LegacyOperatorLog;
use Kadupul\Platform\Infrastructure\Persistence\DbalColumnWidening;
use Kadupul\Platform\Infrastructure\Persistence\MaintenanceConnections;
use Kadupul\Tests\Fixtures\RealMariaDb;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Filesystem\Filesystem;

final class ColumnWideningTest extends TestCase
{
    use RealMariaDb;

    private const string PROBE = 'kadupul_widen_probe';
    private const string HOSTILE = 'we`ird; DROP TABLE kadupul_widen_probe; --';

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/kadupul-widen-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/log', 0700, true);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->root);
    }

    #[DataProvider('types')]
    public function testOnlyNarrowIntegersNeedWidening(string $type, bool $expected): void
    {
        self::assertSame($expected, (new ColumnDefinition('c', $type, false, null, ''))->needsWidening());
    }

    /** @return iterable<string, array{string, bool}> */
    public static function types(): iterable
    {
        yield 'mediumint' => ['mediumint(8) unsigned', true];
        yield 'upper-case mediumint' => ['MEDIUMINT(8) UNSIGNED', true];
        yield 'smallint' => ['smallint(5) unsigned', true];
        yield 'tinyint' => ['tinyint(4)', true];
        yield 'signed int' => ['int(11)', true];
        yield 'int unsigned' => ['int(10) unsigned', false];
        yield 'MySQL 8 int unsigned' => ['int unsigned', false];
        yield 'zerofill' => ['int(10) unsigned zerofill', false];
        yield 'bigint is never narrowed' => ['bigint(20) unsigned', false];
        yield 'text is left alone' => ['varchar(20)', false];
        yield 'a name that only starts like an integer' => ['integerish', false];
    }

    public function testTheChangeKeepsNullabilityAndDefault(): void
    {
        self::assertEquals(new ColumnChange('id', true, false, null), (new ColumnDefinition('id', 'mediumint(8) unsigned', false, null, 'auto_increment'))->change());
        self::assertEquals(new ColumnChange('a', false, false, '0'), (new ColumnDefinition('a', 'mediumint(8) unsigned', false, '0', ''))->change());
        self::assertEquals(new ColumnChange('b', false, true, '5'), (new ColumnDefinition('b', 'int(11)', true, '5', ''))->change());
        // SHOW COLUMNS reports an empty default as ''; the original treated it as none.
        self::assertEquals(new ColumnChange('c', false, false, null), (new ColumnDefinition('c', 'smallint(5)', false, '', ''))->change());
        self::assertTrue((new ColumnDefinition('id', 'mediumint(8) unsigned', false, null, 'auto_increment, INVISIBLE'))->change()->autoIncrement);
        self::assertTrue((new ColumnDefinition('id', 'mediumint(8) unsigned', false, null, 'AUTO_INCREMENT'))->change()->autoIncrement);
    }

    #[DataProvider('extras')]
    public function testGeneratedAndInvisibleColumnsAreNotChangeable(string $extra, bool $expected): void
    {
        self::assertSame($expected, (new ColumnDefinition('c', 'mediumint(8) unsigned', false, null, $extra))->changeable());
    }

    /** @return iterable<string, array{string, bool}> */
    public static function extras(): iterable
    {
        yield 'plain' => ['', true];
        yield 'auto-increment' => ['auto_increment', true];
        yield 'MariaDB virtual' => ['VIRTUAL GENERATED', false];
        yield 'MariaDB stored' => ['STORED GENERATED', false];
        yield 'MySQL 8 expression default' => ['DEFAULT_GENERATED', false];
        yield 'invisible' => ['INVISIBLE', false];
        yield 'invisible auto-increment' => ['auto_increment, INVISIBLE', false];
    }

    public function testSameAsComparesEveryAttribute(): void
    {
        $column = new ColumnDefinition('c', 'mediumint(8) unsigned', false, '0', '');
        self::assertTrue($column->sameAs(new ColumnDefinition('c', 'mediumint(8) unsigned', false, '0', '')));
        foreach ([
            new ColumnDefinition('C', 'mediumint(8) unsigned', false, '0', ''),
            new ColumnDefinition('c', 'int(10) unsigned', false, '0', ''),
            new ColumnDefinition('c', 'mediumint(8) unsigned', true, '0', ''),
            new ColumnDefinition('c', 'mediumint(8) unsigned', false, null, ''),
            new ColumnDefinition('c', 'mediumint(8) unsigned', false, '0', 'INVISIBLE'),
        ] as $other) {
            self::assertFalse($column->sameAs($other));
        }
    }

    public function testTheKnownListIsTheOriginalOne(): void
    {
        self::assertCount(24, IdColumns::KNOWN);
        self::assertSame(['id', 'local_graph_template_item_id', 'local_graph_id', 'task_item_id'], IdColumns::KNOWN['graph_templates_item']);
        self::assertSame(['graph_id', 'data_id'], IdColumns::SHARED);
    }

    public function testTheCatalogMatchesNamesExactly(): void
    {
        $column = new ColumnDefinition('graph_id', 'mediumint(8) unsigned', false, '0', '');
        $catalog = new ColumnCatalog(['b' => [$column], 'a' => [], '123' => [$column]]);
        self::assertSame(['b', 'a', '123'], $catalog->tables());
        self::assertTrue($catalog->has('b'));
        self::assertFalse($catalog->has('B'));
        self::assertSame([$column], $catalog->columns('b'));
        self::assertSame([], $catalog->columns('B'));
    }

    private function adapter(Connection $db): DbalColumnWidening
    {
        return new DbalColumnWidening(new MaintenanceConnections($db, $db, new LegacyOperatorLog($this->root, new Filesystem(), new MockClock())));
    }

    private function log(): string
    {
        return is_file($this->root . '/log/cacti.log') ? (string) file_get_contents($this->root . '/log/cacti.log') : '';
    }

    public function testStatementsQuoteNamesAndDefaults(): void
    {
        // A MariaDB platform without a server: quoting needs no connection.
        $db = DriverManager::getConnection(['driver' => 'pdo_mysql', 'serverVersion' => '11.8.0-MariaDB']);
        self::assertSame(
            'ALTER TABLE `we``ird` MODIFY COLUMN `id` int(10) unsigned NOT NULL AUTO_INCREMENT, '
            . "MODIFY COLUMN `a` int(10) unsigned NOT NULL DEFAULT '0', "
            . "MODIFY COLUMN `b` int(10) unsigned DEFAULT 'it''s\\\\', "
            . 'MODIFY COLUMN `c` int(10) unsigned NOT NULL, '
            . 'MODIFY COLUMN `d` int(10) unsigned DEFAULT NULL',
            $this->adapter($db)->statement(DatabaseTarget::Local, 'we`ird', [
                new ColumnDefinition('id', 'mediumint(8) unsigned', false, null, 'auto_increment'),
                new ColumnDefinition('a', 'mediumint(8) unsigned', false, '0', ''),
                new ColumnDefinition('b', 'int(11)', true, "it's\\", ''),
                new ColumnDefinition('c', 'smallint(5)', false, null, ''),
                new ColumnDefinition('d', 'tinyint(4)', true, null, ''),
            ]),
        );
    }

    public function testAHostileNameStaysOneQuotedIdentifier(): void
    {
        $db = DriverManager::getConnection(['driver' => 'pdo_mysql', 'serverVersion' => '11.8.0-MariaDB']);
        self::assertSame(
            'ALTER TABLE `we``ird; DROP TABLE kadupul_widen_probe; --` MODIFY COLUMN `graph_id``; --` int(10) unsigned NOT NULL',
            $this->adapter($db)->statement(DatabaseTarget::Local, self::HOSTILE, [new ColumnDefinition('graph_id`; --', 'mediumint(8) unsigned', false, null, '')]),
        );
    }

    /** @return list<ColumnDefinition> */
    private static function changes(ColumnCatalog $catalog, string $table): array
    {
        return $catalog->columns($table);
    }

    public function testAgainstARealMariaDb(): void
    {
        $db = $this->realMariaDb();
        try {
            $db->executeStatement('DROP VIEW IF EXISTS kadupul_widen_view');
            $db->executeStatement('DROP TABLE IF EXISTS ' . self::PROBE);
            $db->executeStatement('CREATE TABLE ' . self::PROBE . " (id MEDIUMINT(8) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, graph_id MEDIUMINT(8) UNSIGNED NOT NULL DEFAULT '0', "
                . "data_id INT(11) NULL DEFAULT '5', x SMALLINT(5) NULL, y TINYINT(4) NOT NULL, z INT(11) NULL DEFAULT NULL) ENGINE=InnoDB");
            $db->executeStatement('CREATE VIEW kadupul_widen_view AS SELECT graph_id FROM ' . self::PROBE);
            $adapter = $this->adapter($db);
            $catalog = $adapter->catalog(DatabaseTarget::Local);
            // Views are not base tables and cannot be altered.
            self::assertFalse($catalog->has('kadupul_widen_view'));
            self::assertSame([], $catalog->columns('kadupul_widen_probe_absent'));
            $columns = $catalog->columns(self::PROBE);
            self::assertSame(['id', 'graph_id', 'data_id', 'x', 'y', 'z'], array_map(static fn(ColumnDefinition $c): string => $c->name, $columns));
            // information_schema writes DEFAULT NULL as the word NULL; it is no default.
            self::assertSame([null, '0', '5', null, null, null], array_map(static fn(ColumnDefinition $c): ?string => $c->default, $columns));
            self::assertTrue($adapter->widen(DatabaseTarget::Local, self::PROBE, self::changes($catalog, self::PROBE)));
            $after = $adapter->catalog(DatabaseTarget::Local)->columns(self::PROBE);
            self::assertSame(array_fill(0, 6, 'int(10) unsigned'), array_map(static fn(ColumnDefinition $c): string => $c->type, $after));
            self::assertSame([false, false, true, true, false, true], array_map(static fn(ColumnDefinition $c): bool => $c->nullable, $after));
            self::assertSame(['auto_increment', '0', '5'], [$after[0]->extra, $after[1]->default, $after[2]->default]);
            self::assertSame('', $this->log());
        } finally {
            $db->executeStatement('DROP VIEW IF EXISTS kadupul_widen_view');
            $db->executeStatement('DROP TABLE IF EXISTS ' . self::PROBE);
        }
    }

    public function testAHostileTableIsWidenedAsOneIdentifierOnARealMariaDb(): void
    {
        $db = $this->realMariaDb();
        $hostile = $db->quoteSingleIdentifier(self::HOSTILE);
        try {
            $db->executeStatement('DROP TABLE IF EXISTS ' . $hostile . ', ' . self::PROBE);
            $db->executeStatement('CREATE TABLE ' . self::PROBE . ' (id INT) ENGINE=InnoDB');
            $db->executeStatement('CREATE TABLE ' . $hostile . " (graph_id MEDIUMINT(8) UNSIGNED NOT NULL DEFAULT '0') ENGINE=InnoDB");
            $adapter = $this->adapter($db);
            $catalog = $adapter->catalog(DatabaseTarget::Local);
            self::assertTrue($catalog->has(self::HOSTILE));
            self::assertTrue($adapter->widen(DatabaseTarget::Local, self::HOSTILE, self::changes($catalog, self::HOSTILE)));
            self::assertSame('int(10) unsigned', $adapter->catalog(DatabaseTarget::Local)->columns(self::HOSTILE)[0]->type);
            self::assertTrue($adapter->catalog(DatabaseTarget::Local)->has(self::PROBE));
        } finally {
            $db->executeStatement('DROP TABLE IF EXISTS ' . $hostile . ', ' . self::PROBE);
        }
    }

    public function testANameTheCatalogLacksSendsNothingOnARealMariaDb(): void
    {
        $db = $this->realMariaDb();
        try {
            $db->executeStatement('DROP TABLE IF EXISTS ' . self::PROBE);
            $db->executeStatement('CREATE TABLE ' . self::PROBE . " (graph_id MEDIUMINT(8) UNSIGNED NOT NULL DEFAULT '0') ENGINE=InnoDB");
            $adapter = $this->adapter($db);
            $change = [new ColumnDefinition('graph_id', 'mediumint(8) unsigned', false, '0', '')];
            // Letter case differs, which a case-insensitive server would accept.
            self::assertFalse($adapter->widen(DatabaseTarget::Local, strtoupper(self::PROBE), $change));
            self::assertFalse($adapter->widen(DatabaseTarget::Local, self::PROBE, [...$change, new ColumnDefinition('GRAPH_ID', 'mediumint(8) unsigned', false, '0', '')]));
            self::assertFalse($adapter->widen(DatabaseTarget::Local, 'kadupul_widen_probe_absent', $change));
            self::assertSame('mediumint(8) unsigned', $adapter->catalog(DatabaseTarget::Local)->columns(self::PROBE)[0]->type);
            // No DBCALL line: nothing reached the server to fail.
            self::assertSame('', $this->log());
        } finally {
            $db->executeStatement('DROP TABLE IF EXISTS ' . self::PROBE);
        }
    }

    public function testARefusedStatementIsLoggedAndReportedOnARealMariaDb(): void
    {
        $db = $this->realMariaDb();
        try {
            $db->executeStatement('DROP TABLE IF EXISTS ' . self::PROBE);
            $db->executeStatement('CREATE TABLE ' . self::PROBE . ' (graph_id INT(11) NOT NULL) ENGINE=InnoDB');
            $db->executeStatement('INSERT INTO ' . self::PROBE . ' VALUES (-1)');
            $db->executeStatement('DROP TABLE IF EXISTS settings');
            $db->executeStatement('CREATE TABLE settings (name varchar(50) PRIMARY KEY, value varchar(1024))');
            $db->executeStatement("INSERT INTO settings VALUES ('path_cactilog', ?)", [$this->root . '/log/cacti.log']);
            // A signed column holding -1 becomes unsigned, which strict mode refuses, as it did for the original.
            $db->executeStatement("SET SESSION sql_mode = 'STRICT_ALL_TABLES'");
            self::assertFalse($this->adapter($db)->widen(DatabaseTarget::Local, self::PROBE, [new ColumnDefinition('graph_id', 'int(11)', false, null, '')]));
            self::assertMatchesRegularExpression("/ - DBCALL ERROR: A DB Exec Failed!, Error: \\d+, SQL: 'ALTER TABLE `kadupul_widen_probe` MODIFY COLUMN `graph_id` int\\(10\\) unsigned NOT NULL'\n/", $this->log());
        } finally {
            $db->executeStatement('DROP TABLE IF EXISTS settings, ' . self::PROBE);
        }
    }

    public function testAColumnChangedSinceTheReadSendsNothingOnARealMariaDb(): void
    {
        $db = $this->realMariaDb();
        try {
            $db->executeStatement('DROP TABLE IF EXISTS ' . self::PROBE);
            $db->executeStatement('CREATE TABLE ' . self::PROBE . " (graph_id MEDIUMINT(8) UNSIGNED NOT NULL DEFAULT '0') ENGINE=InnoDB");
            $adapter = $this->adapter($db);
            $read = $adapter->catalog(DatabaseTarget::Local)->columns(self::PROBE);
            // Another session changes the column between the read and the statement.
            $db->executeStatement('ALTER TABLE ' . self::PROBE . " MODIFY graph_id MEDIUMINT(8) UNSIGNED NULL DEFAULT '7'");
            self::assertFalse($adapter->widen(DatabaseTarget::Local, self::PROBE, $read));
            $now = $adapter->catalog(DatabaseTarget::Local)->columns(self::PROBE)[0];
            self::assertSame(['mediumint(8) unsigned', true, '7'], [$now->type, $now->nullable, $now->default]);
            self::assertSame('', $this->log());
        } finally {
            $db->executeStatement('DROP TABLE IF EXISTS ' . self::PROBE);
        }
    }

    public function testGeneratedAndInvisibleColumnsAreReadAsSuchOnARealMariaDb(): void
    {
        $db = $this->realMariaDb();
        try {
            $db->executeStatement('DROP TABLE IF EXISTS ' . self::PROBE);
            $db->executeStatement('CREATE TABLE ' . self::PROBE . ' (id MEDIUMINT(8) UNSIGNED NOT NULL AUTO_INCREMENT INVISIBLE PRIMARY KEY, '
                . 'c MEDIUMINT(8) NOT NULL DEFAULT 0, graph_id MEDIUMINT(8) UNSIGNED INVISIBLE DEFAULT 0, data_id MEDIUMINT(8) AS (c + 1) VIRTUAL, x MEDIUMINT(8) AS (c + 2) STORED) ENGINE=InnoDB');
            $columns = $this->adapter($db)->catalog(DatabaseTarget::Local)->columns(self::PROBE);
            self::assertSame(['auto_increment, INVISIBLE', '', 'INVISIBLE', 'VIRTUAL GENERATED', 'STORED GENERATED'], array_map(static fn(ColumnDefinition $c): string => $c->extra, $columns));
            self::assertSame([false, true, false, false, false], array_map(static fn(ColumnDefinition $c): bool => $c->changeable(), $columns));
            self::assertTrue($columns[0]->change()->autoIncrement);
            $report = $this->widenUseCase($db, $this->createStub(AuditTrail::class))(false, null, true);
            $probe = array_values(array_filter($report->steps, static fn(array $s): bool => $s['table'] === self::PROBE));
            self::assertSame([['graph_id', WideningEvent::Skipped], ['data_id', WideningEvent::Skipped]], array_map(static fn(array $s): array => [$s['column'], $s['event']], $probe));
            self::assertSame(0, $report->tables());
        } finally {
            $db->executeStatement('DROP TABLE IF EXISTS ' . self::PROBE);
        }
    }

    public function testAnExpressionDefaultFailsIsReportedAndAuditedOnARealMariaDb(): void
    {
        $db = $this->realMariaDb();
        $events = [];
        $trail = $this->createStub(AuditTrail::class);
        $trail->method('record')->willReturnCallback(static function (AuditEvent $event) use (&$events): void {
            $events[] = $event;
        });
        try {
            $db->executeStatement('DROP TABLE IF EXISTS settings, ' . self::PROBE);
            $db->executeStatement('CREATE TABLE settings (name varchar(50) PRIMARY KEY, value varchar(1024))');
            $db->executeStatement("INSERT INTO settings VALUES ('path_cactilog', ?)", [$this->root . '/log/cacti.log']);
            $db->executeStatement('CREATE TABLE ' . self::PROBE . ' (graph_id INT(11) NOT NULL DEFAULT (1 + 1)) ENGINE=InnoDB');
            $report = $this->widenUseCase($db, $trail)(false, null, true);
            // MariaDB lists the default as "(1 + 1)"; as a quoted literal it is not an integer.
            self::assertSame([['table' => self::PROBE, 'column' => null, 'event' => WideningEvent::Failed,
                'statement' => 'ALTER TABLE `kadupul_widen_probe` MODIFY COLUMN `graph_id` int(10) unsigned NOT NULL DEFAULT \'(1 + 1)\'']], $report->altered());
            self::assertSame([1, 1], [$report->tables(), $report->failed()]);
            self::assertSame([['local:' . self::PROBE, AuditEvent::FAILED]], array_map(static fn(AuditEvent $e): array => [$e->targetId, $e->outcome], $events));
            self::assertStringContainsString(' - DBCALL ERROR: A DB Exec Failed!, Error: 1067, SQL: ', $this->log());
            self::assertSame('int(11)', $this->adapter($db)->catalog(DatabaseTarget::Local)->columns(self::PROBE)[0]->type);
        } finally {
            $db->executeStatement('DROP TABLE IF EXISTS settings, ' . self::PROBE);
        }
    }

    /** The use case over the real adapter, acting as an operator who holds realm 26. */
    private function widenUseCase(Connection $db, AuditTrail $trail): WidenIdColumns
    {
        $operator = $this->createStub(ConsoleOperator::class);
        $operator->method('actor')->willReturn(new Actor(1, 'admin'));
        $operator->method('canUpgradeInstallation')->willReturn(true);

        return new WidenIdColumns(new MaintenanceTarget($operator, $this->createStub(DatabaseMaintenance::class)), $this->adapter($db), new SchemaChangeAudit($trail));
    }
}
