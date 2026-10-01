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

    /** @dataProvider collectorScenarios */
    public function testCollectorCopiesParentsBeforeDataDefinitions(string $mode, string $failure): void
    {
        $state = $this->runNative(array('collector' => $mode, 'failure' => $failure));
        if ($failure !== '') {
            self::assertSame(array(1), array_map('intval', array_column($state['rows'], 'id')));
            if (str_starts_with($failure, 'child-')) {
                self::assertSame(77, (int) $state['parent']);
            } else {
                self::assertFalse($state['parent']);
            }
            if (!str_starts_with($failure, 'child-')) {
                self::assertStringContainsString('existing collector data-source definitions were retained', implode('\n', $state['log']));
            }
            self::assertSame(array(), array_filter($state['calls'], static fn($call) => $call[0] === 'remote' && str_starts_with($call[1], 'TRUNCATE')));
        } else {
            self::assertCount(4, $state['rras']);
            self::assertSame(array(1,3), array_values(array_unique(array_map('intval', array_column($state['rras'], 'consolidation_function_id')))));
            self::assertSame(77, (int) $state['parent']);
            self::assertSame($mode === 'bulk' ? array(2) : array(1,2), array_map('intval', array_column($state['rows'], 'id')));
            self::assertSame(77, (int) end($state['rows'])['data_source_profile_id']);
        }
    }

    /** @dataProvider collectorScenarios */
    public function testCollectorEntryPointsReportReplicationOutcome(string $mode, string $failure): void
    {
        $state = $this->runNative(array('collector' => $mode, 'failure' => $failure, 'entrypoint' => true));
        self::assertSame($failure === '', $state['result']);
        self::assertSame(array($failure === '' ? '' : 'on', 'on'), $state['sync']);
        if ($failure !== '') {
            self::assertSame(array(), $state['hooks']);
            self::assertNotContains('poller_sync', $state['messages']);
            self::assertStringContainsString('failed while replicating data-source definitions', implode('\n', $state['log']));
            self::assertSame(array(1), array_map('intval', array_column($state['rows'], 'id')));
            self::assertSame(array(), array_filter($state['calls'], static fn($call) => str_contains($call[1], 'FROM data_template_rrd') || str_contains($call[1], 'SUM(CASE')));
            if ($mode === 'bulk') {
                self::assertContains('poller_sync_failed', $state['messages']);
            }
        } else {
            self::assertNotEmpty($state['hooks']);
            if ($mode === 'bulk') {
                self::assertContains('poller_sync', $state['messages']);
            }
        }
    }

    public function testCollectorCliRetainsFailedSynchronizationAndExitsWithFailure(): void
    {
        foreach (array(false, true) as $failure) {
            $state = $this->runNative(array('collector' => 'bulk', 'cli' => true, 'failure' => $failure));
            self::assertSame($failure ? 1 : 0, $state['status']);
            self::assertSame('', $state['stdout']);
            self::assertSame('', $state['stderr']);
            self::assertTrue($state['unregistered']);
            self::assertSame($failure ? array(3) : array(2, 3), array_column(array_column($state['calls'], 1), 0));
            self::assertSame($failure, str_contains(implode('\n', $state['log']), 'replication failed'));
            self::assertSame(!$failure, str_contains(implode('\n', $state['log']), 'Poller ID 2 fully Replicated'));
        }
    }

    public static function collectorScenarios(): array
    {
        $cases = array();
        foreach (array('bulk', 'device') as $mode) {
            foreach (array('', 'copy', 'missing', 'rra', 'cf', 'corrupt', 'missing-rra', 'missing-cf', 'collision', 'engine', 'child-write', 'child-late', 'child-schema', 'child-engine', 'child-corrupt') as $failure) {
                $cases[$mode . ' ' . ($failure ?: 'custom profile')] = array($mode, $failure);
            }
        }
        $cases['bulk child-delete'] = array('bulk', 'child-delete');
        return $cases;
    }

    /** @dataProvider deletionOutcomes */
    public function testConcurrentWriterChecksParentAfterDeletionFinishes(string $outcome, string $writer): void
    {
        $state = $this->runNative(array('reference_guard' => $outcome, 'writer' => $writer));
        self::assertTrue($state['available']);
        self::assertTrue($state['waiting']);
        self::assertSame(0, $state['orphans']);
        self::assertSame('upserted', $state['legacyName']);
        self::assertSame(0, $state['zero']);
        self::assertSame(array(true, true, true), $state['rejected']);
        self::assertTrue($state['missingRejected']);
        self::assertTrue($state['modifiedRejected']);
        if ($outcome === 'commit') {
            self::assertSame(array('inserted' => false, 'sqlstate' => '45000'), $state['writer']);
        } else {
            self::assertSame(array('inserted' => true), $state['writer']);
        }
    }

    /** @dataProvider editorOutcomes */
    public function testConcurrentEditorCannotResurrectDeletedDefinitions(string $outcome, string $editor): void
    {
        $state = $this->runNative(array('reference_guard' => $outcome, 'editor' => $editor));
        self::assertTrue($state['waiting']);
        self::assertSame(0, $state['definitionOrphans']);
        $ids = array_map('intval', array_column($state['writer']['tables']['data_source_profiles'], 'id'));
        self::assertSame($outcome === 'commit' ? array() : ($editor === 'copy' ? array(3,4) : array(3)), $ids);
        if ($outcome === 'rollback') {
            self::assertNotEmpty($state['writer']['tables']['data_source_profiles_cf']);
            if ($editor !== 'remove') {
                self::assertNotEmpty($state['writer']['tables']['data_source_profiles_rra']);
            }
        }
    }
    public static function editorOutcomes(): array
    {
        $cases = array();
        foreach (array('commit','rollback') as $outcome) {
            foreach (array('profile','rra','copy','remove') as $editor) {
                $cases[$outcome . ' ' . $editor] = array($outcome, $editor);
            }
        }
        return $cases;
    }

    public static function deletionOutcomes(): array
    {
        $cases = array();
        foreach (array('commit', 'rollback') as $outcome) {
            foreach (array('insert', 'update', 'upsert', 'rra-insert', 'rra-update', 'rra-upsert', 'cf-insert', 'cf-update', 'cf-upsert') as $writer) {
                $cases[$outcome . ' ' . $writer] = array($outcome, $writer);
            }
        }
        return $cases;
    }

    public function testUsageLockBlocksConcurrentReferenceInsertion(): void
    {
        $state = $this->runNative(array());
        $isolation = array_values(array_filter($state['calls'], static fn($call) => $call[0] === 'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ'));
        self::assertCount(1, $isolation);
        $queries = array_values(array_filter($state['calls'], static fn($call) => str_contains($call[0], 'FROM data_template_data') && str_contains($call[0], 'FOR UPDATE') && $call[1] === array(3)));
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
