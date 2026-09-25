<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Kadupul\Platform\Application\Command\AuditDatabase;
use Kadupul\Platform\Application\Command\WidenIdColumns;
use Kadupul\Platform\Application\Port\DatabaseMaintenance;
use Kadupul\Platform\Application\Port\DatabaseTarget;
use Kadupul\Platform\Application\Port\InstallationUpgrade;
use Kadupul\Platform\Application\ReadModel\AuditReport;
use Kadupul\Platform\Domain\Schema\AddColumn;
use Kadupul\Platform\Domain\Schema\AuditBaseline;
use Kadupul\Platform\Domain\Schema\AuditMode;
use Kadupul\Platform\Domain\Schema\BaselineColumn;
use Kadupul\Platform\Domain\Schema\BaselineIndex;
use Kadupul\Platform\Domain\Schema\ColumnExtra;
use Kadupul\Platform\Domain\Schema\ColumnSpec;
use Kadupul\Platform\Domain\Schema\ColumnType;
use Kadupul\Platform\Domain\Schema\DropIndex;
use Kadupul\Platform\Domain\Schema\IndexAlgorithm;
use Kadupul\Platform\Domain\Schema\LiveTable;
use Kadupul\Platform\Domain\Schema\ModifyColumn;
use Kadupul\Platform\Domain\Schema\RebuildIndex;
use Kadupul\Platform\Domain\Schema\TableAlter;
use Kadupul\Platform\Domain\Schema\TableAudit;
use Kadupul\Platform\Domain\Schema\TableStatus;
use Kadupul\Platform\Domain\Schema\UnbuildableClause;
use Kadupul\Platform\Domain\Schema\WidenedColumn;
use Kadupul\Platform\Infrastructure\Legacy\InstallationConfiguration;
use Kadupul\Platform\Infrastructure\Legacy\InstallationVersion;
use Kadupul\Platform\Infrastructure\Legacy\LegacyOperatorLog;
use Kadupul\Platform\Infrastructure\Persistence\DbalAuditBaselineStore;
use Kadupul\Platform\Infrastructure\Persistence\DbalColumnWidening;
use Kadupul\Platform\Infrastructure\Persistence\DbalSchemaAudit;
use Kadupul\Platform\Infrastructure\Persistence\MaintenanceConnections;
use Kadupul\Tests\Fixtures\MaintenanceOperator;
use Kadupul\Tests\Fixtures\RealMariaDb;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Filesystem\Filesystem;

final class DbalSchemaAuditTest extends TestCase
{
    use MaintenanceOperator;
    use RealMariaDb;

