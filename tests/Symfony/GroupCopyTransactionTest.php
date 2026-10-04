<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Kadupul\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/Helpers/NativeChildCoverageEvidence.php';

final class GroupCopyTransactionTest extends TestCase
{
    #[DataProvider('cases')]
    public function testCopyPreservesCompletePoliciesAndCallerOwnership(string $scenario): void
    {
        $directory = sys_get_temp_dir() . '/group-copy-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $coverage = \PHPUnit\Runner\CodeCoverage::instance()->isActive() ? \PHPUnit\Runner\CodeCoverage::instance()->codeCoverage() : null;
        try {
            $process = proc_open(array(PHP_BINARY, '-d', 'auto_prepend_file=', '-d', 'pcov.directory=' . dirname(__DIR__, 2), '-d', 'pcov.exclude=~/(include/vendor|tests)/~', dirname(__DIR__) . '/Fixtures/group-copy-native.php', $scenario, $directory, $coverage !== null ? 'coverage' : ''), array(1 => array('pipe','w'), 2 => array('pipe','w')), $pipes);
            self::assertIsResource($process);
            $output = stream_get_contents($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), $error . $output);
            self::assertSame('', $error);
            $state = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
            $bulk = str_contains($scenario, 'bulk');
            $cleanup = str_contains($scenario, 'cleanup-failed');
            $caller = str_contains($scenario, 'caller');
            $success = in_array($scenario, array('success','caller-success','empty'), true);
            self::assertSame($cleanup ? 'thrown' : ($bulk ? null : $success), $state['status']);
            self::assertSame($cleanup ? 'Membership write could not be confirmed' : null, $state['cause']);
            self::assertSame($caller || $cleanup, $state['transaction']);
            self::assertSame($bulk ? array(2) : array(), $state['messages']);
            self::assertSame($caller ? 1 : 0, $state['caller']);
            self::assertCount($success || $bulk || $cleanup ? 1 : 0, $state['copies']);
            if ($success || $bulk) {
                self::assertSame('Copied 1', $state['copies'][0]['name']);
                self::assertSame($bulk ? 'other' : 'policy', $state['copies'][0]['description']);
            }
            $sourcePerms = array_values(array_filter($state['perms'], static fn($row) => (int) $row['group_id'] === 5));
            $otherPerms = array_values(array_filter($state['perms'], static fn($row) => (int) $row['group_id'] === 7));
            self::assertCount($scenario === 'empty' ? 0 : 2, $sourcePerms);
            self::assertSame(11, (int) $otherPerms[0]['item_id']);
            self::assertCount(1, $otherPerms);
            $copiedPerms = array_values(array_filter($state['perms'], static fn($row) => !in_array((int) $row['group_id'], array(5,7), true)));
            $copiedRealms = array_values(array_filter($state['realms'], static fn($row) => !in_array((int) $row['group_id'], array(5,7), true)));
            self::assertCount($bulk || $cleanup ? 1 : ($success && $scenario !== 'empty' ? 2 : 0), $copiedPerms);
            self::assertCount($bulk || $success && $scenario !== 'empty' ? 1 : 0, $copiedRealms);
            if ($bulk) {
                self::assertSame(11, (int) $copiedPerms[0]['item_id']);
                self::assertSame(9, (int) $copiedRealms[0]['realm_id']);
            }
            if (str_contains($scenario, 'sqlstate')) {
                self::assertSame(1, $state['persisted_before_fault']);
            }
            if ($caller || $cleanup) {
                self::assertCount(0, $state['after_rollback']['copies']);
                self::assertCount(3, $state['after_rollback']['perms']);
                self::assertCount(2, $state['after_rollback']['realms']);
                self::assertSame(0, $state['after_rollback']['caller']);
            }
            if ($coverage !== null) {
                $reports = glob($directory . '/*.coverage');
                self::assertCount(1, $reports);
                $hits = array('lib/auth.php', 'user_group_admin.php');
                if (!$caller || $bulk) {
                    $hits[] = 'lib/database.php';
                } if ($bulk) {
                    $hits[] = 'src/IdentityAccess/Infrastructure/Legacy/PermissionAssociations.php';
                }
                $child = \NativeChildCoverageEvidence::load($reports[0], dirname(__DIR__, 2), 'tests/Fixtures/group-copy-native.php', $scenario, self::sources(), array('persisted-group-copy-observed'), $hits);
                if ($scenario === 'success' || $scenario === 'bulk-failure-first') {
                    self::assertSame(34, \NativeChildCoverageEvidence::verifyRejections($reports[0], dirname(__DIR__, 2), 'tests/Fixtures/group-copy-native.php', $scenario, self::sources(), array('persisted-group-copy-observed'), $hits, 'lib/rrd.php'));
                }
                $coverage->merge($child);
            }
        } finally {
            foreach (glob($directory . '/*') as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            } rmdir($directory);
        }
    }
    private static function sources(): array
    {
        return array('composer.lock', 'tests/composer.lock', 'tests/Symfony/GroupCopyTransactionTest.php', 'cacti.sql', 'lib/auth.php', 'lib/functions.php', 'tests/Helpers/PhpSource.php', 'lib/database.php', 'user_group_admin.php', 'src/IdentityAccess/Infrastructure/Legacy/PermissionMutation.php', 'src/IdentityAccess/Infrastructure/Legacy/PermissionAssociations.php', 'include/global_constants.php', 'tests/Fixtures/rrd-process-coverage.php', 'tests/Helpers/NativeChildCoverageEvidence.php', 'lib/rrd.php', 'src/Graphing/Infrastructure/Rrd/ProxyCipher.php', 'lib/dsdebug.php', 'lib/rrd_maintenance.php', 'lib/poller.php', 'lib/boost.php', 'lib/api_data_source.php', 'lib/rrdcheck.php', 'lib/dsstats.php');
    }
    public static function cases(): iterable
    {
        foreach (array('cleanup-failed','caller-cleanup-failed','success','caller-success','denied','caller-denied','prepare','caller-prepare','parent-sqlstate','child-sqlstate','caller-child-sqlstate','mismatch','caller-mismatch','source-read','late-source','commit','empty','absent','bulk-failure-first','bulk-success-first','caller-bulk-failure-first') as $case) {
            yield $case => array($case);
        }
    }
}
