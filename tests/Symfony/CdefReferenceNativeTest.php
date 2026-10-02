<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Platform\Infrastructure\Legacy\CdefReferenceContract;
use Kadupul\Platform\Infrastructure\Legacy\CdefReferenceReadiness;
use PHPUnit\Framework\TestCase;

/** Real native API coverage; each case owns and removes a distinct schema. */
final class CdefReferenceNativeTest extends TestCase
{
    private ?\PDO $database = null;
    private ?string $schema = null;

    protected function setUp(): void
    {
        $dsn = getenv('KADUPUL_TEST_MYSQL_DSN');
        if (!is_string($dsn) || $dsn === '') {
            self::markTestSkipped('Set KADUPUL_TEST_MYSQL_DSN for native CDEF contract verification.');
        }
        $this->database = new \PDO($dsn, getenv('KADUPUL_TEST_MYSQL_USER') ?: 'root', getenv('KADUPUL_TEST_MYSQL_PASSWORD') ?: '', [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $schema = 'kadupul_cdef_unit_' . bin2hex(random_bytes(8));
        $this->database->exec("CREATE DATABASE `$schema`");
        $this->schema = $schema;
        $this->database->exec("USE `$schema`");
        foreach ([
            'cdef' => 'id MEDIUMINT UNSIGNED PRIMARY KEY',
            'cdef_items' => 'id INT UNSIGNED PRIMARY KEY, cdef_id MEDIUMINT UNSIGNED NOT NULL, type TINYINT UNSIGNED NOT NULL, value VARCHAR(150) NOT NULL, INDEX owner (cdef_id)',
            'graph_templates_item' => 'id INT UNSIGNED PRIMARY KEY, cdef_id MEDIUMINT UNSIGNED NOT NULL DEFAULT 0, INDEX reference_id (cdef_id)',
            'aggregate_graph_templates_item' => 'id INT UNSIGNED PRIMARY KEY, cdef_id MEDIUMINT UNSIGNED NULL',
            'aggregate_graphs_graph_item' => 'id INT UNSIGNED PRIMARY KEY, cdef_id MEDIUMINT UNSIGNED NULL',
        ] as $table => $columns) {
            $this->database->exec("CREATE TABLE `$table` ($columns) ENGINE=InnoDB");
        }
        $this->database->exec('INSERT INTO cdef VALUES(7),(8)');
    }

    protected function tearDown(): void
    {
        if ($this->database !== null && $this->schema !== null) {
            if ($this->database->inTransaction()) {
                $this->database->rollBack();
            }
            $this->database->exec("DROP DATABASE `{$this->schema}`");
        }
    }

    public function testInstallAndIdempotentRecoveryVerifyActualNativeMetadataAndData(): void
    {
        $contract = new CdefReferenceContract($this->database, 1);
        self::assertFalse($contract->ready());
        $contract->install();
        self::assertTrue($contract->ready());
        self::assertTrue((new CdefReferenceReadiness($this->database, 1))->ready());
        self::assertSame(10, (int) $this->database->query('SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE()')->fetchColumn());
        $this->database->exec('DROP TRIGGER kadupul_cdef_cdef_items_insert');
        self::assertFalse($contract->ready());
        self::assertFalse((new CdefReferenceReadiness($this->database, 1))->ready());
        $contract->install();
        self::assertTrue($contract->ready());
        self::assertTrue((new CdefReferenceReadiness($this->database, 1))->ready());
        self::assertSame([7, 8], array_map('intval', $this->database->query('SELECT id FROM cdef ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN)));
    }

    public function testActualUnsafeDataRefusesInstallationWithoutRepairingIt(): void
    {
        $this->database->exec('INSERT INTO aggregate_graphs_graph_item VALUES(1,16777215)');
        try {
            (new CdefReferenceContract($this->database, 1))->install();
            self::fail('Unsafe native references accepted.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('references are unsafe', $error->getMessage());
        }
        self::assertSame(16777215, (int) $this->database->query('SELECT cdef_id FROM aggregate_graphs_graph_item WHERE id=1')->fetchColumn());
        self::assertSame(0, (int) $this->database->query('SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE()')->fetchColumn());
    }

    public function testActualTemporaryShadowRefusesInstallerAndRuntimeDespitePersistentGuards(): void
    {
        $contract = new CdefReferenceContract($this->database, 1);
        $contract->install();
        $this->database->exec('CREATE TEMPORARY TABLE cdef (id MEDIUMINT UNSIGNED PRIMARY KEY) ENGINE=InnoDB');
        self::assertFalse((new CdefReferenceReadiness($this->database, 1))->ready());
        try {
            $contract->install();
            self::fail('Temporary native shadow accepted.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('persistent InnoDB tables', $error->getMessage());
        }
        $this->database->exec('DROP TEMPORARY TABLE cdef');
        self::assertTrue($contract->ready());
    }
}
