<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Graphing\Application\Port\GprintPresetAccess;
use Kadupul\Graphing\Infrastructure\Legacy\LegacyGprintPresetStore;
use Kadupul\IdentityAccess\Contract\AuditTrail;
use Kadupul\Platform\Contract\DatabaseConnection;
use Kadupul\Platform\Contract\LegacyConfiguration;
use Kadupul\Tests\Fixtures\RealMariaDb;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GprintPresetMariaDbTest extends TestCase
{
    use RealMariaDb;

    #[DataProvider('mutationTables')]
    public function testNontransactionalTablesRejectBothMutations(string $table, bool $temporary): void
    {
        $connection = $this->realMariaDb();
        $db = $connection->getNativeConnection();
        $schema = 'gprint_guard_' . bin2hex(random_bytes(6));
        $db->exec('CREATE DATABASE `' . $schema . '`');
        $db->exec('USE `' . $schema . '`');
        try {
            foreach (self::tables() as $name) {
                $db->exec('CREATE TABLE `' . $name . '` (id INT PRIMARY KEY) ENGINE=InnoDB');
            }
            if ($temporary) {
                $db->exec('CREATE TEMPORARY TABLE `' . $table . '` (id INT PRIMARY KEY) ENGINE=MyISAM');
            } else {
                $db->exec('ALTER TABLE `' . $table . '` ENGINE=MyISAM');
            }
            $access = $this->createMock(GprintPresetAccess::class);
            $access->expects(self::never())->method('assertCurrent');
            $database = new class ($db) implements DatabaseConnection {
                public function __construct(private readonly \PDO $db) {}
                public function get(): \PDO
                {
                    return $this->db;
                }
            };
            $configuration = $this->createMock(LegacyConfiguration::class);
            $configuration->method('values')->willReturn(['collector_id' => 1]);
            $store = new LegacyGprintPresetStore($database, $access, $this->createMock(AuditTrail::class), $configuration);
            foreach ([fn() => $store->save(42, null, 'New', '%5.2lf', null), fn() => $store->delete(42, [1], [1 => str_repeat('0', 64)])] as $mutation) {
                try {
                    $mutation();
                    self::fail('Nontransactional table was accepted.');
                } catch (\RuntimeException $error) {
                    self::assertStringContainsString('InnoDB tables: ' . $table, $error->getMessage());
                }
                self::assertFalse($db->inTransaction());
            }
        } finally {
            $db->exec('DROP DATABASE `' . $schema . '`');
            $connection->close();
        }
    }

    public static function mutationTables(): array
    {
        $cases = [];
        foreach (self::tables() as $table) {
            $cases[$table] = [$table, false];
            $cases[$table . ' temporary shadow'] = [$table, true];
        }
        return $cases;
    }

    public function testDeletionLocksNewReferencesDespiteReadCommittedSessionDefault(): void
    {
        $connection = $this->realMariaDb();
        $writerConnection = $this->realMariaDb();
        $db = $connection->getNativeConnection();
        $writer = $writerConnection->getNativeConnection();
        $schema = 'gprint_lock_' . bin2hex(random_bytes(6));
        $db->exec('CREATE DATABASE `' . $schema . '`');
        foreach ([$db, $writer] as $client) {
            $client->exec('USE `' . $schema . '`');
        }
        try {
            $source = file_get_contents(__DIR__ . '/../../cacti.sql');
            foreach (self::tables() as $table) {
                self::assertSame(1, preg_match('/CREATE TABLE `?' . $table . '`? \(.*?;\s/s', $source, $match));
                $db->exec($match[0]);
            }
            $db->exec("INSERT INTO graph_templates_gprint (id,hash,name,gprint_text) VALUES (1,'fixture-hash','Unused','%5.2lf')");
            $db->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
            $writer->exec('SET SESSION innodb_lock_wait_timeout = 1');
            $attempted = false;
            $probe = static function () use ($writer, &$attempted): void {
                $attempted = true;
                try {
                    $writer->exec('INSERT INTO graph_templates_item (gprint_id,local_graph_id,graph_template_id) VALUES (1,91,4)');
                    self::fail('A concurrent graph reference bypassed the dependency gap lock.');
                } catch (\PDOException $error) {
                    self::assertSame(1205, $error->errorInfo[1]);
                }
            };
            $db->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [GprintDependencyLockStatement::class, [$probe]]);
            $database = new class ($db) implements DatabaseConnection {
                public function __construct(private readonly \PDO $db) {}
                public function get(): \PDO
                {
                    return $this->db;
                }
            };
            $configuration = $this->createMock(LegacyConfiguration::class);
            $configuration->method('values')->willReturn(['collector_id' => 1]);
            $store = new LegacyGprintPresetStore($database, $this->createMock(GprintPresetAccess::class), $this->createMock(AuditTrail::class), $configuration);
            $preset = $store->find(1);
            self::assertNotNull($preset);
            $store->delete(42, [1], [1 => $preset->revision]);
            self::assertTrue($attempted);
            self::assertFalse($db->inTransaction());
            self::assertSame(0, (int) $db->query('SELECT COUNT(*) FROM graph_templates_gprint')->fetchColumn());
            self::assertSame(0, (int) $db->query('SELECT COUNT(*) FROM graph_templates_item')->fetchColumn());
        } finally {
            $db->exec('DROP DATABASE `' . $schema . '`');
            $connection->close();
            $writerConnection->close();
        }
    }

    private static function tables(): array
    {
        return ['graph_templates_gprint', 'graph_templates_item', 'settings', 'user_auth', 'user_auth_realm', 'user_auth_group', 'user_auth_group_members', 'user_auth_group_realm'];
    }
}

final class GprintDependencyLockStatement extends \PDOStatement
{
    protected function __construct(private readonly \Closure $probe) {}

    public function execute(?array $params = null): bool
    {
        $result = parent::execute($params);
        if (str_starts_with($this->queryString, 'SELECT gprint_id, local_graph_id, graph_template_id')) {
            ($this->probe)();
        }
        return $result;
    }
}
