<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class DbUpdateTableUniqueNativeTest extends TestCase
{
    #[DataProvider('rebuilds')]
    public function testRebuildPreservesUniqueness(string $before, array $after, bool $success): void
    {
        $dsn = getenv('KADUPUL_TEST_MYSQL_DSN');
        if (!$dsn) {
            self::markTestSkipped('Set KADUPUL_TEST_MYSQL_DSN to a disposable database.');
        }
        $root = dirname(__DIR__, 3);
        require_once $root . '/lib/database.php';
        require_once $root . '/lib/functions.php';
        $GLOBALS['config'] = [];
        $GLOBALS['database_total_queries'] = 0;
        $db = new PDO($dsn, getenv('KADUPUL_TEST_MYSQL_USER') ?: 'root', getenv('KADUPUL_TEST_MYSQL_PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT]);
        $table = 'unique_rebuild_' . bin2hex(random_bytes(8));
        self::assertNotFalse($db->exec("CREATE TABLE `$table` (a varchar(20) NOT NULL, b int NOT NULL, UNIQUE KEY pair_key ($before)) ENGINE=InnoDB"));
        try {
            self::assertNotFalse($db->exec("INSERT INTO `$table` VALUES ('same',1),('same',2)"));
            $definition = ['type' => 'InnoDB', 'columns' => [
                ['name' => 'a', 'type' => 'varchar(20)', 'NULL' => false],
                ['name' => 'b', 'type' => 'int', 'NULL' => false],
            ], 'keys' => [['name' => 'pair_key', 'columns' => $after]]];
            self::assertSame($success, db_update_table($table, $definition, false, false, $db));
            $indexes = $db->query("SHOW INDEX FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
            self::assertSame([0], array_values(array_unique(array_map(static fn(array $row): int => (int) $row['Non_unique'], $indexes))));
            self::assertSame($success ? $after : ['a', 'b'], array_map(static fn(array $row): string => $row['Column_name'], $indexes));
            self::assertFalse($db->exec("INSERT INTO `$table` VALUES ('same',1)"));
            self::assertSame('23000', $db->errorCode());
            self::assertSame(2, (int) $db->query("SELECT COUNT(*) FROM `$table`")->fetchColumn());
        } finally {
            self::assertNotFalse($db->exec("DROP TABLE `$table`"));
        }
    }

    public static function rebuilds(): array
    {
        return [
            'column reorder' => ['a,b', ['b', 'a'], true],
            'column removal preserves uniqueness' => ['a,b', ['b'], true],
            'duplicate rebuild preserves original index' => ['a,b', ['a'], false],
        ];
    }
}
