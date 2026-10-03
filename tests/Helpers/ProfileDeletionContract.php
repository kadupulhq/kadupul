<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;

abstract class ProfileDeletionContract extends TestCase
{
    /** @dataProvider scenarios */
    public function testDeletionIsAtomicAndReportsRefusals(array $scenario): void
    {
        $state = $this->runNative($scenario);
        $failure = $scenario['failure'] ?? '';
        $expected = $failure !== '' || ($scenario['selected'] ?? array(3)) === array(1,2) ? array(1,2,3) : array(1,2);
        self::assertSame($expected, array_map('intval', array_column($state['tables']['data_source_profiles'], 'id')));
        self::assertSame($expected, array_map('intval', array_column($state['tables']['data_source_profiles_rra'], 'data_source_profile_id')));
        self::assertSame($expected, array_map('intval', array_column($state['tables']['data_source_profiles_cf'], 'data_source_profile_id')));
        $inUse = in_array(1, $scenario['selected'] ?? array(3), true);
        $messages = json_encode($state['messages']);
        if ($inUse) {
            self::assertStringContainsString('Data Source Profiles in use by Data Templates or Data Sources can not be deleted.', $messages);
            self::assertStringContainsString('for user 7', $state['log']);
        } else {
            self::assertStringNotContainsString('profile_in_use', $messages);
        }
        if ($failure !== '') {
            self::assertStringContainsString('profile_delete_failed', $messages);
        }
        if ($failure === '' && $expected === array(1,2,3)) {
            self::assertSame(array(), array_filter($state['calls'], static fn($call) => str_starts_with($call[0], 'DELETE')));
        }
        if ($failure === 'lookup-aborted' || $failure === 'commit' || str_starts_with($failure, 'data_source_profiles')) {
            self::assertSame(1, $state['rollbacks']);
        }
        if (!str_starts_with($failure, 'lookup') && !in_array($failure, array('begin', 'isolation', 'guard-missing', 'guard-modified', 'guard-engine', 'guard-rra-engine', 'guard-cf-engine'), true)) {
            $locking = array_filter($state['calls'], static fn($call) => str_contains($call[0], 'FROM data_template_data') && str_contains($call[0], 'FOR UPDATE') && $call[1] === ($scenario['selected'] ?? array(3)));
            self::assertCount(1, $locking);
        }
    }

    /** @dataProvider pages */
    public function testNativeProfilePagesPreserveTheirContext(array $request, string $needle): void
    {
        $state = $this->runNative(array('request' => $request));
        self::assertStringNotContainsString('Warning:', $state['html']);
        self::assertStringNotContainsString('Fatal error:', $state['html']);
        self::assertStringContainsString($needle, $state['html']);
        self::assertSame(array(1,2,3), array_map('intval', array_column($state['tables']['data_source_profiles'], 'id')));
    }

    /** @dataProvider saveScenarios */
    public function testNativeProfileEditorsPreserveCreationAndRefuseStaleIds(array $request, bool $success, bool $created): void
    {
        $state = $this->runNative(array('request' => $request));
        self::assertStringNotContainsString('Warning:', $state['html']);
        self::assertStringNotContainsString('Fatal error:', $state['html']);
        self::assertArrayHasKey($success ? 1 : 2, $state['messages']);
        self::assertSame($created ? array(1,2,3,4) : array(1,2,3), array_map('intval', array_column($state['tables']['data_source_profiles'], 'id')));
        if (!$success) {
            self::assertSame(array(11,12,13), array_map('intval', array_column($state['tables']['data_source_profiles_rra'], 'id')));
            self::assertSame(array(1,2,3), array_map('intval', array_column($state['tables']['data_source_profiles_cf'], 'data_source_profile_id')));
        }
    }
    public static function saveScenarios(): array
    {
        $profile = array('action' => 'save', 'save_component_profile' => '1', 'name' => 'changed', 'step' => 300, 'heartbeat' => 600, 'x_files_factor' => 0.5, 'consolidation_function_id' => array(1,3));
        $rra = array('action' => 'save', 'save_component_rra' => '1', 'name' => 'new', 'steps' => 1, 'rows' => 100, 'timespan' => 30000);
        return array(
            'edit existing profile' => array($profile + array('id' => 3), true, false),
            'create profile' => array($profile + array('id' => 0), true, true),
            'stale parent id' => array($profile + array('id' => 99), false, false),
            'edit RRA' => array($rra + array('id' => 13, 'profile_id' => 3), true, false),
            'create RRA' => array($rra + array('id' => 0, 'profile_id' => 3), true, false),
            'stale RRA id' => array($rra + array('id' => 99, 'profile_id' => 3), false, false),
            'RRA parent gone' => array($rra + array('id' => 0, 'profile_id' => 99), false, false),
            'RRA belongs elsewhere' => array($rra + array('id' => 12, 'profile_id' => 3), false, false)
        );
    }

