<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;

final class PermissionMutationNativeTest extends TestCase
{
    private static bool $evidenceChecked = false;

    /** @dataProvider cases */
    public function testAtomicMutationAndEpochOutcomes(array $scenario): void
    {
        $scenario['engine'] = getenv('PERMISSION_MUTATION_TEST_ENGINE') ?: 'sqlite';
        $state = $this->runNative($scenario);
        $failure = $scenario['failure'] ?? '';
        $failed = $failure !== '';
        self::assertSame(!$failed, $state['result']);
        self::assertSame($scenario['caller'] ?? false, $state['transaction_open']);
        self::assertNull($state['error']);
        $epochs = array_column($state['epochs'], 'reset_perms', 'id');
        $old = $scenario['epoch'] ?? 7;
        self::assertSame($failed ? $old : ($old === 4294967295 ? 1 : $old + 1), (int) $epochs[42]);
        self::assertSame(0, (int) $epochs[41]);
        self::assertSame(7, (int) $epochs[43]);
        $group = $scenario['group'] ?? false;
        $kind = $scenario['kind'] ?? 'typed';
        self::assertSame(!$failed && $group && $kind !== 'membership' ? 8 : 7, (int) $epochs[44]);
        self::assertCount(!$failed && $kind === 'typed' ? 2 : 3, $state['permissions']);
        self::assertCount(!$failed && $kind === 'membership' ? 2 : 3, $state['memberships']);
        self::assertSame(!$failed && $kind === 'policy' ? 1 : 2, $state['policy']);
        if ($scenario['caller'] ?? false) {
            self::assertSame(1, $state['caller_work']);
            self::assertSame(0, $state['rollback_caller_work']);
            self::assertSame(2, $state['rollback_permissions']);
        }
        if ($kind === 'membership' && !$group) {
            $queries = $state['first_mutation_queries'];
            $groupLock = array_search('SELECT id FROM user_auth_group WHERE id = ?' . ($scenario['engine'] === 'sqlite' ? '' : ' FOR UPDATE'), $queries, true);
            $userLock = array_search('SELECT id, reset_perms FROM user_auth WHERE id IN (?) ORDER BY id' . ($scenario['engine'] === 'sqlite' ? '' : ' FOR UPDATE'), $queries, true);
            self::assertIsInt($groupLock);
            self::assertIsInt($userLock);
            self::assertLessThan($userLock, $groupLock);
        }
    }

    public static function cases(): array
    {
        $cases = array();
        foreach (array(false, true) as $group) {
            foreach (array('typed', 'membership', 'policy') as $kind) {
                foreach (array('', 'epoch', 'mismatch') as $failure) {
                    foreach (array(false, true) as $caller) {
                        $cases[($group ? 'group ' : 'user ') . $kind . ' ' . $failure . ($caller ? ' caller' : '')] = array(array('group' => $group, 'kind' => $kind, 'failure' => $failure, 'caller' => $caller, 'failed_user' => $group && $kind !== 'membership' ? 44 : 42));
                    }
                }
            }
        }
        foreach (array(0, 4294967295) as $epoch) {
            $cases['uint32 epoch ' . $epoch] = array(array('epoch' => $epoch));
        }
        $cases['legacy false mutation outcome'] = array(array('failure' => 'mutation'));
        return $cases;
    }

    /** @dataProvider batchCases */
    public function testMaximumConfiguredBatchHasBoundedMetadataAndEpochWork(bool $group, int $members): void
    {
        $scenario = array('engine' => getenv('PERMISSION_MUTATION_TEST_ENGINE') ?: 'sqlite', 'kind' => 'batch', 'group' => $group, 'size' => 5000, 'members' => $members);
        $state = $this->runNative($scenario);
        self::assertSame('permsg', $state['result']);
        self::assertNull($state['error']);
        self::assertFalse($state['transaction_open']);
        self::assertCount(1, $state['permissions']);
        self::assertSame(8, (int) array_column($state['epochs'], 'reset_perms', 'id')[42]);
        self::assertLessThanOrEqual(3 * $scenario['size'] + 20, $state['mutation_queries']);
        self::assertSame(array(), $state['messages']);
        if ($group) {
            self::assertCount($members + 2, $state['epochs']);
            foreach ($state['epochs'] as $account) {
                self::assertSame($account['id'] == 41 ? 0 : ($account['id'] == 43 ? 7 : 8), (int) $account['reset_perms']);
            }
        }
    }

