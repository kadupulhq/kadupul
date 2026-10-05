<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Kadupul\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/Helpers/NativeChildCoverageEvidence.php';

final class UserCopyTransactionTest extends TestCase
{
    #[DataProvider('existingTables')]
    public function testRefusedFixtureCreationPreservesUnownedTables(string $table): void
    {
        $path = tempnam(sys_get_temp_dir(), 'copy-existing-');
        $db = new \PDO('sqlite:' . $path);
        $db->exec('CREATE TABLE ' . $table . '(owned_value TEXT)');
        $db->exec('INSERT INTO ' . $table . " VALUES('existing-owner')");
        try {
            $environment = getenv();
            $environment['KADUPUL_USER_COPY_TEST_DSN'] = 'sqlite:' . $path;
            $process = proc_open(array(PHP_BINARY, '-d', 'auto_prepend_file=', dirname(__DIR__) . '/Fixtures/user-copy-native.php', 'healthy', sys_get_temp_dir()), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, null, $environment);
            self::assertIsResource($process);
            $output = stream_get_contents($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertNotSame(0, proc_close($process), $output . $error);
            self::assertStringContainsString('Actual table creation failed', $output . $error);
            self::assertSame('existing-owner', $db->query('SELECT owned_value FROM ' . $table)->fetchColumn());
            self::assertSame(array($table), $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name != 'sqlite_sequence' ORDER BY name")->fetchAll(\PDO::FETCH_COLUMN));
        } finally {
            $db = null;
            unlink($path);
        }
    }

    public static function existingTables(): iterable
    {
        yield 'before any owned creation' => array('user_auth');
        yield 'after three owned creations' => array('settings_user');
    }

    #[DataProvider('cases')]
    public function testCompleteCopyRefusesPartialPoliciesAndRetainsCallerOwnership(string $scenario): void
    {
        $root = dirname(__DIR__, 2);
        $directory = sys_get_temp_dir() . '/user-copy-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $coverage = \PHPUnit\Runner\CodeCoverage::instance()->isActive() ? \PHPUnit\Runner\CodeCoverage::instance()->codeCoverage() : null;
        try {
            $process = proc_open(array(PHP_BINARY, '-d', 'auto_prepend_file=', '-d', 'pcov.directory=' . $root, '-d', 'pcov.exclude=~/(include/vendor|tests)/~', $root . '/tests/Fixtures/user-copy-native.php', $scenario, $directory, $coverage !== null ? 'coverage' : ''), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
            self::assertIsResource($process);
            $output = stream_get_contents($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), $error . $output);
            self::assertSame('', $error);
            $state = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
            self::assertSame($scenario, $state['case']);
            self::assertSame(hash_file('sha256', $root . '/lib/auth.php'), $state['source_sha256']);
            $denied = str_starts_with($scenario, 'delete-') || str_starts_with($scenario, 'insert-') || in_array($scenario, array('new-denied', 'caller-denied', 'update-user_auth', 'membership-denied', 'epoch-denied', 'commit-denied', 'state-write', 'noexecute-state'), true);
            self::assertSame($denied, $state['complete_rollback']);
            self::assertSame(str_starts_with($scenario, 'caller-'), $state['caller_owned']);
            if (in_array($scenario, array('commit-ended', 'rollback-ended'), true)) {
                self::assertNull($state['result']);
                self::assertSame(\RuntimeException::class, $state['error']);
            } elseif ($denied) {
                self::assertFalse($state['result']);
            } else {
                self::assertGreaterThan(0, (int) $state['result']);
            }
            if ($coverage !== null) {
                $reports = glob($directory . '/*.coverage');
                self::assertCount(1, $reports);
                $markers = array('complete-user-copy-observed');
                $hits = array('lib/auth.php', 'lib/database.php', 'lib/html_validate.php');
                $child = \NativeChildCoverageEvidence::load($reports[0], $root, 'tests/Fixtures/user-copy-native.php', $scenario, self::sources(), $markers, $hits);
                if (in_array($scenario, array('healthy', 'membership-denied'), true)) {
                    self::assertSame(count(self::sources()) + 11, \NativeChildCoverageEvidence::verifyRejections($reports[0], $root, 'tests/Fixtures/user-copy-native.php', $scenario, self::sources(), $markers, $hits, 'lib/rrd.php'));
                }
                $coverage->merge($child);
            }
        } finally {
            foreach (glob($directory . '/*') as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($directory);
        }
    }

    private static function sources(): array
    {
        return array('composer.lock', 'tests/composer.lock', 'tests/Symfony/UserCopyTransactionTest.php', 'cacti.sql', 'lib/auth.php', 'lib/functions.php', 'tests/Helpers/PhpSource.php', 'lib/database.php', 'lib/html_validate.php', 'include/global_constants.php', 'tests/Fixtures/rrd-process-coverage.php', 'tests/Helpers/NativeChildCoverageEvidence.php', 'lib/rrd.php', 'src/Graphing/Infrastructure/Rrd/ProxyCipher.php', 'lib/dsdebug.php', 'lib/rrd_maintenance.php', 'lib/poller.php', 'lib/boost.php', 'lib/api_data_source.php', 'lib/rrdcheck.php', 'lib/dsstats.php');
    }

    public static function cases(): iterable
    {
        foreach (array('healthy', 'self-overwrite', 'new-healthy', 'new-denied', 'caller-healthy', 'caller-denied', 'delete-user_auth_perms', 'delete-user_auth_realm', 'delete-settings_user', 'delete-settings_tree', 'insert-user_auth_perms', 'insert-user_auth_realm', 'insert-settings_user', 'insert-settings_tree', 'update-user_auth', 'membership-denied', 'epoch-denied', 'epoch-maximum', 'override-epoch', 'override-state', 'commit-denied', 'state-write', 'noexecute-state', 'hook-throw', 'caller-hook-throw', 'commit-ended', 'rollback-ended') as $scenario) {
            yield $scenario => array($scenario);
        }
    }
}