    public static function pages(): array
    {
        return array(
            'confirm delete' => array(array('selected_items' => null, 'chk_3' => 'on'), 'Unused profile'),
            'confirm duplicate' => array(array('selected_items' => null, 'chk_3' => 'on', 'drp_action' => '2'), 'title_format'),
            'sampling span' => array(array('action' => 'ajax_span', 'profile_id' => 3, 'rows' => 100, 'span' => 1), 'Hour'),
            'absolute span' => array(array('action' => 'ajax_span', 'profile_id' => 3, 'rows' => 100, 'span' => 60), 'Hour'),
            'invalid span' => array(array('action' => 'ajax_span', 'profile_id' => 3, 'rows' => 0, 'span' => 1), 'N/A'),
            'profile size' => array(array('action' => 'ajax_size', 'id' => 3, 'type' => 'profile', 'cfs' => 0, 'rows' => 100), 'KBytes'),
            'rra size' => array(array('action' => 'ajax_size', 'id' => 3, 'type' => 'rra', 'cfs' => 0, 'rows' => 100), 'KBytes'),
            'rra delete confirmation' => array(array('action' => 'item_remove_confirm', 'id' => 13, 'profile_id' => 3), 'Hourly'),
            'edit unused profile' => array(array('action' => 'edit', 'id' => 3), 'Unused profile'),
            'edit referenced profile' => array(array('action' => 'edit', 'id' => 2), 'Read Only'),
            'edit rra' => array(array('action' => 'item_edit', 'id' => 13, 'profile_id' => 3), 'Hourly'),
            'new rra' => array(array('action' => 'item_edit', 'id' => 0, 'profile_id' => 3), 'Rows'),
        );
    }

    public function testNativeDuplicateCopiesProfileAndItsChildren(): void
    {
        $state = $this->runNative(array('request' => array('drp_action' => '2', 'title_format' => '<profile_title> copy')));
        self::assertSame(array(1,2,3,4), array_map('intval', array_column($state['tables']['data_source_profiles'], 'id')));
        $profile = $state['tables']['data_source_profiles'][3];
        self::assertSame('Unused profile copy', $profile['name']);
        self::assertSame(300, (int) $profile['step']);
        self::assertNotSame('ghi', $profile['hash']);
        foreach (array('data_source_profiles_rra', 'data_source_profiles_cf') as $table) {
            self::assertSame(array(1,2,3,4), array_map('intval', array_column($state['tables'][$table], 'data_source_profile_id')));
        }
        self::assertSame('Hourly', $state['tables']['data_source_profiles_rra'][3]['name']);
        self::assertSame(1, (int) $state['tables']['data_source_profiles_cf'][3]['consolidation_function_id']);
    }

    public function testMissingDuplicateSourceReportsFailureWithoutChanges(): void
    {
        $state = $this->runNative(array('selected' => array(99), 'request' => array('drp_action' => '2', 'title_format' => '<profile_title> copy')));
        self::assertSame(array(1,2,3), array_map('intval', array_column($state['tables']['data_source_profiles'], 'id')));
        self::assertStringContainsString('profile_error', json_encode($state['messages']));
    }

    public static function scenarios(): array
    {
        $cases = array('unused' => array(array()), 'mixed' => array(array('selected' => array(1,2,3))), 'all referenced' => array(array('selected' => array(1,2))));
        foreach (array('guard-engine', 'guard-rra-engine', 'guard-cf-engine','guard-missing','guard-modified','lookup-aborted','lookup-false','lookup-invalid','lookup-invalid-row','lookup-throw','isolation','begin','commit','data_source_profiles','data_source_profiles_rra','data_source_profiles_cf') as $failure) {
            $cases[$failure] = array(array('failure' => $failure));
        }
        return $cases;
    }

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
            $fixture = isset($scenario['collector']) ? 'profile-collector-replication-native.php' : (isset($scenario['guard_unit']) ? 'profile-reference-guard-unit.php' : (isset($scenario['reference_guard']) ? 'profile-reference-race-native.php' : (isset($scenario['upgrade']) ? 'profile-index-upgrade-native.php' : 'profile-deletion-native.php')));
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
