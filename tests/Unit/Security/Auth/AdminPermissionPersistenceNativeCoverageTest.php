<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;

final class AdminPermissionPersistenceNativeCoverageTest extends TestCase
{
    private static bool $coverageEvidenceChecked = false;

    /** @dataProvider realmCases */
    public function testRealmSavesReplaceOnlyTheTargetPrincipalAndResetItsUsers(bool $group, array $realms, bool $self): void
    {
        $state = $this->runController(['group' => $group, 'operation' => 'realm', 'realms' => $realms, 'self' => $self]);
        $principal = $group ? 'group_id' : 'user_id';
        $expected = [];
        sort($realms);
        foreach ($realms as $realm) {
            $expected[] = [$principal => 42, 'realm_id' => $realm];
        }
        $expected[] = [$principal => 43, 'realm_id' => 9];
        self::assertSame($expected, $state['realms']);
        self::assertCount(12, $state['permissions']);
        foreach ($state['reset'] as $account) {
            if ($account['id'] === 42 || ($group && $account['id'] === 44)) {
                self::assertGreaterThan(0, $account['reset_perms']);
            } else {
                self::assertSame(0, $account['reset_perms']);
            }
        }
        if (!$group && $self) {
            self::assertSame(['sess_user_id' => 42, 'sess_user_perms_key' => 0], $state['session']);
        } else {
            self::assertSame($state['initial_session'], $state['session']);
        }
        self::assertSame([1], $state['messages']);
        self::assertSame('', $state['output']);
    }

    public static function realmCases(): array
    {
        return [
            'user replaces realms' => [false, [21, 8], false],
            'self user clears cached permissions' => [false, [21, 8], true],
            'user removes all realms' => [false, [], false],
            'group replaces realms' => [true, [21, 8], false],
            'group removes all realms' => [true, [], false],
        ];
    }

    /** @dataProvider permissionCases */
    public function testPermissionRemovalPreservesOtherTypesItemsAndPrincipals(bool $group, string $typeName, int $typeId, bool $self): void
    {
        $state = $this->runController(['group' => $group, 'operation' => 'remove', 'type' => $typeName, 'self' => $self]);
        $principal = $group ? 'group_id' : 'user_id';
        $expected = [];
        foreach ([42 => [100, 101], 43 => [100]] as $id => $items) {
            foreach ($items as $item) {
                foreach (range(1, 4) as $type) {
                    if ($id === 42 && $item === 100 && $type === $typeId) {
                        continue;
                    }
                    $expected[] = [$principal => $id, 'item_id' => $item, 'type' => $type];
                }
            }
        }
        self::assertSame($expected, $state['permissions']);
        self::assertSame([[$principal => 42, 'realm_id' => 7], [$principal => 43, 'realm_id' => 9]], $state['realms']);
        foreach ($state['reset'] as $account) {
            if ($typeId !== 0 && ($account['id'] === 42 || ($group && $account['id'] === 44))) {
                self::assertGreaterThan(0, $account['reset_perms']);
            } else {
                self::assertSame(0, $account['reset_perms']);
            }
        }
        self::assertSame(!($self && $typeId !== 0), $state['perms_valid']);
        if ($self && !$group && $typeId !== 0) {
            self::assertSame(['sess_user_id' => 42, 'sess_user_perms_key' => 0], $state['session']);
        } else {
            self::assertSame($state['initial_session'], $state['session']);
        }
        self::assertSame('', $state['output']);
    }

    public static function permissionCases(): array
    {
        $cases = [];
        foreach ([false, true] as $group) {
            foreach (['graph' => 1, 'tree' => 2, 'host' => 3, 'graph_template' => 4, 'unknown' => 0] as $name => $id) {
                $cases[($group ? 'group ' : 'user ') . $name] = [$group, $name, $id, false];
                if ($id !== 0) {
                    $cases[($group ? 'self group ' : 'self user ') . $name] = [$group, $name, $id, true];
                }
            }
        }
        return $cases;
    }

    /** @dataProvider bulkCases */
    public function testBulkWritesInvalidateOnlyAffectedPrincipalsAndExistingSessions(bool $group, string $kind, int $type, bool $replace, bool $self): void
    {
        $membership = $kind === 'membership';
        $state = $this->runController(['group' => $group, 'operation' => $membership ? 'membership' : 'bulk', 'kind' => $kind, 'type_id' => $type, 'replace' => $replace, 'self' => $self]);
        foreach ($state['reset'] as $account) {
            $affected = $account['id'] === 42 || ($group && !$membership && $account['id'] === 44);
            if ($affected) {
                self::assertGreaterThan(0, $account['reset_perms']);
            } else {
                self::assertSame(0, $account['reset_perms']);
            }
        }
        self::assertFalse($state['next_valid'], 'The target existing session must invalidate on its next request.');
        self::assertSame(!$self, $state['perms_valid']);
        self::assertSame($self && !$group || $self && $membership ? ['sess_user_id' => 42, 'sess_user_perms_key' => 0] : $state['initial_session'], $state['session']);
        $principal = $group ? 'group_id' : 'user_id';
        $expected = [];
        foreach ([42 => [100, 101], 43 => [100]] as $id => $items) {
            foreach ($items as $item) {
                foreach (range(1, 4) as $permission) {
                    if (!$membership && !$replace && $id === 42 && $item === 100 && $permission === $type) {
                        continue;
                    }
                    $expected[] = [$principal => $id, 'item_id' => $item, 'type' => $permission];
                }
            }
        }
        self::assertSame($expected, $state['permissions']);
        $members = [['group_id' => 42, 'user_id' => 42], ['group_id' => 42, 'user_id' => 44], ['group_id' => 43, 'user_id' => 43]];
        if ($membership && !$replace) {
            array_shift($members);
        }
        self::assertSame($members, $state['memberships']);
        self::assertSame('', $state['output']);
    }