    public static function batchCases(): array
    {
        return array('user' => array(false, 2), 'group with 2500 members' => array(true, 2500));
    }

    public function testFailureInLaterEpochChunkRollsBackEarlierChunkAndPermission(): void
    {
        $state = $this->runNative(array('engine' => getenv('PERMISSION_MUTATION_TEST_ENGINE') ?: 'sqlite', 'group' => true, 'members' => 2500, 'failure' => 'epoch', 'failed_user' => 1500));
        self::assertFalse($state['result']);
        self::assertCount(3, $state['permissions']);
        self::assertFalse($state['transaction_open']);
        self::assertCount(2502, $state['epochs']);
        foreach ($state['epochs'] as $account) {
            self::assertSame($account['id'] == 41 ? 0 : 7, (int) $account['reset_perms']);
        }
    }

    public function testSupportedEngineRefusesNontransactionalAndTemporaryTablesBeforeMutation(): void
    {
        $engine = getenv('PERMISSION_MUTATION_TEST_ENGINE');
        if (!$engine) {
            self::markTestSkipped('Actual MySQL/MariaDB table-contract cases require the isolated engine runner.');
        }
        foreach (array('myisam', 'shadow') as $failure) {
            $state = $this->runNative(array('engine' => $engine, 'failure' => $failure));
            self::assertFalse($state['result']);
            self::assertSame(RuntimeException::class, $state['error']);
            self::assertCount(3, $state['permissions']);
            self::assertFalse($state['transaction_open']);
        }
    }

    private function runNative(array $scenario): array
    {
        $root = dirname(__DIR__, 4);
        $directory = sys_get_temp_dir() . '/permission-mutation-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($directory, 0700));
        $coverage = $this->getTestResultObject()->getCodeCoverage();
        $encoded = json_encode($scenario, JSON_THROW_ON_ERROR);
        $command = array(PHP_BINARY, '-d', 'auto_prepend_file=', '-d', 'error_reporting=24575', '-d', 'pcov.directory=' . $root, '-d', 'pcov.exclude=~/(include/vendor|tests)/~', $root . '/tests/Fixtures/permission-mutation-native.php', $encoded, $directory);
        if ($coverage !== null) {
            $command[] = 'coverage';
        }
        try {
            $process = proc_open($command, array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
            self::assertIsResource($process);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), $stderr . $stdout);
            self::assertSame('', $stderr);
            if ($coverage !== null) {
                $reports = glob($directory . '/*.coverage');
                self::assertCount(1, $reports);
                require_once $root . '/tests/Helpers/NativeChildCoverageEvidence.php';
                $sources = array('src/IdentityAccess/Infrastructure/Legacy/PermissionMutation.php', 'src/IdentityAccess/Infrastructure/Legacy/PermissionAssociations.php', 'lib/auth.php', 'lib/database.php', 'include/global_constants.php', 'tests/Unit/Security/Auth/PermissionMutationNativeTest.php', 'tests/Fixtures/rrd-process-coverage.php', 'tests/Helpers/NativeChildCoverageEvidence.php', 'lib/rrd.php', 'src/Graphing/Infrastructure/Rrd/ProxyCipher.php', 'lib/dsdebug.php', 'lib/rrd_maintenance.php', 'lib/poller.php', 'lib/boost.php', 'lib/api_data_source.php', 'lib/rrdcheck.php', 'lib/dsstats.php');
                $markers = array('mutation-and-epochs-readback', 'transaction-ownership-readback');
                $hits = array('src/IdentityAccess/Infrastructure/Legacy/PermissionMutation.php');
                if (!in_array($scenario['failure'] ?? '', array('myisam', 'shadow'), true)) {
                    $hits[] = 'lib/database.php';
                }
                if (($scenario['kind'] ?? '') === 'batch') {
                    $hits[] = 'src/IdentityAccess/Infrastructure/Legacy/PermissionAssociations.php';
                }
                $child = NativeChildCoverageEvidence::load($reports[0], $root, 'tests/Fixtures/permission-mutation-native.php', $encoded, $sources, $markers, $hits);
                if (!self::$evidenceChecked) {
                    self::assertSame(29, NativeChildCoverageEvidence::verifyRejections($reports[0], $root, 'tests/Fixtures/permission-mutation-native.php', $encoded, $sources, $markers, $hits, 'lib/rrd.php'));
                    self::$evidenceChecked = true;
                }
                $coverage->merge($child);
            }
            return json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
        } finally {
            foreach (glob($directory . '/*') as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($directory);
        }
    }
}
