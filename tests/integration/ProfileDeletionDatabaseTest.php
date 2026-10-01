<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

require_once __DIR__ . '/../Helpers/ProfileDeletionContract.php';

final class ProfileDeletionDatabaseTest extends ProfileDeletionContract
{
    protected function setUp(): void
    {
        if (!getenv('KADUPUL_TEST_MYSQL_DSN')) {
            self::markTestSkipped('KADUPUL_TEST_MYSQL_DSN is required for database contracts');
        }
    }
    protected function useMysql(): bool
    {
        return true;
    }

    public function testUsageLockBlocksConcurrentReferenceInsertion(): void
    {
        $state = $this->runNative(array());
        $isolation = array_values(array_filter($state['calls'], static fn($call) => $call[0] === 'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ'));
        self::assertCount(1, $isolation);
        $queries = array_values(array_filter($state['calls'], static fn($call) => str_contains($call[0], 'FOR UPDATE') && $call[1] === array(3)));
        self::assertCount(1, $queries);
        $table = 'profile_lock_' . bin2hex(random_bytes(8));
        $connect = static fn() => new PDO(getenv('KADUPUL_TEST_MYSQL_DSN'), getenv('KADUPUL_TEST_MYSQL_USER') ?: 'root', getenv('KADUPUL_TEST_MYSQL_PASSWORD') ?: '', array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false));
        $first = $connect();
        $second = $connect();
        try {
            $first->exec('CREATE TABLE ' . $table . ' (id INTEGER PRIMARY KEY, data_source_profile_id INTEGER, INDEX data_source_profile_id (data_source_profile_id)) ENGINE=InnoDB');
            $first->exec('INSERT INTO ' . $table . ' VALUES (1,1),(2,2),(4,4),(99,99)');
            $second->exec('SET SESSION innodb_lock_wait_timeout=1');
            $first->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
            $first->exec($isolation[0][0]);
            $first->beginTransaction();
            $query = $first->prepare(str_replace('data_template_data', $table, $queries[0][0]));
            $query->execute($queries[0][1]);
            self::assertSame(array(), $query->fetchAll(PDO::FETCH_ASSOC));
            try {
                $second->exec('INSERT INTO ' . $table . ' VALUES (3,3)');
                self::fail('A reference must not be inserted while the deletion usage lock is held');
            } catch (PDOException $exception) {
                self::assertSame(1205, (int) $exception->errorInfo[1]);
            }
            $second->exec('INSERT INTO ' . $table . ' VALUES (98,98)');
            $first->rollBack();
            $second->exec('INSERT INTO ' . $table . ' VALUES (3,3)');
            self::assertSame(6, (int) $first->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn());
        } finally {
            if ($first->inTransaction()) {
                $first->rollBack();
            }
            $first->exec('DROP TABLE IF EXISTS ' . $table);
        }
    }
}