    public static function bulkCases(): array
    {
        $cases = [];
        foreach ([false, true] as $group) {
            foreach (['graph' => 1, 'tree' => 2, 'host' => 3, 'template' => 4, 'membership' => 0] as $kind => $type) {
                foreach ([false, true] as $replace) {
                    foreach ([false, true] as $self) {
                        $cases[] = [$group, $kind, $type, $replace, $self];
                    }
                }
            }
        }
        return $cases;
    }

    private function runController(array $scenario): array
    {
        $root = dirname(__DIR__, 4);
        $directory = sys_get_temp_dir() . '/admin-permission-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        mkdir($directory . '/include', 0700);
        file_put_contents($directory . '/include/auth.php', '<?php');
        $coverage = $this->getTestResultObject()->getCodeCoverage();
        $command = [PHP_BINARY, '-d', 'auto_prepend_file=', '-d', 'error_reporting=24575', '-d', 'pcov.directory=' . $root, '-d', 'pcov.exclude=~/(include/vendor|tests)/~', $root . '/tests/Fixtures/admin-permission-native.php', json_encode($scenario, JSON_THROW_ON_ERROR), $directory];
        if ($coverage !== null) {
            $command[] = 'coverage';
        }
        try {
            $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
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
                $childCoverage = NativeChildCoverageEvidence::load($reports[0], $root, 'tests/Fixtures/admin-permission-native.php', json_encode($scenario, JSON_THROW_ON_ERROR), array('user_admin.php', 'user_group_admin.php', 'lib/auth.php', 'include/global_constants.php', 'tests/Fixtures/rrd-process-coverage.php', 'tests/Helpers/NativeChildCoverageEvidence.php', 'lib/rrd.php', 'src/Graphing/Infrastructure/Rrd/ProxyCipher.php', 'lib/dsdebug.php', 'lib/rrd_maintenance.php', 'lib/poller.php', 'lib/boost.php', 'lib/api_data_source.php', 'lib/rrdcheck.php', 'lib/dsstats.php'), array_merge(array('admin-state-readback', 'permission-epoch-checked'), in_array($scenario['operation'], array('add', 'policy', 'bulk'), true) || ($scenario['operation'] === 'membership' && isset($scenario['replace'])) ? array('next-request-epoch-checked') : array()), array($scenario['group'] ? 'user_group_admin.php' : 'user_admin.php', 'lib/auth.php'));
                if (!self::$coverageEvidenceChecked) {
                    self::assertSame(27, NativeChildCoverageEvidence::verifyRejections($reports[0], $root, 'tests/Fixtures/admin-permission-native.php', json_encode($scenario, JSON_THROW_ON_ERROR), array('user_admin.php', 'user_group_admin.php', 'lib/auth.php', 'include/global_constants.php', 'tests/Fixtures/rrd-process-coverage.php', 'tests/Helpers/NativeChildCoverageEvidence.php', 'lib/rrd.php', 'src/Graphing/Infrastructure/Rrd/ProxyCipher.php', 'lib/dsdebug.php', 'lib/rrd_maintenance.php', 'lib/poller.php', 'lib/boost.php', 'lib/api_data_source.php', 'lib/rrdcheck.php', 'lib/dsstats.php'), array_merge(array('admin-state-readback', 'permission-epoch-checked'), in_array($scenario['operation'], array('add', 'policy', 'bulk'), true) || ($scenario['operation'] === 'membership' && isset($scenario['replace'])) ? array('next-request-epoch-checked') : array()), array($scenario['group'] ? 'user_group_admin.php' : 'user_admin.php', 'lib/auth.php'), 'lib/rrd.php'));
                    self::$coverageEvidenceChecked = true;
                }
                $coverage->merge($childCoverage);
            }
            return json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
        } finally {
            foreach (glob($directory . '/*.coverage') as $report) {
                if (is_file($report . '.json')) {
                    unlink($report . '.json');
                }
                unlink($report);
            }
            if (is_file($directory . '/state.sqlite')) {
                unlink($directory . '/state.sqlite');
            }
            unlink($directory . '/include/auth.php');
            rmdir($directory . '/include');
            rmdir($directory);
        }
    }
}