    private const string PROBE = 'kadupul_audit_probe';
    private const string HOSTILE = 'kadupul_audit`probe; DROP TABLE settings; --';

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/kadupul-audit-' . bin2hex(random_bytes(8));
        (new Filesystem())->dumpFile($this->root . '/include/cacti_version', "1.3.0\n");
        mkdir($this->root . '/log', 0700, true);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->root);
    }

    /**
     * A MariaDB platform pointed at a port nothing listens on: it can quote
     * and render, and any statement it tried to send would fail to connect.
     */
    private static function offline(): Connection
    {
        return DriverManager::getConnection(['driver' => 'pdo_mysql', 'host' => '127.0.0.1', 'port' => 1, 'serverVersion' => '10.11.0-MariaDB']);
    }

    private function connections(Connection $db): MaintenanceConnections
    {
        return new MaintenanceConnections($db, $db, new LegacyOperatorLog($this->root, new Filesystem(), new MockClock()));
    }

    private function audit(Connection $db): DbalSchemaAudit
    {
        return new DbalSchemaAudit($this->connections($db), new InstallationVersion($this->root, $db, new Filesystem(), new MockClock()));
    }

    private function store(Connection $db, float $dumpTimeout = 300.0): DbalAuditBaselineStore
    {
        return new DbalAuditBaselineStore($this->root, new Filesystem(), $this->connections($db), new InstallationConfiguration($this->root), $dumpTimeout);
    }

    private function log(): string
    {
        return is_file($this->root . '/log/cacti.log') ? (string) file_get_contents($this->root . '/log/cacti.log') : '';
    }

    /** A MariaDB with a settings table that sends cacti.log into the test directory. */
    private function mariaDb(): Connection
    {
        $db = $this->realMariaDb();
        $this->dropAll($db);
        $db->executeStatement('CREATE TABLE settings (name varchar(50) PRIMARY KEY, value varchar(1024))');
        $db->executeStatement("INSERT INTO settings VALUES ('path_cactilog', ?)", [$this->root . '/log/cacti.log']);

        return $db;
    }

    private function dropAll(Connection $db): void
    {
        $db->executeStatement('DROP TABLE IF EXISTS settings, table_columns, table_indexes, plugin_db_changes, poller_item, rrdcheck, version, ' . self::PROBE . ', '
            . $db->quoteSingleIdentifier(self::HOSTILE) . ', ' . $db->quoteSingleIdentifier(ucfirst(self::PROBE)));
    }

    private static function innodb(): TableStatus
    {
        return new TableStatus('InnoDB', 'utf8mb4_unicode_ci', 'Dynamic', 0);
    }

    private static function type(string $text): ColumnType
    {
        return ColumnType::parse($text) ?? throw new \LogicException('Not a column type: ' . $text);
    }

    /** @return list<string> */
    private static function keys(Connection $db, string $table): array
    {
        return array_values(array_unique(array_column($db->fetchAllAssociative('SHOW INDEXES FROM ' . $db->quoteSingleIdentifier($table)), 'Key_name')));
    }

    public function testStatementsQuoteEveryNameAndUseOnlyTypedParts(): void
    {
        $int = self::type('int(10) unsigned');
        $alter = new TableAlter('host`s', [
            new ModifyColumn(new ColumnSpec('ping`x', $int, true, "it's", false, ColumnExtra::None), 'legacy'),
            new AddColumn(new ColumnSpec('seen', self::type('timestamp'), false, null, true, ColumnExtra::OnUpdateNow), 'id', 'legacy'),
            new AddColumn(new ColumnSpec('id', $int, true, null, false, ColumnExtra::AutoIncrement), null, 'legacy'),
            new DropIndex('stray`; DROP TABLE host; --'),
            new RebuildIndex([null, 'old'], false, true, 'uniq', ['a', 'b'], IndexAlgorithm::Hash, 'legacy'),
            new RebuildIndex([], true, false, 'PRIMARY', ['a`b'], IndexAlgorithm::Btree, 'legacy'),
            new RebuildIndex(['x`y'], false, false, 'plain`z', ['c'], IndexAlgorithm::Btree, 'legacy'),
        ], new TableStatus('MyISAM', 'latin1_swedish_ci', 'Dynamic', 0));

        self::assertSame('ALTER TABLE `host``s` MODIFY COLUMN `ping``x` int(10) unsigned NOT NULL DEFAULT \'it\'\'s\', '
            . 'ADD COLUMN `seen` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `id`, '
            . 'ADD COLUMN `id` int(10) unsigned NOT NULL AUTO_INCREMENT FIRST, '
            . 'DROP INDEX `stray``; DROP TABLE host; --`, '
            . 'DROP PRIMARY KEY, DROP INDEX `old`, ADD UNIQUE INDEX `uniq` (`a`, `b`) USING HASH, '
            . 'ADD PRIMARY KEY (`a``b`) USING BTREE, '
            . 'DROP INDEX `x``y`, ADD INDEX `plain``z` (`c`) USING BTREE, '
            . 'ENGINE=InnoDB ROW_FORMAT=Dynamic CHARSET=latin1', $this->audit(self::offline())->statement(DatabaseTarget::Local, $alter));
    }

    public function testAnInnodbTableGetsNoEngineOption(): void
    {
        $alter = new TableAlter('t', [new DropIndex('k')], new TableStatus('InnoDB', null, 'Dynamic', 0));

        self::assertSame('ALTER TABLE `t` DROP INDEX `k`, ROW_FORMAT=Dynamic CHARSET=utf8mb4', $this->audit(self::offline())->statement(DatabaseTarget::Local, $alter));
    }

    public function testADefaultEndingInABackslashIsOneLiteral(): void
    {
        $alter = new TableAlter('t', [
            new ModifyColumn(new ColumnSpec('a', self::type('varchar(20)'), true, 'x\\', false, ColumnExtra::None), 'legacy'),
            new ModifyColumn(new ColumnSpec('b', self::type('varchar(20)'), false, "x\\', b = 'y", false, ColumnExtra::None), 'legacy'),
        ], self::innodb());

        self::assertSame(
            "ALTER TABLE `t` MODIFY COLUMN `a` varchar(20) NOT NULL DEFAULT 'x\\\\', MODIFY COLUMN `b` varchar(20) DEFAULT 'x\\\\'', b = ''y', ROW_FORMAT=Dynamic CHARSET=utf8mb4",
            $this->audit(self::offline())->statement(DatabaseTarget::Local, $alter)
        );
    }

    public function testAnUnbuildableAlterHasNoStatement(): void
    {
        $this->expectException(\LogicException::class);
        $this->audit(self::offline())->statement(DatabaseTarget::Local, new TableAlter('t', [new UnbuildableClause('x')], self::innodb()));
    }

    public function testACharsetOutsideTheListHasNoStatement(): void
    {
        $this->expectException(\LogicException::class);
        $this->audit(self::offline())->statement(DatabaseTarget::Local, new TableAlter('t', [new DropIndex('k')], new TableStatus('InnoDB', 'utf16_general_ci', 'Dynamic', 0)));
    }

    public function testTheBaselineConstantsMatchTheShippedFile(): void
    {
        $dump = (string) file_get_contents(dirname(__DIR__, 2) . DbalAuditBaselineStore::FILE);
        preg_match_all('/^CREATE TABLE `table_(?:columns|indexes)` \(.*?\n\) [^;]*/ms', $dump, $tables);

        self::assertSame([DbalAuditBaselineStore::DUMP_COLUMNS, DbalAuditBaselineStore::DUMP_INDEXES], $tables[0]);
    }

    public function testTheFileAndDocsDirectoryDecideWhatIsRead(): void
    {
        $store = $this->store(self::offline());
        self::assertNull($store->read());
        self::assertNull($store->dumpPath());
        (new Filesystem())->dumpFile($this->root . DbalAuditBaselineStore::FILE, "INSERT INTO `table_columns` VALUES ('t',1,'x','int(10)','NO','',NULL,'');\n");
        self::assertSame('x', $store->read()?->columnRows[0]->field);
        self::assertSame($this->root . DbalAuditBaselineStore::FILE, $store->dumpPath());
    }

    public function testTheCatalogReadsWhatShowColumnsAndShowIndexesPrintOnARealMariaDb(): void
    {
        $db = $this->mariaDb();
        try {
            $db->executeStatement('CREATE TABLE ' . self::PROBE . " (id int(10) unsigned NOT NULL AUTO_INCREMENT, name varchar(20) NOT NULL DEFAULT 'a', PRIMARY KEY (id), KEY name (name)) ENGINE=InnoDB");
            $db->executeStatement('CREATE TABLE plugin_db_changes (plugin varchar(16), `table` varchar(64), `column` varchar(64), method varchar(16))');
            $db->executeStatement("INSERT INTO plugin_db_changes VALUES ('thold', 'thold_data', '', 'create'), ('thold', 'host', 'thold_send', 'addcolumn'), ('x', 'other', 'y', 'dropcolumn')");

            $catalog = $this->audit($db)->catalog(DatabaseTarget::Local);
            $table = $catalog->table(self::PROBE);

            self::assertNotNull($table);
            self::assertSame([
                ['Field' => 'id', 'Type' => 'int(10) unsigned', 'Null' => 'NO', 'Key' => 'PRI', 'Default' => null, 'Extra' => 'auto_increment'],
                ['Field' => 'name', 'Type' => 'varchar(20)', 'Null' => 'NO', 'Key' => 'MUL', 'Default' => 'a', 'Extra' => ''],
            ], $table->columns);
            self::assertSame([['PRIMARY', '0', '1', 'id'], ['name', '1', '1', 'name']], array_map(static fn(array $index): array => [$index['Key_name'], $index['Non_unique'], $index['Seq_in_index'], $index['Column_name']], $table->indexes));
            self::assertSame('InnoDB', $table->status->engine);
            self::assertTrue($catalog->plugins->createdTable('THOLD_DATA'));
            self::assertTrue($catalog->plugins->addedColumn('host', 'thold_send'));
            self::assertFalse($catalog->plugins->addedColumn('other', 'y'));
            self::assertNull($catalog->table(strtoupper(self::PROBE)));
        } finally {
            $this->dropAll($db);
        }
    }

    public function testACatalogWithoutPluginChangesMatchesNoPluginOnARealMariaDb(): void
    {
        $db = $this->mariaDb();
        try {
            $catalog = $this->audit($db)->catalog(DatabaseTarget::Local);

            self::assertFalse($catalog->plugins->createdTable('settings'));
            self::assertNotNull($catalog->table('settings'));
        } finally {
            $this->dropAll($db);
        }
    }

    /**
     * The original's lookups failed quietly and matched nothing here; the
     * adapter lets the fault through, so the audit fails instead of treating
     * every plugin table as unknown.
     */
    public function testAPluginChangesTableWithoutItsColumnsFailsTheCatalogOnARealMariaDb(): void
    {
        $db = $this->mariaDb();
        try {
            $db->executeStatement('CREATE TABLE plugin_db_changes (plugin varchar(16), `table` varchar(64))');

            $this->expectException(\Doctrine\DBAL\Exception::class);
            $this->audit($db)->catalog(DatabaseTarget::Local);
        } finally {
            $this->dropAll($db);
        }
    }

    public function testTheVersionsAreTheFileAndTheVersionRowOnARealMariaDb(): void
    {
        $db = $this->mariaDb();
        try {
            $db->executeStatement('DROP TABLE IF EXISTS version');
            $db->executeStatement('CREATE TABLE version (cacti varchar(20))');
            $audit = $this->audit($db);
            self::assertSame('', $audit->databaseVersion(DatabaseTarget::Local));
            $db->executeStatement("INSERT INTO version VALUES ('1.2.31 ')");

            self::assertSame(['1.3.0', '1.2.31 '], [$audit->codeVersion(), $audit->databaseVersion(DatabaseTarget::Local)]);
        } finally {
            $db->executeStatement('DROP TABLE IF EXISTS version');
            $this->dropAll($db);
        }
    }

    public function testStatementWritesNothingOnARealMariaDb(): void
    {
        $db = $this->mariaDb();
        try {
            $db->executeStatement('CREATE TABLE ' . self::PROBE . ' (id int(10) unsigned NOT NULL, stray int NOT NULL, KEY stray (stray)) ENGINE=MyISAM');
            $audit = $this->audit($db);
            $read = $audit->catalog(DatabaseTarget::Local)->table(self::PROBE);
            self::assertNotNull($read);
            $before = $db->fetchAssociative('SHOW CREATE TABLE ' . self::PROBE);

            $statement = $audit->statement(DatabaseTarget::Local, new TableAlter(self::PROBE, [new DropIndex('stray')], $read->status));

            self::assertSame('ALTER TABLE `kadupul_audit_probe` DROP INDEX `stray`, ENGINE=InnoDB ROW_FORMAT=Dynamic CHARSET=utf8mb4', $statement);
            self::assertSame($before, $db->fetchAssociative('SHOW CREATE TABLE ' . self::PROBE));
            self::assertSame('', $this->log());
        } finally {
            $this->dropAll($db);
        }
    }

    public function testAnAlterRunsOnlyWhileTheTableIsAsReadOnARealMariaDb(): void
    {
        $db = $this->mariaDb();
        try {
            $db->executeStatement('CREATE TABLE ' . self::PROBE . ' (id int(10) unsigned NOT NULL, stray int NOT NULL, KEY stray (stray)) ENGINE=InnoDB');
            $audit = $this->audit($db);
            $read = $audit->catalog(DatabaseTarget::Local)->table(self::PROBE);
            self::assertNotNull($read);
            $alter = new TableAlter(self::PROBE, [new DropIndex('stray')], $read->status);
            // Another session changes the table between the read and the statement.
            $db->executeStatement('ALTER TABLE ' . self::PROBE . ' ADD COLUMN later int');

            self::assertFalse($audit->alter(DatabaseTarget::Local, $alter, $read));
            self::assertSame(['stray'], self::keys($db, self::PROBE));

            $read = $audit->catalog(DatabaseTarget::Local)->table(self::PROBE);
            self::assertNotNull($read);
            self::assertTrue($audit->alter(DatabaseTarget::Local, $alter, $read));
            self::assertSame([], self::keys($db, self::PROBE));
            self::assertSame('', $this->log());
        } finally {
            $this->dropAll($db);
        }
    }

    public function testATableRenamedOrDroppedSinceTheReadGetsNothingOnARealMariaDb(): void
    {
        $db = $this->mariaDb();
        try {
            $db->executeStatement('CREATE TABLE ' . self::PROBE . ' (id int NOT NULL, KEY stray (id)) ENGINE=InnoDB');
            $audit = $this->audit($db);
            $read = $audit->catalog(DatabaseTarget::Local)->table(self::PROBE);
            self::assertNotNull($read);
            $alter = new TableAlter(self::PROBE, [new DropIndex('stray')], $read->status);

            // Same letters, other case: a bound TABLE_NAME lookup would still find it.
            $db->executeStatement('RENAME TABLE ' . self::PROBE . ' TO ' . $db->quoteSingleIdentifier(ucfirst(self::PROBE)));
            self::assertFalse($audit->alter(DatabaseTarget::Local, $alter, $read));
            self::assertSame(['stray'], self::keys($db, ucfirst(self::PROBE)));

            // An alter for one table, checked against the read of another.
            $db->executeStatement('RENAME TABLE ' . $db->quoteSingleIdentifier(ucfirst(self::PROBE)) . ' TO ' . self::PROBE);
            $other = new LiveTable(ucfirst(self::PROBE), $read->status, $read->columns, $read->indexes);
            self::assertFalse($audit->alter(DatabaseTarget::Local, $alter, $other));
            self::assertSame(['stray'], self::keys($db, self::PROBE));

            $db->executeStatement('DROP TABLE ' . self::PROBE);
            self::assertFalse($audit->alter(DatabaseTarget::Local, $alter, $read));
            self::assertSame('', $this->log());
        } finally {
            $this->dropAll($db);
        }
    }

    /** @return iterable<string, array{\Closure(): list<\Kadupul\Platform\Domain\Schema\AlterClause>}> */
    public static function clausesNamingWhatIsNotLive(): iterable
    {
        $int = ColumnType::parse('int(11)');
        yield 'a modify in another letter case' => [static fn(): array => [new ModifyColumn(new ColumnSpec('ID', $int, true, null, false, ColumnExtra::None), 'legacy')]];
        yield 'a modify of a missing column' => [static fn(): array => [new ModifyColumn(new ColumnSpec('gone', $int, true, null, false, ColumnExtra::None), 'legacy')]];
        yield 'a drop in another letter case' => [static fn(): array => [new DropIndex('STRAY')]];
        yield 'a drop of a missing index' => [static fn(): array => [new DropIndex('gone')]];
        yield 'a rebuild dropping a missing index' => [static fn(): array => [new RebuildIndex(['gone'], false, false, 'n', ['id'], IndexAlgorithm::Btree, 'legacy')]];
        yield 'a valid clause next to a bad one' => [static fn(): array => [new DropIndex('stray'), new DropIndex('Stray')]];
    }

    /** @param \Closure(): non-empty-list<\Kadupul\Platform\Domain\Schema\AlterClause> $clauses */
    #[\PHPUnit\Framework\Attributes\DataProvider('clausesNamingWhatIsNotLive')]
    public function testAClauseNamingWhatTheServerDoesNotListSendsNothingOnARealMariaDb(\Closure $clauses): void
    {
        $db = $this->mariaDb();
        try {
            $db->executeStatement('CREATE TABLE ' . self::PROBE . ' (id int(11) NOT NULL, KEY stray (id)) ENGINE=InnoDB');
            $audit = $this->audit($db);
            $read = $audit->catalog(DatabaseTarget::Local)->table(self::PROBE);
            self::assertNotNull($read);
            $before = $db->fetchAssociative('SHOW CREATE TABLE ' . self::PROBE);

            self::assertFalse($audit->alter(DatabaseTarget::Local, new TableAlter(self::PROBE, $clauses(), $read->status), $read));
            self::assertSame($before, $db->fetchAssociative('SHOW CREATE TABLE ' . self::PROBE));
            self::assertSame('', $this->log());
        } finally {
            $this->dropAll($db);
        }
    }

    public function testHostileLiveNamesReachTheServerAsNamesOnARealMariaDb(): void
    {
        $db = $this->mariaDb();
        $key = 'k`; DROP TABLE settings; --';
        try {
            $db->executeStatement('CREATE TABLE ' . $db->quoteSingleIdentifier(self::HOSTILE) . ' (id int NOT NULL, name varchar(20) NOT NULL DEFAULT \'a\', KEY '
                . $db->quoteSingleIdentifier($key) . ' (id), KEY plain (name)) ENGINE=InnoDB');
            $audit = $this->audit($db);
            $read = $audit->catalog(DatabaseTarget::Local)->table(self::HOSTILE);
            self::assertNotNull($read);

            self::assertTrue($audit->alter(DatabaseTarget::Local, new TableAlter(self::HOSTILE, [
                new DropIndex($key),
                new RebuildIndex(['plain'], false, true, 'plain', ['name'], IndexAlgorithm::Btree, 'legacy'),
                new ModifyColumn(new ColumnSpec('name', self::type('varchar(30)'), true, 'x\\', false, ColumnExtra::None), 'legacy'),
            ], $read->status), $read));

            self::assertSame(['plain'], self::keys($db, self::HOSTILE));
            self::assertSame('0', (string) $db->fetchAllAssociative('SHOW INDEXES FROM ' . $db->quoteSingleIdentifier(self::HOSTILE))[0]['Non_unique']);
            $name = $db->fetchAllAssociative('SHOW COLUMNS FROM ' . $db->quoteSingleIdentifier(self::HOSTILE))[1];
            self::assertSame(['name', 'varchar(30)', 'x\\'], [$name['Field'], $name['Type'], $name['Default']]);
            self::assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM settings'));
            self::assertSame('', $this->log());
        } finally {
            $this->dropAll($db);
        }
    }

    /**
     * MariaDB renames a column to the letter case a MODIFY COLUMN spells it
     * in. The original spelled it as the audit schema did, so its repair also
     * renamed; the port spells it as the server lists it, which keeps the name.
     */
    public function testAModifyInTheLiveCaseKeepsTheNameWhereTheBaselineCaseRenamedItOnARealMariaDb(): void
    {
        $db = $this->mariaDb();
        try {
            $db->executeStatement('CREATE TABLE ' . self::PROBE . " (Name varchar(20) NOT NULL DEFAULT 'a') ENGINE=InnoDB");
            $audit = $this->audit($db);
            $read = $audit->catalog(DatabaseTarget::Local)->table(self::PROBE);
            self::assertNotNull($read);

            self::assertTrue($audit->alter(DatabaseTarget::Local, new TableAlter(self::PROBE, [
                new ModifyColumn(new ColumnSpec('Name', self::type('varchar(30)'), true, 'b', false, ColumnExtra::None), 'MODIFY COLUMN `name` varchar(30) NOT NULL DEFAULT \'b\''),
            ], $read->status), $read));
            $column = $db->fetchAssociative('SHOW COLUMNS FROM ' . self::PROBE);
            self::assertSame(['Name', 'varchar(30)', 'b'], [$column['Field'] ?? null, $column['Type'] ?? null, $column['Default'] ?? null]);

            // The original's statement, run directly.
            $db->executeStatement('ALTER TABLE ' . self::PROBE . " MODIFY COLUMN `name` varchar(30) NOT NULL DEFAULT 'b'");
            self::assertSame('name', $db->fetchAssociative('SHOW COLUMNS FROM ' . self::PROBE)['Field'] ?? null);
        } finally {
            $this->dropAll($db);
        }
    }

    /**
     * widen-id-columns, then a repair against the shipped audit schema. Once
     * poller_item.local_data_id needs widening, local_data_id joins the names
     * widened in every table, rrdcheck's included, which the audit schema
     * still lists as mediumint. The original sent a MODIFY back to mediumint;
     * under a lenient SQL mode that truncated ids above 16777215 silently.
     */
    public function testARepairAfterWidenIdColumnsNarrowsNothingOnARealMariaDb(): void
    {
        $db = $this->mariaDb();
        try {
            (new Filesystem())->copy(dirname(__DIR__, 2) . '/docs/audit_schema.sql', $this->root . DbalAuditBaselineStore::FILE);
            $db->executeStatement("CREATE TABLE version (cacti char(20) NOT NULL DEFAULT '') ENGINE=InnoDB");
            $db->executeStatement("INSERT INTO version VALUES ('1.3.0')");
            $db->executeStatement("CREATE TABLE poller_item (local_data_id mediumint(8) unsigned NOT NULL DEFAULT '0') ENGINE=InnoDB");
            // rrdcheck as docs/audit_schema.sql lists it.
            $db->executeStatement("CREATE TABLE rrdcheck (local_data_id mediumint(8) unsigned NOT NULL, test_date timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
                message varchar(250) DEFAULT '') ENGINE=InnoDB ROW_FORMAT=Dynamic DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $audit = $this->audit($db);
            $store = $this->store($db);
            self::assertSame([0, 0], self::counts($this->auditDatabase($audit, $store)(AuditMode::Report, false, null, false), 'rrdcheck'));

            (new WidenIdColumns($this->maintenanceTarget(), new DbalColumnWidening($this->connections($db)), $this->recordingAudit()))(false, null, true);
            self::assertSame('int(10) unsigned', self::idType($db, 'rrdcheck'));
            $db->executeStatement('INSERT INTO rrdcheck (local_data_id) VALUES (16777216)');

            $report = $this->auditDatabase($audit, $store)(AuditMode::Repair, false, null, true);

            $table = array_find($report->tables, static fn(TableAudit $table): bool => $table->table === 'rrdcheck');
            self::assertSame([['local_data_id', 'int(10) unsigned', 'mediumint(8) unsigned']], array_map(static fn(WidenedColumn $column): array => [$column->field, $column->type, $column->baseline], $table?->widened ?? []));
            self::assertSame([0, 1], self::counts($report, 'rrdcheck'));
            self::assertSame([], array_values(array_filter($report->alters, static fn(array $alter): bool => $alter['table'] === 'rrdcheck')));
            self::assertSame('int(10) unsigned', self::idType($db, 'rrdcheck'));
            self::assertSame('16777216', (string) $db->fetchOne('SELECT local_data_id FROM rrdcheck'));
        } finally {
            $this->dropAll($db);
        }
    }

    private function auditDatabase(DbalSchemaAudit $audit, DbalAuditBaselineStore $store): AuditDatabase
    {
        $maintenance = $this->createStub(DatabaseMaintenance::class);
        $maintenance->method('isRemoteCollector')->willReturn(false);

        return new AuditDatabase($this->maintenanceTarget(), $maintenance, $audit, $store, $this->createStub(InstallationUpgrade::class), $this->recordingAudit());
    }

    /** @return array{int, int} errors and warnings of one audited table */
    private static function counts(AuditReport $report, string $name): array
    {
        $table = array_find($report->tables, static fn(TableAudit $table): bool => $table->table === $name);

        return [$table?->errors ?? -1, $table?->warnings ?? -1];
    }

    private static function idType(Connection $db, string $table): string
    {
        return (string) ($db->fetchAssociative('SHOW COLUMNS FROM ' . $db->quoteSingleIdentifier($table) . " LIKE 'local_data_id'")['Type'] ?? '');
    }

    public function testAFailedAlterIsLoggedWithTheServerMessageOnARealMariaDb(): void
    {
        $db = $this->mariaDb();
        try {
            $db->executeStatement('CREATE TABLE ' . self::PROBE . ' (id int(10) unsigned NOT NULL) ENGINE=InnoDB');
            $audit = $this->audit($db);
            $read = $audit->catalog(DatabaseTarget::Local)->table(self::PROBE);
            self::assertNotNull($read);
            $alter = new TableAlter(self::PROBE, [new RebuildIndex([], false, false, 'missing', ['missing'], IndexAlgorithm::Btree, 'legacy')], $read->status);

            self::assertFalse($audit->alter(DatabaseTarget::Local, $alter, $read));
            // At the default verbosity only the error line is written, and
            // without the SQLSTATE prefix PDO's exception message adds.
            self::assertMatchesRegularExpression("/^\\d{2}\\/\\d{2}\\/\\d{4} \\d{2}:\\d{2}:\\d{2} - DBCALL ERROR: A DB Exec Failed!, Error: Key column 'missing' doesn't exist in table\n$/", $this->log());
        } finally {
            $this->dropAll($db);
        }
    }

    public function testResetCreatesAndEmptiesTheAuditTablesOnARealMariaDb(): void
    {
        $db = $this->mariaDb();
        try {
            $store = $this->store($db);
            self::assertNull($store->reset(DatabaseTarget::Local));
            self::assertStringContainsString('Holds Default Kadupul Table Definitions', (string) ($db->fetchAssociative('SHOW CREATE TABLE table_columns')['Create Table'] ?? ''));
            self::assertStringContainsString('Holds Default Kadupul Index Definitions', (string) ($db->fetchAssociative('SHOW CREATE TABLE table_indexes')['Create Table'] ?? ''));
            $db->executeStatement("INSERT INTO table_columns (table_name, table_sequence, table_field) VALUES ('t', 1, 'x')");
            $db->executeStatement("INSERT INTO table_indexes (idx_table_name, idx_key_name, idx_seq_in_index, idx_column_name) VALUES ('t', 'k', 1, 'x')");

            self::assertNull($store->reset(DatabaseTarget::Local));
            self::assertSame([0, 0], [(int) $db->fetchOne('SELECT COUNT(*) FROM table_columns'), (int) $db->fetchOne('SELECT COUNT(*) FROM table_indexes')]);
        } finally {
            $this->dropAll($db);
        }
    }

    public function testReplaceLeavesTheDumpDefinitionsAndBoundRowsOnARealMariaDb(): void
    {
        $db = $this->mariaDb();
        $hostile = "x'); DROP TABLE settings; --\\";
        try {
            $store = $this->store($db);
            self::assertNull($store->reset(DatabaseTarget::Local));

            self::assertTrue($store->replace(DatabaseTarget::Local, new AuditBaseline(
                [new BaselineColumn('host', 1, 'id', 'int(10) unsigned', 'NO', 'PRI', null, 'auto_increment'), new BaselineColumn('host', 2, 'n', 'varchar(20)', 'YES', '', $hostile, '')],
                [new BaselineIndex('host', 0, 'PRIMARY', 1, 'id', 'A', 0, null, null, '', 'BTREE', '')],
            )));

            self::assertStringContainsString("COMMENT='Holds Default Cacti Table Definitions'", (string) ($db->fetchAssociative('SHOW CREATE TABLE table_columns')['Create Table'] ?? ''));
            self::assertSame([['host', '1', 'id', 'int(10) unsigned', 'NO', 'PRI', null, 'auto_increment'], ['host', '2', 'n', 'varchar(20)', 'YES', '', $hostile, '']], array_map(
                static fn(array $row): array => array_map(static fn(mixed $v): ?string => $v === null ? null : (string) $v, array_values($row)),
                $db->fetchAllAssociative('SELECT * FROM table_columns ORDER BY table_sequence'),
            ));
            self::assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM table_indexes'));
            self::assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM settings'));

            self::assertTrue($store->replace(DatabaseTarget::Local, AuditBaseline::empty()));
            self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM table_columns'));
        } finally {
            $this->dropAll($db);
        }
    }

    public function testImportWritesEveryTableAsShowColumnsListsItOnARealMariaDb(): void
    {
        $db = $this->mariaDb();
        try {
            $db->executeStatement('CREATE TABLE ' . self::PROBE . " (id int(10) unsigned NOT NULL, name varchar(20) DEFAULT 'a', PRIMARY KEY (id)) ENGINE=InnoDB");
            $store = $this->store($db);
            $store->reset(DatabaseTarget::Local);
            $catalog = $this->audit($db)->catalog(DatabaseTarget::Local);

            self::assertTrue($store->import(DatabaseTarget::Local, $catalog));

            self::assertSame([[self::PROBE, 1, 'id'], [self::PROBE, 2, 'name']], array_map(
                static fn(array $row): array => [$row['table_name'], (int) $row['table_sequence'], $row['table_field']],
                $db->fetchAllAssociative('SELECT * FROM table_columns WHERE table_name = ? ORDER BY table_sequence', [self::PROBE]),
            ));
            self::assertSame('a', $db->fetchOne('SELECT table_default FROM table_columns WHERE table_name = ? AND table_field = ?', [self::PROBE, 'name']));
            self::assertSame([self::PROBE, '0', 'PRIMARY', '1', 'id'], array_map('strval', array_values($db->fetchAssociative(
                'SELECT idx_table_name, idx_non_unique, idx_key_name, idx_seq_in_index, idx_column_name FROM table_indexes WHERE idx_table_name = ?',
                [self::PROBE],
            ) ?: [])));
            $imported = array_column($db->fetchAllAssociative('SELECT DISTINCT table_name FROM table_columns'), 'table_name');
            $listed = array_map(static fn(LiveTable $table): string => $table->name, $catalog->tables());
            sort($imported);
            sort($listed);
            self::assertSame($listed, $imported);
        } finally {
            $this->dropAll($db);
        }
    }

    /**
     * A stand-in dump program on PATH, written in PHP so it sees its
     * environment exactly as export() built it (a shell would add its own
     * variables). It records its arguments and environment and prints a dump.
     * With $sleep, it prints only the start of one and then hangs.
     */
    private function fakeDump(int $exit, bool $ssl = true, ?int $sleep = null): string
    {
        $bin = $this->root . '/fake-bin';
        $script = '#!' . PHP_BINARY . "\n<?php\n"
            . 'file_put_contents(' . var_export($this->root . '/argv.json', true) . ', json_encode(array_slice($argv, 1)));' . "\n"
            . 'file_put_contents(' . var_export($this->root . '/env.json', true) . ', json_encode(getenv()));' . "\n"
            . ($sleep === null
                ? 'print "-- dump of " . implode(" ", array_slice($argv, 1)) . "\n";' . "\n"
                : 'print "-- partial dump\nINSERT INTO";' . "\n" . 'flush();' . "\n" . 'sleep(' . $sleep . ');' . "\n")
            . 'exit(' . $exit . ');' . "\n";
        foreach (['mysqldump', 'mariadb-dump'] as $name) {
            (new Filesystem())->dumpFile($bin . '/' . $name, $script);
            chmod($bin . '/' . $name, 0700);
        }
        (new Filesystem())->dumpFile($this->root . '/include/config.php', "<?php\n\$database_default = 'cacti';\n\$database_hostname = 'db.example';\n"
            . "\$database_port = '3307';\n\$database_username = '-u-root';\n\$database_password = 'p w\"\\'\$x';\n"
            . '$database_ssl = ' . var_export($ssl, true) . ";\n\$database_ssl_ca = '/tls/ca.pem';\n\$database_ssl_cert = '';\n\$database_ssl_key = '/tls/key.pem';\n");
        mkdir($this->root . '/docs');

        return $bin;
    }

    /** Runs $run with $bin first on PATH and a variable a client would read, so the test can see it is not passed on. */
    private function withPath(string $bin, \Closure $run): void
    {
        $saved = ['PATH' => getenv('PATH'), 'MYSQL_HOST' => getenv('MYSQL_HOST'), 'MYSQL_UNIX_PORT' => getenv('MYSQL_UNIX_PORT')];
        putenv('PATH=' . $bin . ':' . $saved['PATH']);
        putenv('MYSQL_HOST=elsewhere.example');
        putenv('MYSQL_UNIX_PORT=/tmp/elsewhere.sock');
        try {
            $run();
        } finally {
            foreach ($saved as $name => $value) {
                putenv($value === false ? $name : $name . '=' . $value);
            }
        }
    }

    /** A settings table with no rows, so a logged line goes to <root>/log/cacti.log. */
    private static function noSettings(): Connection
    {
        $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $db->executeStatement('CREATE TABLE settings (name varchar(50) PRIMARY KEY, value varchar(1024))');

        return $db;
    }

    public function testExportDumpsFromTheConfiguredServerWithThePasswordOnlyInItsEnvironment(): void
    {
        $bin = $this->fakeDump(0);
        $store = $this->store(self::noSettings());

        $this->withPath($bin, static function () use ($store): void {
            self::assertTrue($store->export(DatabaseTarget::Local));
        });

        $argv = json_decode((string) file_get_contents($this->root . '/argv.json'), true);
        self::assertSame(['--extended-insert=FALSE', '--host=db.example', '--port=3307', '--ssl-ca=/tls/ca.pem', '--ssl-key=/tls/key.pem',
            '--user=-u-root', '--', 'cacti', 'table_columns', 'table_indexes'], $argv);
        self::assertStringNotContainsString('p w', implode(' ', $argv));
        $env = json_decode((string) file_get_contents($this->root . '/env.json'), true);
        self::assertIsArray($env);
        $expected = ['MYSQL_PWD', 'PATH'];
        if (getenv('HOME') !== false) {
            $expected[] = 'HOME';
        }
        sort($expected);
        // macOS's CoreFoundation sets this in every PHP process it starts,
        // the fake included, even under an explicit environment.
        $names = array_values(array_diff(array_keys($env), PHP_OS_FAMILY === 'Darwin' ? ['__CF_USER_TEXT_ENCODING'] : []));
        sort($names);
        self::assertSame($expected, $names);
        self::assertSame('p w"\'$x', $env['MYSQL_PWD']);
        self::assertSame("-- dump of " . implode(' ', $argv) . "\n", file_get_contents($this->root . DbalAuditBaselineStore::FILE));
    }

    public function testExportPassesNoTlsFilesWhenTheConnectionDoesNotUseTls(): void
    {
        $bin = $this->fakeDump(0, false);
        $store = $this->store(self::noSettings());

        $this->withPath($bin, static function () use ($store): void {
            self::assertTrue($store->export(DatabaseTarget::Local));
        });

        self::assertSame(
            ['--extended-insert=FALSE', '--host=db.example', '--port=3307', '--user=-u-root', '--', 'cacti', 'table_columns', 'table_indexes'],
            json_decode((string) file_get_contents($this->root . '/argv.json'), true)
        );
    }

    public function testAFailedExportLeavesTheFileAndLogsTheExitCode(): void
    {
        $bin = $this->fakeDump(2);
        (new Filesystem())->dumpFile($this->root . DbalAuditBaselineStore::FILE, "kept\n");
        $store = $this->store(self::noSettings());

        $this->withPath($bin, static function () use ($store): void {
            self::assertFalse($store->export(DatabaseTarget::Local));
        });

        self::assertSame("kept\n", file_get_contents($this->root . DbalAuditBaselineStore::FILE));
        self::assertMatchesRegularExpression("/^\\d{2}\\/\\d{2}\\/\\d{4} \\d{2}:\\d{2}:\\d{2} - DBCALL ERROR: mysqldump failed with exit code 2 for database 'cacti'\n$/", $this->log());
    }

    public function testATimedOutExportLeavesTheFileAndLogsTheTimeout(): void
    {
        $bin = $this->fakeDump(0, true, 30);
        (new Filesystem())->dumpFile($this->root . DbalAuditBaselineStore::FILE, "kept\n");
        $store = $this->store(self::noSettings(), 0.5);

        $this->withPath($bin, static function () use ($store): void {
            self::assertFalse($store->export(DatabaseTarget::Local));
        });

        self::assertSame("kept\n", file_get_contents($this->root . DbalAuditBaselineStore::FILE));
        // Filesystem::dumpFile() writes a temporary file beside the target first.
        self::assertSame([$this->root . DbalAuditBaselineStore::FILE], glob($this->root . '/docs/*.sql*'));
        self::assertMatchesRegularExpression("/^\\d{2}\\/\\d{2}\\/\\d{4} \\d{2}:\\d{2}:\\d{2} - DBCALL ERROR: mysqldump timed out after 0.5 seconds for database 'cacti'\n$/", $this->log());
    }

    public function testAnExportThatCannotBeWrittenReturnsFalseAndLogsIt(): void
    {
        $bin = $this->fakeDump(0);
        // A directory where the file should be makes the final rename fail.
        mkdir($this->root . DbalAuditBaselineStore::FILE);
        $store = $this->store(self::noSettings());

        $this->withPath($bin, static function () use ($store): void {
            self::assertFalse($store->export(DatabaseTarget::Local));
        });

        self::assertDirectoryExists($this->root . DbalAuditBaselineStore::FILE);
        self::assertMatchesRegularExpression("/ - DBCALL ERROR: could not write the audit schema dump for database 'cacti'\n$/", $this->log());
    }

    public function testExportNeedsTheDocsDirectory(): void
    {
        $bin = $this->fakeDump(0);
        rmdir($this->root . '/docs');
        $store = $this->store(self::noSettings());

        $this->withPath($bin, static function () use ($store): void {
            self::assertFalse($store->export(DatabaseTarget::Local));
        });

        self::assertFileDoesNotExist($this->root . '/argv.json');
    }
}
