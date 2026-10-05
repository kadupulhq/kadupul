<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Kadupul\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/Helpers/NativeChildCoverageEvidence.php';

final class MembershipEpochTransactionTest extends TestCase
{
    #[DataProvider('cases')]
    public function testMembershipAndRemovalKeepConfirmedEpochs(string $scenario): void
    {
        $directory = sys_get_temp_dir() . '/membership-epoch-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $coverage = \PHPUnit\Runner\CodeCoverage::instance()->isActive() ? \PHPUnit\Runner\CodeCoverage::instance()->codeCoverage() : null;
        try {
            $process = proc_open(array(PHP_BINARY, '-d', 'auto_prepend_file=', '-d', 'pcov.directory=' . dirname(__DIR__, 2), '-d', 'pcov.exclude=~/(include/vendor|tests)/~', dirname(__DIR__) . '/Fixtures/membership-epoch-native.php', $scenario, $directory, $coverage !== null ? 'coverage' : ''), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
            self::assertIsResource($process);
            $output = stream_get_contents($pipes[1]);
            $errors = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), $errors . $output);
            self::assertSame('', $errors);
            $state = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
            $refused = str_contains($scenario, 'denied') || str_contains($scenario, 'mismatch') || str_contains($scenario, 'prepare') || str_contains($scenario, 'sqlstate') || str_contains($scenario, 'commit') || str_contains($scenario, 'retry') || str_contains($scenario, 'myisam') || str_contains($scenario, 'temporary') || str_contains($scenario, 'metadata-') || str_contains($scenario, 'read-') || str_contains($scenario, 'write-');
            $remove = str_starts_with($scenario, 'remove');
            $caller = str_contains($scenario, 'caller');
            self::assertSame($refused ? 'refused' : 'complete', $state['status']);
            self::assertSame($caller, $state['transaction']);
            $absent = str_contains($scenario, 'absent-group');
            self::assertSame($refused || $remove && $absent ? array(1) : ($remove || $absent ? array() : array(2)), $state['groups']);
            self::assertSame($remove && (!$refused || $absent) ? 0 : 1, $state['parent']);
            $unaffected = str_contains($scenario, 'empty') || str_contains($scenario, 'orphan') || $remove && $absent;
            self::assertSame($refused || $unaffected ? (str_contains($scenario, 'wrap') ? 4294967295 : 7) : (str_contains($scenario, 'wrap') ? 1 : 8), (int) $state['epochs'][42]);
            self::assertSame($remove && !$refused && !$unaffected ? 1 : 0, (int) $state['epochs'][43]);
            self::assertSame(13, (int) $state['epochs'][7]);
            self::assertSame(99, (int) $state['epochs'][44]);
            self::assertSame($caller ? 1 : 0, $state['caller']);
            if (!$refused) {
                self::assertSame(!$unaffected, $state['cache_cleared']);
            }
            if (str_contains($scenario, 'write-')) {
                self::assertSame(0, $state['fault_value']);
            }
            if (str_contains($scenario, 'sqlstate')) {
                self::assertSame(8, $state['fault_value']);
            }
            if (str_contains($scenario, 'pool-swap')) {
                self::assertTrue($state['pool_changed']);
            }
            if (str_contains($scenario, 'later')) {
                self::assertSame(2, $state['epoch_updates']);
                self::assertSame(7, (int) $state['epochs'][1101]);
                self::assertSame(7, (int) $state['epochs'][100]);
            }
            if ($caller) {
                self::assertSame(array(1), $state['after_rollback']['groups']);
                self::assertSame(1, $state['after_rollback']['parent']);
                self::assertSame(str_contains($scenario, 'wrap') ? 4294967295 : 7, (int) $state['after_rollback']['epochs'][42]);
                self::assertSame(0, $state['after_rollback']['caller']);
            }
            if (str_contains($scenario, 'retry')) {
                self::assertSame(array(2), $state['retry']['groups']);
                self::assertSame(8, (int) $state['retry']['epochs'][42]);
            }
            if ($coverage !== null) {
                $reports = glob($directory . '/*.coverage');
                self::assertCount(1, $reports);
                $hits = array('lib/auth.php');
                // Caller units use only captured PDO/savepoints; owned units invoke
                // the legacy transaction functions in lib/database.php.
                if (!$caller) {
                    $hits[] = 'lib/database.php';
                }
                if ($remove) {
                    $hits[] = 'user_group_admin.php';
                }
                $child = \NativeChildCoverageEvidence::load($reports[0], dirname(__DIR__, 2), 'tests/Fixtures/membership-epoch-native.php', $scenario, self::sources(), array('persisted-membership-epochs-observed'), $hits);
                if ($scenario === 'replace-success' || $scenario === 'remove-success') {
                    self::assertSame(32, \NativeChildCoverageEvidence::verifyRejections($reports[0], dirname(__DIR__, 2), 'tests/Fixtures/membership-epoch-native.php', $scenario, self::sources(), array('persisted-membership-epochs-observed'), $hits, 'lib/rrd.php'));
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
        return array('composer.lock', 'tests/composer.lock', 'tests/Symfony/MembershipEpochTransactionTest.php', 'cacti.sql', 'lib/auth.php', 'lib/functions.php', 'tests/Helpers/PhpSource.php', 'lib/database.php', 'user_group_admin.php', 'include/global_constants.php', 'tests/Fixtures/rrd-process-coverage.php', 'tests/Helpers/NativeChildCoverageEvidence.php', 'lib/rrd.php', 'src/Graphing/Infrastructure/Rrd/ProxyCipher.php', 'lib/dsdebug.php', 'lib/rrd_maintenance.php', 'lib/poller.php', 'lib/boost.php', 'lib/api_data_source.php', 'lib/rrdcheck.php', 'lib/dsstats.php');
    }

    public static function cases(): iterable
    {
        foreach (array('replace', 'remove') as $action) {
            foreach (array('success', 'wrap', 'caller-success', 'denied', 'caller-denied', 'mismatch', 'caller-mismatch', 'prepare', 'sqlstate', 'caller-sqlstate', 'commit', 'pool-swap') as $case) {
                yield $action . '-' . $case => array($action . '-' . $case);
            }
            foreach (array('read-group', 'read-user', 'read-snapshot', 'read-late', 'caller-read-group', 'caller-read-user', 'caller-read-snapshot', 'caller-read-late', 'absent-group') as $case) {
                yield $action . '-' . $case => array($action . '-' . $case);
            }
        }
        foreach (array('replace-write-member-add', 'replace-write-member-delete', 'remove-write-child-delete', 'remove-write-parent-delete', 'replace-caller-write-member-add', 'replace-caller-write-member-delete', 'remove-caller-write-child-delete', 'remove-caller-write-parent-delete') as $case) {
            yield $case => array($case);
        }
        yield 'replacement retry' => array('replace-retry');
        yield 'replacement retry in caller' => array('replace-caller-retry');
        yield 'later removal epoch batch fails' => array('remove-later-denied');
        yield 'later removal epoch batch fails in caller' => array('remove-caller-later-denied');
        yield 'empty removal' => array('remove-empty');
        yield 'orphan-only removal' => array('remove-orphan');
        if (str_starts_with(getenv('KADUPUL_MEMBERSHIP_TEST_DSN') ?: '', 'mysql:')) {
            foreach (array('replace', 'remove') as $action) {
                foreach (array('myisam', 'temporary', 'caller-myisam', 'caller-temporary', 'metadata-select', 'metadata-show', 'metadata-fetch', 'caller-metadata-select', 'caller-metadata-show', 'caller-metadata-fetch') as $case) {
                    yield $action . '-' . $case => array($action . '-' . $case);
                }
            }
        }
    }
}
