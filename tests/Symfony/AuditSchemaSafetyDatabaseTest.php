<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Doctrine\DBAL\Connection;
use Kadupul\Platform\Application\Port\DatabaseTarget;
use Kadupul\Platform\Domain\Schema\AuditBaseline;
use Kadupul\Platform\Domain\Schema\BaselineColumn;
use Kadupul\Platform\Domain\Schema\IndexAlgorithm;
use Kadupul\Platform\Domain\Schema\PluginSchemaChanges;
use Kadupul\Platform\Domain\Schema\RebuildIndex;
use Kadupul\Platform\Domain\Schema\TableAlter;
use Kadupul\Platform\Domain\Schema\TableAudit;
use Kadupul\Platform\Infrastructure\Legacy\InstallationVersion;
use Kadupul\Platform\Infrastructure\Legacy\LegacyOperatorLog;
use Kadupul\Platform\Infrastructure\Persistence\DbalSchemaAudit;
use Kadupul\Platform\Infrastructure\Persistence\MaintenanceConnections;
use Kadupul\Tests\Fixtures\RealMariaDb;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Filesystem\Filesystem;

final class AuditSchemaSafetyDatabaseTest extends TestCase
{
    use RealMariaDb;

    private const string TABLE = 'kadupul_schema_safety_probe';

    private function adapter(Connection $db): DbalSchemaAudit
    {
        $filesystem = new Filesystem();
        $clock = new MockClock();
        return new DbalSchemaAudit(new MaintenanceConnections($db, $db, new LegacyOperatorLog(__DIR__, $filesystem, $clock)), new InstallationVersion(__DIR__, $db, $filesystem, $clock));
    }

