<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;

/**
 * The settings functions moved from the db_* helpers to DBAL adapters. Each
 * statement must do on the supported engines what the helper call it replaced
 * did: same answer, same rows, same db_error(), same log lines. The fixture
 * runs the real lib/database.php in a database of its own.
 */
final class SettingsStorageDatabaseTest extends TestCase
{
    protected function setUp(): void
    {
        if (!getenv('KADUPUL_TEST_MYSQL_DSN')) {
            self::markTestSkipped('KADUPUL_TEST_MYSQL_DSN is required for database contracts');
        }
    }

    public function testEachStatementAnswersAndWritesAsTheHelperDid(): void
    {
        $parity = $this->runNative('contract')['parity'];
        self::assertCount(37, $parity);
        foreach ($parity as $case => $sides) {
            self::assertSame($sides['helper'], $sides['storage'], $case);
            self::assertNull($sides['storage']['thrown'], $case);
        }
        // The cases cover what they say they cover.
        self::assertSame("array (\n  'value' => '300',\n)", $parity['row_accent_folded']['storage']['returns']);
        self::assertSame("array (\n)", $parity['row_miss']['storage']['returns']);
        self::assertSame("array (\n  'empty' => '',\n  'poller_interval' => '300',\n)", $parity['values']['storage']['returns']);
        self::assertSame("array (\n  'value' => 'anon',\n)", $parity['user_row_non_numeric_id']['storage']['returns']);
        // The legacy session counts found rows, so an unchanged upsert reports 1.
        self::assertSame([1, 2, 1], [$parity['write_insert']['storage']['affected'], $parity['write_update']['storage']['affected'], $parity['write_unchanged']['storage']['affected']]);
        self::assertSame(4096, strlen((string) hex2bin(array_column($parity['write_value_4097']['storage']['tables']['settings'], 1, 0)[bin2hex('new')])));
        self::assertSame('false', $parity['write_null_value']['storage']['returns']);
        self::assertStringContainsString('1048', $parity['write_null_value']['storage']['db_error']);
        foreach ($parity as $case => $sides) {
            self::assertSame(1, $sides['storage']['counted'], $case);
        }
    }

    public function testFailuresAreReportedAsTheHelperReportedThem(): void
    {
        $failure = $this->runNative('contract')['failure'];
        self::assertCount(9, $failure);
        foreach ($failure as $case => $places) {
            foreach ($places as $place => $sides) {
                self::assertSame($sides['helper'], $sides['storage'], $case . ' ' . $place);
                // settingValues() reads a failure as no rows; the rest return false.
                self::assertSame($case === 'values' ? "array (\n)" : 'false', $sides['storage']['returns'], $case . ' ' . $place);
                self::assertStringContainsString('1146', $sides['storage']['db_error'], $case . ' ' . $place);
            }
            // Logged outside a transaction, quiet inside one.
            self::assertSame([], $places['transaction']['storage']['logs'], $case);
            $expected = $case === 'row_quiet' ? 0 : 3;
            self::assertCount($expected, $places['outside']['storage']['logs'], $case);
        }
    }

    public function testStorageRunsOnTheLegacySessionInsideItsTransaction(): void
    {
        $share = $this->runNative('contract')['share'];
        self::assertSame(['write' => true, 'legacy_sees_write' => 'in-transaction', 'observer_sees_write' => 0, 'storage_sees_legacy_write' => ['value' => 'uncommitted'], 'observer_sees_legacy_write' => 0, 'rollback' => true, 'after_rollback' => ['storage' => [], 'observer' => 0]], array_diff_key($share, ['session' => true]));
        self::assertNotSame($share['session']['legacy'], $share['session']['observer']);
    }

    public function testALockWaitIsRetriedOnlyOutsideATransaction(): void
    {
        $lock = $this->runNative('contract')['lock'];
        self::assertSame('true', $lock['outside']['returns']);
        self::assertSame([200000], $lock['outside']['pauses']);
        self::assertSame([], $lock['outside']['logs']);
        self::assertSame('outside', $lock['outside']['stored']);

        self::assertSame('false', $lock['inside']['returns']);
        self::assertSame([], $lock['inside']['pauses']);
        self::assertSame([], $lock['inside']['logs']);
        self::assertStringContainsString('1205', $lock['inside']['db_error']);
        self::assertTrue($lock['inside']['still_in_transaction']);
        // The earlier write committed; the refused one left no trace.
        self::assertSame('outside', $lock['inside']['stored']);
    }

    public function testStrictModeThrowsWithoutLoggingAsTheHelperDoes(): void
    {
        foreach ($this->runNative('strict')['strict'] as $case => $sides) {
            self::assertSame($sides['helper'], $sides['storage'], $case);
            self::assertSame(['Error', 'Database operation failed.'], $sides['storage']['thrown'], $case);
            self::assertSame([], $sides['storage']['logs'], $case);
        }
    }

    /**
     * include/global.php reads settings before it loads Composer's
     * autoloader. There the helpers still run, and nothing from Composer or
     * Doctrine is loaded ahead of the CSRF boundary.
     */
    public function testBeforeComposerTheHelpersRunAndNothingIsAutoloaded(): void
    {
        $boot = $this->runNative('bootstrap')['bootstrap'];
        self::assertSame([
            'composer_loaded_before' => false,
            'write' => true,
            'read' => ['value' => 'x'],
            'dbal_loaded' => false,
            'storage_classes' => ['Kadupul\Platform\Infrastructure\Legacy\LegacyConnectionRegistry', 'Kadupul\Platform\Infrastructure\Legacy\LegacyLockRetry', 'Kadupul\Platform\Infrastructure\Legacy\LegacySettingsStorage'],
            'composer_init' => [],
            'vendor_functions' => [],
        ], $boot);
    }

    /** @return array<string, mixed> */
    private function runNative(string $scenario): array
    {
        static $runs = [];
        if (isset($runs[$scenario])) {
            return $runs[$scenario];
        }
        // Arguments stay out of traces: db_connect_real() takes the password.
        $process = proc_open([PHP_BINARY, '-d', 'error_reporting=' . error_reporting(), '-d', 'display_errors=stderr', '-d', 'log_errors=0', '-d', 'zend.exception_ignore_args=1', dirname(__DIR__) . '/Fixtures/settings-storage-native.php', $scenario], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Settings storage fixture did not start');
        }
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $error . $output);
        self::assertSame('', $error);

        return $runs[$scenario] = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
    }
}
