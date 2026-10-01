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
        if ($failure === 'commit' || str_starts_with($failure, 'data_source_profiles')) {
            self::assertSame(1, $state['rollbacks']);
        }
        if (!str_starts_with($failure, 'lookup') && $failure !== 'begin') {
            $locking = array_filter($state['calls'], static fn($call) => str_contains($call[0], 'FOR UPDATE') && $call[1] === array(3));
            if ($expected === array(1,2)) {
                self::assertNotEmpty($locking);
            }
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
        foreach (array('lookup-false','lookup-invalid','lookup-throw','begin','commit','data_source_profiles','data_source_profiles_rra','data_source_profiles_cf') as $failure) {
            $cases[$failure] = array(array('failure' => $failure));
        }
        return $cases;
    }

    protected function useMysql(): bool
    {
        return false;
    }

    protected function runNative(array $scenario): array
    {
        $root = dirname(__DIR__, 2);
        $directory = sys_get_temp_dir() . '/native-profile-deletion-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $coverage = $this->getTestResultObject()->getCodeCoverage();
        try {
            $command = array(PHP_BINARY, '-d', 'opcache.jit=0', '-d', 'opcache.jit_buffer_size=0', '-d', 'pcov.directory=/', '-d', 'error_reporting=24575', '-d', 'display_errors=stderr', $root . '/tests/Fixtures/profile-deletion-native.php', json_encode($scenario, JSON_THROW_ON_ERROR), $directory);
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