    public function testActualRepairPreservesLocalTextAndCollationWhileRestoringDefaultAndMissingColumn(): void
    {
        $db = $this->realMariaDb();
        $table = self::TABLE;
        $db->executeStatement("CREATE TABLE $table (x varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT 'old') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
        try {
            $db->executeStatement("INSERT INTO $table VALUES (?)", ['Égal']);
            $adapter = $this->adapter($db);
            $before = $adapter->catalog(DatabaseTarget::Local)->table($table);
            self::assertNotNull($before);
            $baseline = new AuditBaseline([
                new BaselineColumn($table, 1, 'x', 'varchar(20)', 'NO', '', 'new', '', 'utf8mb4_unicode_ci'),
                new BaselineColumn($table, 2, 'missing', 'int(10)', 'NO', '', '0', ''),
            ], []);
            $audit = TableAudit::of($before, $baseline, PluginSchemaChanges::none(), true);
            self::assertSame(1, $audit->warnings);
            self::assertTrue($audit->alter($before->status)->buildable());
            self::assertTrue($adapter->alter(DatabaseTarget::Local, $audit->alter($before->status), $before));
            $stored = $db->fetchAllAssociative("SHOW FULL COLUMNS FROM $table");
            self::assertSame('utf8mb4_bin', $stored[0]['Collation']);
            self::assertSame('new', \Kadupul\Platform\Infrastructure\Persistence\ColumnDdl::defaultValue($stored[0]['Default']));
            self::assertSame('missing', $stored[1]['Field']);
            self::assertSame([['x' => 'Égal', 'missing' => 0]], $db->fetchAllAssociative("SELECT x, missing FROM $table"));
            $after = $adapter->catalog(DatabaseTarget::Local)->table($table);
            $remaining = TableAudit::of($after, $baseline, PluginSchemaChanges::none(), true);
            self::assertSame(0, $remaining->errors);
            self::assertSame(1, $remaining->warnings);
            self::assertSame([], $remaining->clauses);
        } finally {
            $db->executeStatement("DROP TABLE $table");
            $db->close();
        }
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function signedValues(): iterable
    {
        yield 'negative values' => ['int', 'int unsigned', '-1'];
        yield 'unsigned upper boundary' => ['int unsigned', 'int', '4294967295'];
    }

    #[DataProvider('signedValues')]
    public function testActualSignednessRefusalPreservesStoredTypeAndOutOfRangeValues(string $live, string $target, string $value): void
    {
        $db = $this->realMariaDb();
        $table = self::TABLE;
        $db->executeStatement("CREATE TABLE $table (x $live NOT NULL DEFAULT 0) ENGINE=InnoDB");
        try {
            $db->executeStatement("INSERT INTO $table VALUES (?)", [$value]);
            $adapter = $this->adapter($db);
            $before = $adapter->catalog(DatabaseTarget::Local)->table($table);
            $audit = TableAudit::of($before, new AuditBaseline([new BaselineColumn($table, 1, 'x', $target, 'NO', '', '0', '')], []), PluginSchemaChanges::none(), true);
            self::assertSame(1, $audit->errors);
            self::assertSame([], $audit->widened);
            self::assertFalse($audit->alter($before->status)->buildable());
            self::assertSame($before->columns, $adapter->catalog(DatabaseTarget::Local)->table($table)->columns);
            self::assertSame($value, (string) $db->fetchOne("SELECT x FROM $table"));
        } finally {
            $db->executeStatement("DROP TABLE $table");
            $db->close();
        }
    }

    public function testActualInnoDbRefusesHashBeforeDroppingThePrimaryKey(): void
    {
        $db = $this->realMariaDb();
        $table = self::TABLE;
        $db->executeStatement("CREATE TABLE $table (x int NOT NULL PRIMARY KEY) ENGINE=InnoDB");
        try {
            $db->executeStatement("INSERT INTO $table VALUES (7)");
            $adapter = $this->adapter($db);
            $before = $adapter->catalog(DatabaseTarget::Local)->table($table);
            $alter = new TableAlter($table, [new RebuildIndex([null], true, true, 'PRIMARY', ['x'], IndexAlgorithm::Hash, 'legacy')], $before->status);
            self::assertFalse($adapter->alter(DatabaseTarget::Local, $alter, $before));
            self::assertSame($before->indexes, $adapter->catalog(DatabaseTarget::Local)->table($table)->indexes);
            self::assertSame(7, (int) $db->fetchOne("SELECT x FROM $table"));
        } finally {
            $db->executeStatement("DROP TABLE $table");
            $db->close();
        }
    }

    public function testActualMemoryHashIndexIsAdmittedAndConfirmed(): void
    {
        $db = $this->realMariaDb();
        $table = self::TABLE;
        $db->executeStatement("CREATE TABLE $table (x int NOT NULL) ENGINE=MEMORY DEFAULT CHARSET=utf8mb4");
        try {
            $db->executeStatement("INSERT INTO $table VALUES (7)");
            $adapter = $this->adapter($db);
            $before = $adapter->catalog(DatabaseTarget::Local)->table($table);
            $alter = new TableAlter($table, [new RebuildIndex([], false, true, 'confirmed', ['x'], IndexAlgorithm::Hash, 'legacy')], $before->status);
            self::assertTrue($adapter->alter(DatabaseTarget::Local, $alter, $before));
            $stored = $db->fetchAllAssociative("SHOW INDEXES FROM $table");
            self::assertCount(1, $stored);
            self::assertSame('HASH', $stored[0]['Index_type']);
            self::assertSame('0', (string) $stored[0]['Non_unique']);
            self::assertSame(7, (int) $db->fetchOne("SELECT x FROM $table"));
        } finally {
            $db->executeStatement("DROP TABLE $table");
            $db->close();
        }
    }

    public function testActualMissingTextColumnUsesExplicitCollationAndLiteralDefault(): void
    {
        $db = $this->realMariaDb();
        $table = self::TABLE;
        $db->executeStatement("CREATE TABLE $table (x int NOT NULL DEFAULT 0) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
        try {
            $db->executeStatement("INSERT INTO $table VALUES (7)");
            $adapter = $this->adapter($db);
            $before = $adapter->catalog(DatabaseTarget::Local)->table($table);
            $baseline = new AuditBaseline([
                new BaselineColumn($table, 1, 'x', 'int(11)', 'NO', '', '0', ''),
                new BaselineColumn($table, 2, 'missing', 'varchar(20)', 'NO', '', "l'été", '', 'utf8mb4_bin'),
            ], []);
            $audit = TableAudit::of($before, $baseline, PluginSchemaChanges::none(), true);
            self::assertTrue($adapter->alter(DatabaseTarget::Local, $audit->alter($before->status), $before));
            $stored = $db->fetchAllAssociative("SHOW FULL COLUMNS FROM $table");
            self::assertSame('utf8mb4_bin', $stored[1]['Collation']);
            self::assertSame("l'été", \Kadupul\Platform\Infrastructure\Persistence\ColumnDdl::defaultValue($stored[1]['Default']));
            self::assertSame("l'été", $db->fetchOne("SELECT missing FROM $table"));
        } finally {
            $db->executeStatement("DROP TABLE $table");
            $db->close();
        }
    }

    /** @return iterable<string, array{bool}> */
    public static function indexOutcomes(): iterable
    {
        yield 'stored index confirmed' => [false];
        yield 'index disappears before readback' => [true];
    }

    #[DataProvider('indexOutcomes')]
    public function testActualIndexWriteMustSurviveTheReadbackBeforeSuccess(bool $removeAfterWrite): void
    {
        $original = $this->realMariaDb();
        $db = new class ($original->getParams(), $original->getDriver(), $original->getConfiguration()) extends Connection {
            public bool $removeAfterWrite = false;
            public function executeStatement(string $sql, array $params = [], array $types = []): int|string
            {
                $result = parent::executeStatement($sql, $params, $types);
                if ($this->removeAfterWrite && str_starts_with($sql, 'ALTER TABLE')) {
                    $this->removeAfterWrite = false;
                    parent::executeStatement('ALTER TABLE kadupul_schema_safety_probe DROP INDEX confirmed');
                }
                return $result;
            }
        };
        $original->close();
        $table = self::TABLE;
        $db->executeStatement("CREATE TABLE $table (x int NOT NULL) ENGINE=InnoDB");
        try {
            $db->executeStatement("INSERT INTO $table VALUES (7)");
            $adapter = $this->adapter($db);
            $before = $adapter->catalog(DatabaseTarget::Local)->table($table);
            $alter = new TableAlter($table, [new RebuildIndex([], false, false, 'confirmed', ['x'], IndexAlgorithm::Btree, 'legacy')], $before->status);
            $db->removeAfterWrite = $removeAfterWrite;
            self::assertSame(!$removeAfterWrite, $adapter->alter(DatabaseTarget::Local, $alter, $before));
            $stored = $db->fetchAllAssociative("SHOW INDEXES FROM $table");
            self::assertCount($removeAfterWrite ? 0 : 1, $stored);
            if (!$removeAfterWrite) {
                self::assertSame('confirmed', $stored[0]['Key_name']);
                self::assertSame('x', $stored[0]['Column_name']);
                self::assertSame('BTREE', $stored[0]['Index_type']);
            }
            self::assertSame(7, (int) $db->fetchOne("SELECT x FROM $table"));
        } finally {
            $db->executeStatement("DROP TABLE $table");
            $db->close();
        }
    }
}
