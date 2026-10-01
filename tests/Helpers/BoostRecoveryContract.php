<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;

abstract class BoostRecoveryContract extends TestCase
{
    /** @dataProvider scenarios */
    public function testRecoveryKeepsUnacknowledgedOrChangedSamples(string $scenario): void
    {
        $state = $this->runNative($scenario);
        $failures = array('final-failure', 'partial-failure', 'delete-failure', 'oversized', 'missing-remote', 'missing-local', 'exact-boundary', 'changed-row', 'case-change', 'space-change', 'envelope-overflow', 'main-failure');
        if (str_starts_with($scenario, 'main-')) {
            self::assertSame($scenario === 'main-failure' ? 1 : 0, $state['exit']);
            self::assertSame($scenario === 'main-failure' ? 5 : 2, $state['status']);
            self::assertCount($scenario === 'main-failure' ? 3 : 0, $state['local']);
            self::assertCount($scenario === 'main-failure' ? 0 : 3, $state['remote']);
            self::assertSame($scenario === 'main-failure', $state['pid'] !== false);
            self::assertCount($scenario === 'main-failure' ? 1 : 0, $state['rejectedPackets']);
            return;
        }
        self::assertSame(0, $state['exit']);
        self::assertSame(!in_array($scenario, $failures, true), $state['success']);
        if (in_array($scenario, array('missing-remote', 'missing-local', 'oversized', 'exact-boundary', 'envelope-overflow'), true)) {
            self::assertSame(0, $state['records']);
            self::assertSame(0, $state['insertCalls']);
            self::assertSame(0, $state['deleteCalls']);
            self::assertCount(in_array($scenario, array('oversized','exact-boundary','envelope-overflow'), true) ? 1 : 3, $state['local']);
            self::assertSame(array(), $state['remote']);
            return;
        }
        if (in_array($scenario, array('final-failure', 'partial-failure', 'delete-failure'), true)) {
            $expected = $scenario === 'final-failure' ? 0 : ($scenario === 'partial-failure' ? 1 : 3);
            self::assertSame($expected, $state['records']);
            self::assertCount($expected, $state['remote']);
            self::assertCount($scenario === 'final-failure' ? 1 : 3, $state['local']);
            if ($scenario !== 'delete-failure') {
                self::assertSame(0, $state['deleteCalls']);
            }
            return;
        }
        if (in_array($scenario, array('changed-row','late-row','case-change','space-change'), true)) {
            self::assertSame(3, $state['records']);
            self::assertCount(3, $state['remote']);
            self::assertCount($scenario === 'late-row' ? 1 : 2, $state['local']);
            self::assertSame(999, (int) end($state['local'])['local_data_id']);
            if ($scenario === 'changed-row') {
                self::assertSame('changed value', $state['local'][0]['output']);
                self::assertSame('sample2', $state['remote'][1]['output']);
            }
            if ($scenario === 'case-change' || $scenario === 'space-change') {
                self::assertSame($scenario === 'case-change' ? 'u' : '42 ', $state['local'][0]['output']);
                self::assertSame($scenario === 'case-change' ? 'U' : '42', $state['remote'][1]['output']);
            }
            return;
        }
        if ($scenario === 'case-retry' || $scenario === 'space-retry') {
            self::assertTrue($state['success']);
            self::assertFalse($state['retryBefore']['success']);
            self::assertSame(array(), $state['local']);
            self::assertCount(4, $state['remote']);
            self::assertSame($scenario === 'case-retry' ? 'u' : '42 ', $state['remote'][1]['output']);
            self::assertSame(2, $state['records']);
            return;
        }
        $expected = match ($scenario) {
            'full-packet-exact' => 1, 'chunk-250' => 250, 'chunk-251' => 251, default => 3
        };
        self::assertSame($expected, $state['records']);
        self::assertCount($expected, $state['remote']);
        self::assertSame(array(), $state['local']);
        self::assertSame($scenario === 'chunk-251' ? 2 : 1, $state['deleteCalls']);
        if ($scenario === 'retry') {
            self::assertSame(array('success' => false, 'records' => 1, 'local' => 3, 'remote' => 1), $state['retryBefore']);
            self::assertSame(array(1, 2, 3), array_map('intval', array_column($state['remote'], 'local_data_id')));
        }
        if ($scenario === 'split-boundary') {
            self::assertSame(3, $state['insertCalls']);
        }
        if ($scenario === 'quote-output') {
            self::assertSame("value'quoted\\\\data\n<x>", $state['remote'][0]['output']);
        }
        foreach ($state['queries'] as $query) {
            if (str_starts_with($query['sql'], 'INSERT INTO poller_output_boost')) {
                self::assertLessThanOrEqual($state['limit'] ?: 1000000, strlen($query['sql']) + 1);
            }
            if (str_starts_with($query['sql'], 'DELETE FROM poller_output_boost')) {
                self::assertFalse($query['remote']);
                self::assertStringContainsString('CAST(CONVERT(output USING utf8mb4) AS BINARY) = CAST(CONVERT(? USING utf8mb4) AS BINARY)', $query['sql']);
                self::assertSame(0, count($query['params']) % 4);
            }
        }
    }

