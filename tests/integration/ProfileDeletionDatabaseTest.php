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

    /** @dataProvider migrationIndexCases */
    public function testActualInstallerRecordsVersionOnlyForAUsableReferenceIndex(string $failure, bool $ready): void
    {
        $state = $this->runNative(['collector' => 'bulk', 'upgrade_entry' => true, 'failure' => $failure]);
        self::assertSame($ready ? '1.2.34' : '1.2.33', $state['remote_version']);
        if ($ready) {
            self::assertNull($state['upgrade_error']);
        } else {
            self::assertStringContainsString('reference index is missing or incompatible', $state['upgrade_error']);
            self::assertSame([], array_filter($state['calls'], static fn($call) => str_starts_with($call[1], 'UPDATE version')));
        }
    }

    public static function migrationIndexCases(): array
    {
        return ['creates missing index' => ['index-missing', true], 'existing full index' => ['', true], 'equivalent composite' => ['index-equivalent', true], 'wrong column' => ['index-wrong-column', false], 'wrong leading column' => ['index-composite', false], 'prefix' => ['index-prefix', false], 'unique' => ['index-unique', false], 'hidden' => ['index-hidden', false]];
    }

    /** @dataProvider boundedBatchCases */
    public function testReferenceDeliveryUsesBoundedBatchesAndRetainsOldRowsOnRefusal(array $scenario, int $expectedRows, bool $success): void
    {
        $state = $this->runNative($scenario + ['collector' => 'bulk']);
        self::assertSame($success, $state['result']);
        self::assertCount($expectedRows, $state['rows']);
        $writes = array_values(array_filter($state['calls'], static fn($call) => $call[0] === 'remote' && str_starts_with($call[1], 'INSERT INTO data_template_data')));
        $reads = array_values(array_filter($state['calls'], static fn($call) => $call[0] === 'remote' && str_starts_with($call[1], 'SELECT * FROM data_template_data WHERE id')));
        if (!$success) {
            self::assertSame([['id' => 1, 'data_source_profile_id' => 1, 'name' => 'existing']], $state['rows']);
            if (in_array($scenario['failure'], ['batch-late', 'batch-corrupt'], true)) {
                self::assertCount(2, $writes, 'The refusal occurs after an earlier batch was delivered');
            }
        } elseif (($scenario['failure'] ?? '') === 'batch-excluded') {
            self::assertSame('existing', $state['rows'][0]['name']);
            self::assertSame(77, $state['rows'][0]['data_source_profile_id']);
        } elseif ($expectedRows > 0) {
            self::assertSame(range(2, $expectedRows + 1), array_column($state['rows'], 'id'));
            self::assertSame([77], array_values(array_unique(array_column($state['rows'], 'data_source_profile_id'))));
        }
        if ($success && $scenario['batch_rows'] === 2001) {
            self::assertCount(3, $writes);
            self::assertCount(3, $reads);
        }
        foreach ($writes as $write) {
            self::assertLessThanOrEqual(60000, $write[2]);
            self::assertLessThanOrEqual(1048576, $write[3] + strlen($write[1]));
        }
        foreach ($reads as $read) {
            self::assertLessThanOrEqual(1000, $read[2]);
        }
        if (!empty($scenario['name_bytes']) && $success) {
            self::assertGreaterThan(1, count($writes), 'Payload size splits batches before the row limit');
        }
    }

    public static function boundedBatchCases(): array
    {
        return ['2001 rows' => [['batch_rows' => 2001], 2001, true], 'payload limit' => [['batch_rows' => 200, 'name_bytes' => 6000], 200, true], 'parameter limit' => [['batch_rows' => 1000, 'extra_columns' => 64], 1000, true], 'late write' => [['batch_rows' => 2001, 'failure' => 'batch-late'], 1, false], 'late corruption' => [['batch_rows' => 2001, 'failure' => 'batch-corrupt'], 1, false], 'oversized row' => [['batch_rows' => 1, 'name_bytes' => 600000, 'failure' => 'batch-oversized'], 1, false], 'duplicate key' => [['batch_rows' => 1, 'failure' => 'batch-duplicate'], 1, false], 'empty replacement' => [['batch_rows' => 0], 0, true], 'excluded update field' => [['collector' => 'device', 'batch_rows' => 1, 'failure' => 'batch-excluded', 'exclude' => ['name']], 1, true]];
    }

    /** @dataProvider remoteGuardPreflightScenarios */
    public function testGuardPreflightPreservesCollectorVersionBeforeEveryMutation(string $class, string $failure): void
    {
        $state = $this->runNative(array('collector' => 'bulk', 'failure' => $failure, 'entrypoint' => true, 'class' => $class));
        self::assertSame('1.2.33', $state['remote_version']);
        self::assertFalse($state['result']);
        self::assertSame(array($class === 'settings' ? '' : 'on', 'on'), $state['sync']);
        self::assertSame(array(), array_filter($state['calls'], static fn($call) => $call[0] === 'remote' && preg_match('/^\s*(INSERT|REPLACE|UPDATE|DELETE|CREATE|DROP|ALTER|TRUNCATE)\b/i', $call[1])));
        self::assertStringContainsString('schema version was retained', implode('\n', $state['log']));
    }

    /** @dataProvider equivalentIndexClasses */
    public function testCollectorAcceptsAnEquivalentFullLeadingColumnIndex(string $class): void
    {
        $state = $this->runNative(array('collector' => 'bulk', 'failure' => 'index-equivalent', 'entrypoint' => true, 'class' => $class));
        self::assertTrue($state['result']);
        self::assertSame('1.2.33', $state['remote_version']);
        self::assertSame(array(), array_filter($state['calls'], static fn($call) => preg_match('/\bversion\b/', $call[1])));
        self::assertSame(array('', 'on'), $state['sync']);
    }

    public static function equivalentIndexClasses(): array
    {
        return array('all' => array('all'), 'settings' => array('settings'), 'data' => array('data'));
    }

    public static function remoteGuardPreflightScenarios(): array
    {
        $cases = array();
        foreach (array('all', 'settings', 'data') as $class) {
            foreach (array('guard-missing', 'guard-modified', 'index-missing', 'index-wrong-column', 'index-composite', 'index-hidden') as $failure) {
                $cases[$class . ' ' . $failure] = array($class, $failure);
            }
        }
        return $cases;
    }

    public function testCollectorCapturesOneLockedSourceDefinitionSnapshot(): void
    {
        $state = $this->runNative(array('collector' => 'bulk', 'failure' => '', 'snapshot_edit' => true));
        self::assertTrue($state['snapshot_blocked']);
        self::assertFalse($state['source_active']);
        self::assertSame(60, (int) $state['remote_step']);
        self::assertCount(4, $state['rras']);
    }

    public function testCollectorPreservesCallerOwnedSourceTransaction(): void
    {
        $state = $this->runNative(array('collector' => 'device', 'failure' => '', 'snapshot_edit' => true, 'source_active' => true));
        self::assertTrue($state['snapshot_blocked']);
        self::assertTrue($state['source_active']);
        self::assertSame(301, (int) $state['caller_before']);
        self::assertSame(300, (int) $state['caller_after']);
        self::assertSame(60, (int) $state['remote_step']);
        self::assertSame(array(1, 2), array_map('intval', array_column($state['rows'], 'id')));
    }

    /** @dataProvider sourceDefinitionInserts */
    public function testReadCommittedSourceSnapshotBlocksDefinitionInsert(string $kind): void
    {
        $state = $this->runNative(array('collector' => 'device', 'failure' => '', 'snapshot_edit' => true, 'source_active' => true, 'source_insert' => $kind));
        self::assertTrue($state['snapshot_blocked']);
        self::assertTrue($state['source_active']);
        self::assertSame(301, (int) $state['caller_before']);
        self::assertSame(300, (int) $state['caller_after']);
        self::assertCount(4, $state['rras']);
        self::assertSame(array(1,3), array_values(array_unique(array_map('intval', array_column($state['rras'], 'consolidation_function_id')))));
    }

    public static function sourceDefinitionInserts(): array
    {
        return array('RRA insertion' => array('rra'), 'CF insertion' => array('cf'));
    }

    public function testReadCommittedCallerRefusesMissingSourceGuardWithoutChangingItsTransaction(): void
    {
        $state = $this->runNative(array('collector' => 'device', 'failure' => 'source-guard-missing', 'source_active' => true, 'entrypoint' => true));
        self::assertFalse($state['result']);
        self::assertTrue($state['source_active']);
        self::assertSame(301, (int) $state['caller_before']);
        self::assertSame(300, (int) $state['caller_after']);
        self::assertFalse($state['parent']);
        self::assertStringContainsString('Source profile catalogs require InnoDB and intact definition guards', implode('\n', $state['log']));
    }

    public function testUnupgradedCollectorVersionIsPreservedBeforeAnyBulkReplication(): void
    {
        foreach (array('engine', 'guard-missing', 'guard-modified') as $failure) {
            $state = $this->runNative(array('collector' => 'bulk', 'failure' => $failure, 'entrypoint' => true, 'class' => 'all'));
            self::assertFalse($state['result']);
            self::assertSame('1.2.33', $state['remote_version']);
            self::assertSame(array('on', 'on'), $state['sync']);
            self::assertNotContains('poller_sync', $state['messages']);
            self::assertContains('poller_sync_failed', $state['messages']);
            self::assertStringContainsString('schema version was retained', implode('\n', $state['log']));
            self::assertSame(array(), array_filter($state['calls'], static fn($call) => str_contains($call[1], 'FROM version')
                || ($call[0] === 'remote' && preg_match('/^(?:INSERT|REPLACE|TRUNCATE|UPDATE|DELETE|DROP|CREATE|ALTER)\b/i', trim($call[1])))));
        }
    }

    public function testCollectorCompletionRefusalRetainsRetryOwnership(): void
    {
        foreach (array('all', 'data') as $class) {
            $state = $this->runNative(array('collector' => 'bulk', 'failure' => 'completion-state', 'entrypoint' => true, 'class' => $class));
            self::assertFalse($state['result']);
            self::assertSame(array('on', 'on'), $state['sync']);
            self::assertFalse($state['source_active']);
            self::assertSame(77, (int) $state['parent']);
            self::assertNotContains('poller_sync', $state['messages']);
            self::assertStringContainsString('completion-state write failed', implode('\n', $state['log']));
        }
    }

    /** @dataProvider collectorScenarios */
    public function testCollectorCopiesParentsBeforeDataDefinitions(string $mode, string $failure): void
    {
        $state = $this->runNative(array('collector' => $mode, 'failure' => $failure));
        if ($failure !== '') {
            self::assertSame(array(1), array_map('intval', array_column($state['rows'], 'id')));
            if (str_starts_with($failure, 'child-') && $failure !== 'child-engine') {
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

    /** @dataProvider collectorEntryPointScenarios */
    public function testCollectorEntryPointsReportReplicationOutcome(string $mode, string $failure, string $class = 'all'): void
    {
        $state = $this->runNative(array('collector' => $mode, 'failure' => $failure, 'entrypoint' => true, 'class' => $class));
        self::assertSame($failure === '', $state['result']);
        if ($mode === 'bulk' && $failure === '' && $class === 'all') {
            self::assertSame('1.2.33', $state['remote_version']);
            self::assertSame(array(), array_filter($state['calls'], static fn($call) => preg_match('/\bversion\b/', $call[1])));
        }
        if ($failure !== 'retry-state') {
            self::assertSame(array($mode === 'bulk' && $failure === '' ? '' : 'on', 'on'), $state['sync']);
        }
        if ($failure === 'retry-state') {
            self::assertSame(array('', 'on'), $state['sync']);
            self::assertSame(array(), $state['hooks']);
            self::assertSame(array(1), array_map('intval', array_column($state['rows'], 'id')));
            self::assertStringContainsString('Unable to mark Poller', implode('\n', $state['log']));
            self::assertSame(array(), array_filter($state['calls'], static fn($call) => $call[0] === 'remote' || $call[0] === 'availability' || $call[0] === 'connect'));
            return;
        }
        if (in_array($failure, array('connect', 'unavailable'), true)) {
            self::assertSame(array(), $state['hooks']);
            self::assertSame(array(), array_filter($state['calls'], static fn($call) => $call[0] === 'remote'));
            self::assertSame(array(1), array_map('intval', array_column($state['rows'], 'id')));
            return;
        }
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
            if ($class === 'all') {
                self::assertNotEmpty($state['hooks']);
            }
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
            foreach (array('', 'copy', 'missing', 'rra', 'cf', 'corrupt', 'missing-rra', 'missing-cf', 'collision', 'engine', 'child-write', 'child-late', 'child-schema', 'child-engine', 'child-corrupt', 'guard-missing', 'guard-modified', 'source-engine', 'source-guard-missing', 'source-guard-modified') as $failure) {
                $cases[$mode . ' ' . ($failure ?: 'custom profile')] = array($mode, $failure);
            }
        }
        $cases['bulk child-delete'] = array('bulk', 'child-delete');
        return $cases;
    }

    public static function collectorEntryPointScenarios(): array
    {
        return array_merge(self::collectorScenarios(), array('bulk retry-state' => array('bulk', 'retry-state'), 'device retry-state' => array('device', 'retry-state'), 'bulk-data retry-state' => array('bulk', 'retry-state', 'data'), 'bulk-data success' => array('bulk', '', 'data'), 'bulk connection failure' => array('bulk', 'connect'), 'bulk-data connection failure' => array('bulk', 'connect', 'data'), 'device connection failure' => array('device', 'connect'), 'device unavailable' => array('device', 'unavailable')));
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
