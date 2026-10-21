<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

require_once dirname(__DIR__, 1) . '/Helpers/PestCodeCoverageCompatibility.php';

use PHPUnit\Framework\TestCase;

abstract class ProfileDeletionContract extends TestCase
{
    use \PestCodeCoverageCompatibility;

    protected function useMysql(): bool
    {
        return false;
    }

    public function testProfileReferenceIndexUpgradeIsIdempotent(): void
    {
        $state = $this->runNative(array('upgrade' => true));
        self::assertContains('data_source_profile_id', $state['indexes']);
        self::assertCount(1, array_filter($state['calls'], static fn($sql) => $sql === 'ALTER TABLE data_template_data ADD INDEX data_source_profile_id (data_source_profile_id)'));
        self::assertSame(2, $state['runs']);
        self::assertCount(8, array_filter($state['calls'], static fn($sql) => str_starts_with($sql, 'CREATE TRIGGER')));
        if ($this->useMysql()) {
            self::assertSame('MUL', $state['audit']['liveKey']);
            self::assertSame($state['audit']['liveKey'], $state['audit']['baselineKey']);
            self::assertSame(1, $state['audit']['recognized']);
            self::assertSame('data_source_profile_id', $state['audit']['index']['Column_name']);
            self::assertSame('BTREE', $state['audit']['index']['Index_type']);
            self::assertSame('', $state['audit']['index']['Null']);
        }
    }

    public function testReferenceGuardInstallationFailureStopsUpgrade(): void
    {
        $state = $this->runNative(array('upgrade' => true, 'guard_failure' => true));
        self::assertStringContainsString('could not be installed', $state['upgrade_error']);
        self::assertStringContainsString('TRIGGER privileges', $state['upgrade_error']);
    }

    public function testIncompleteReferenceIndexStopsUpgrade(): void
    {
        foreach (array('wrong-column', 'unique', 'hidden', 'create-failure') as $failure) {
            $state = $this->runNative(array('upgrade' => true, 'index_failure' => $failure));
            self::assertStringContainsString('reference index is missing or incompatible', $state['upgrade_error']);
            self::assertStringContainsString('rerun the upgrade', $state['upgrade_error']);
        }
    }

    public function testReferenceGuardsMatchFreshSchemaAndFailClosed(): void
    {
        $state = $this->runNative(array('guard_unit' => true));
        self::assertTrue($state['fresh']);
        self::assertTrue($state['valid']);
        self::assertSame(array_fill(0, 9, true), $state['refusals']);
        self::assertSame(array(true, true, true), $state['engineRefusals']);
        self::assertSame(array(true, true, true), $state['invalidIdentifiers']);
    }

    public function testAuditBaselinePreservesProfileReferenceIndex(): void
    {
        $baseline = new PDO('sqlite::memory:');
        $baseline->exec('CREATE TABLE table_indexes (idx_table_name, idx_non_unique, idx_key_name, idx_seq_in_index, idx_column_name, idx_collation, idx_cardinality, idx_sub_part, idx_packed, idx_null, idx_index_type, idx_comment)');
        $baseline->exec('CREATE TABLE table_columns (table_name, table_sequence, table_field, table_type, table_null, table_key, table_default, table_extra, table_collation)');
        foreach (file(dirname(__DIR__, 2) . '/docs/audit_schema.sql') as $statement) {
            if (str_starts_with($statement, 'INSERT INTO `table_indexes`') || str_starts_with($statement, 'INSERT INTO `table_columns`')) {
                $baseline->exec($statement);
            }
        }
        $state = $this->runNative(array('upgrade' => true));
        self::assertContains('data_source_profile_id', $state['indexes']);
        foreach (array_intersect($state['indexes'], array('data_source_profile_id')) as $index) {
            $query = $baseline->prepare('SELECT idx_column_name, idx_non_unique, idx_seq_in_index, idx_index_type FROM table_indexes WHERE idx_table_name = ? AND idx_key_name = ?');
            $query->execute(array('data_template_data', $index));
            self::assertSame(array('idx_column_name' => 'data_source_profile_id', 'idx_non_unique' => 1, 'idx_seq_in_index' => 1, 'idx_index_type' => 'BTREE'), $query->fetch(PDO::FETCH_ASSOC));
        }
        self::assertSame('MUL', $baseline->query("SELECT table_key FROM table_columns WHERE table_name = 'data_template_data' AND table_field = 'data_source_profile_id'")->fetchColumn());
    }

    protected function runNative(array $scenario): array
    {
        $root = dirname(__DIR__, 2);
        $directory = sys_get_temp_dir() . '/native-profile-deletion-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $coverage = $this->getTestResultObject()->getCodeCoverage();
        try {
            $fixture = isset($scenario['collector']) ? 'profile-collector-replication-native.php' : (isset($scenario['guard_unit']) ? 'profile-reference-guard-unit.php' : (isset($scenario['reference_guard']) ? 'profile-reference-race-native.php' : 'profile-index-upgrade-native.php'));
            $command = array(PHP_BINARY, '-d', 'opcache.jit=0', '-d', 'opcache.jit_buffer_size=0', '-d', 'pcov.directory=/', '-d', 'error_reporting=24575', '-d', 'display_errors=stderr', $root . '/tests/Fixtures/' . $fixture, json_encode($scenario, JSON_THROW_ON_ERROR), $directory);
            if ($coverage !== null) {
                $command[] = $directory;
            }
            $environment = getenv();
            $environment['PROFILE_DELETE_MYSQL'] = $this->useMysql() ? '1' : '0';
            $process = proc_open($command, array(1 => array('pipe','w'),2 => array('pipe','w')), $pipes, $directory, $environment);
            self::assertIsResource($process);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), $stderr . $stdout);
            self::assertSame('', $stderr);
            self::assertSame('', $stdout);
            $state = json_decode(file_get_contents($directory . '/result.json'), true, flags: JSON_THROW_ON_ERROR);
            if ($coverage !== null) {
                $reports = glob($directory . '/*.coverage');
                self::assertCount(1, $reports);
                $coverage->merge(unserialize(file_get_contents($reports[0])));
            }
            return $state;
        } finally {
            $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($entries as $entry) {
                $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
            rmdir($directory);
        }
    }
}