    public static function scenarios(): array
    {
        $result = array();
        foreach (array('final-failure', 'partial-failure', 'success', 'delete-failure', 'oversized', 'missing-remote', 'missing-local', 'default-limit', 'split-boundary', 'exact-boundary', 'chunk-250', 'chunk-251', 'late-row', 'changed-row', 'retry', 'quote-output', 'main-failure', 'main-success', 'case-change', 'space-change', 'case-retry', 'space-retry', 'full-packet-exact', 'envelope-overflow') as $scenario) {
            $result[$scenario] = array($scenario);
        }
        return $result;
    }

    protected function useMysql(): bool
    {
        return false;
    }

    private function runNative(string $scenario): array
    {
        $root = dirname(__DIR__, 2);
        $directory = sys_get_temp_dir() . '/native-boost-recovery-' . bin2hex(random_bytes(8));
        mkdir($directory . '/include', 0700, true);
        mkdir($directory . '/lib', 0700);
        copy($root . '/poller_recovery.php', $directory . '/poller_recovery.php');
        file_put_contents($directory . '/include/cli_check.php', '<?php require ' . var_export($root . '/tests/Fixtures/boost-recovery-native.php', true) . ';');
        foreach (array('poller.php', 'boost.php', 'dsstats.php') as $stub) {
            file_put_contents($directory . '/lib/' . $stub, '<?php');
        }
        $coverage = $this->getTestResultObject()->getCodeCoverage();
        try {
            $environment = getenv();
            $environment['BOOST_RECOVERY_ROOT'] = $root;
            $environment['BOOST_RECOVERY_DIRECTORY'] = $directory;
            $environment['BOOST_RECOVERY_SCENARIO'] = $scenario;
            $environment['BOOST_RECOVERY_COVERAGE'] = $coverage !== null ? '1' : '0';
            $environment['BOOST_RECOVERY_MYSQL'] = $this->useMysql() ? '1' : '0';
            $process = proc_open(array(PHP_BINARY, '-d', 'opcache.jit=0', '-d', 'opcache.jit_buffer_size=0', '-d', 'pcov.directory=/', '-d', 'error_reporting=-1', '-d', 'display_errors=stderr', $directory . '/poller_recovery.php'), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, $directory, $environment);
            self::assertIsResource($process);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exit = proc_close($process);
            self::assertSame('', $stderr, $stdout);
            self::assertSame('', $stdout);
            self::assertFileExists($directory . '/result.json');
            $state = json_decode(file_get_contents($directory . '/result.json'), true, flags: JSON_THROW_ON_ERROR);
            if ($coverage !== null) {
                $reports = glob($directory . '/*.coverage');
                self::assertCount(1, $reports);
                foreach ($reports as $report) {
                    $coverage->merge(unserialize(file_get_contents($report)));
                }
            }
            $state['exit'] = $exit;
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
