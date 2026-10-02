<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;

final class AdminPermissionPersistenceNativeCoverageTest extends TestCase
{
    private static bool $coverageEvidenceChecked = false;

    /** @dataProvider epochFailureCases */
    public function testFailedEpochWritesUndoOnlyTheirPermissionUnit(bool $group, string $operation, string $failure, bool $caller): void
    {
        $scenario = array('group' => $group, 'operation' => $operation, 'type' => 'graph', 'kind' => 'graph', 'type_id' => 1, 'replace' => false, $failure => $group ? 44 : 42, 'caller_transaction' => $caller);
        if ($operation === 'membership') {
            $scenario[$failure] = 42;
        }
        $state = $this->runController($scenario);
        self::assertCount(12, $state['permissions']);
        self::assertSame(array(array('group_id' => 42, 'user_id' => 42), array('group_id' => 42, 'user_id' => 44), array('group_id' => 43, 'user_id' => 43)), $state['memberships']);
        foreach ($state['reset'] as $account) {
            self::assertSame(0, $account['reset_perms']);
        }
        self::assertSame($caller, $state['transaction_open']);
        if ($caller) {
            self::assertSame(1, $state['caller_work']);
            self::assertSame(0, $state['after_caller_rollback']['caller_work']);
            self::assertSame($state['permissions'], $state['after_caller_rollback']['permissions']);
        }
        self::assertSame('', $state['output']);
        self::assertSame(array(2), $state['messages']);
    }

    public static function epochFailureCases(): array
    {
        $cases = array();
        foreach (array(false, true) as $group) {
            foreach (array('remove', 'bulk', 'membership') as $operation) {
                foreach (array('epoch_failure', 'epoch_mismatch') as $failure) {
                    foreach (array(false, true) as $caller) {
                        $cases[($group ? 'group ' : 'user ') . $operation . ' ' . $failure . ($caller ? ' caller transaction' : '')] = array($group, $operation, $failure, $caller);
                    }
                }
            }
        }
        return $cases;
    }

    /** @dataProvider epochPartialCases */
    public function testRemovedMemberEpochFailureRetainsIndependentSuccessfulUnits(array $selected): void
    {
        $state = $this->runController(array('group' => true, 'operation' => 'membership', 'replace' => false, 'selected' => $selected, 'epoch_failure' => 42));
        self::assertSame(array(array('group_id' => 42, 'user_id' => 42), array('group_id' => 43, 'user_id' => 43)), $state['memberships']);
        self::assertSame(array(0, 0, 0, 1), array_column($state['reset'], 'reset_perms'));
        self::assertSame(array(41 => true, 42 => true, 43 => true, 44 => false), $state['next_valid_accounts']);
        self::assertSame(array(2), $state['messages']);
        self::assertFalse($state['transaction_open']);
    }

    public static function epochPartialCases(): array
    {
        return array('failed epoch first' => array(array(42,44)), 'failed epoch last' => array(array(44,42)));
    }

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

    /** @dataProvider failedMutationCases */
    public function testNativeSqlFailuresPreserveUnchangedEpochsAndInvalidateOnlySuccessfulWrites(bool $group, string $operation, bool $replace, bool $partial, bool $failureLast = false, string $typeName = 'host'): void
    {
        $membership = $operation === 'membership';
        $selected = $operation === 'remove' ? [100] : ($membership ? ($group ? [42, 44] : [42, 43]) : [100, 101]);
        $failed = $partial ? [$selected[$failureLast ? 1 : 0]] : $selected;
        $typeId = ['graph' => 1, 'tree' => 2, 'host' => 3, 'graph_template' => 4][$typeName];
        $state = $this->runController(['group' => $group, 'operation' => $operation, 'type' => $typeName, 'kind' => 'host', 'type_id' => $typeId, 'replace' => $replace, 'self' => true, 'selected' => $selected, 'failed_ids' => $failed]);
        self::assertCount(count($selected), $state['write_outcomes']);
        self::assertSame($partial ? ($failureLast ? [true, false] : [false, true]) : array_fill(0, count($selected), false), array_column($state['write_outcomes'], 'success'));
        foreach ($state['reset'] as $account) {
            $affected = $partial && ($group && $membership ? $account['id'] === $selected[$failureLast ? 0 : 1] : ($account['id'] === 42 || ($group && $account['id'] === 44)));
            $affected ? self::assertGreaterThan(0, $account['reset_perms']) : self::assertSame(0, $account['reset_perms']);
            if ($operation !== 'remove') {
                self::assertSame(!$affected, $state['next_valid_accounts'][$account['id']]);
            }
        }
        $targetChanged = $partial && (!$group || !$membership || !in_array(42, $failed, true));
        self::assertSame(!$targetChanged, $state['perms_valid']);
        if ($operation !== 'remove') {
            self::assertSame(!$targetChanged, $state['next_valid']);
        }
        self::assertSame($targetChanged && (!$group || $membership) ? ['sess_user_id' => 42, 'sess_user_perms_key' => 0] : $state['initial_session'], $state['session']);
        foreach ($selected as $id) {
            if ($membership) {
                $row = ['group_id' => $group ? 42 : $id, 'user_id' => $group ? $id : 42];
                $exists = in_array($row, $state['memberships'], true);
            } else {
                $row = [$group ? 'group_id' : 'user_id' => 42, 'item_id' => $id, 'type' => $typeId];
                $exists = in_array($row, $state['permissions'], true);
            }
            self::assertSame(in_array($id, $failed, true) ? !$replace : $replace, $exists);
        }
        self::assertSame('', $state['output']);
    }

    public static function failedMutationCases(): array
    {
        $cases = [];
        foreach ([false, true] as $group) {
            foreach (['graph', 'tree', 'host', 'graph_template'] as $typeName) {
                $cases[] = [$group, 'remove', false, false, false, $typeName];
            }
            foreach (['bulk', 'membership'] as $operation) {
                foreach ([false, true] as $replace) {
                    foreach ([false, true] as $partial) {
                        $cases[] = [$group, $operation, $replace, $partial];
                        if ($partial) {
                            $cases[] = [$group, $operation, $replace, true, true];
                        }
                    }
                }
            }
        }
        return $cases;
    }

    /** @dataProvider absentDeleteCases */
    public function testDeletingAnAbsentExceptionLeavesEpochsUnchanged(bool $group, string $operation): void
    {
        $scenario = ['group' => $group, 'operation' => $operation, 'type' => 'host', 'kind' => 'host', 'type_id' => 3, 'replace' => false, 'self' => true];
        if ($operation === 'bulk') {
            $scenario['selected'] = [999];
        } else {
            $scenario['item_id'] = 999;
        }
        $state = $this->runController($scenario);
        self::assertSame([0, 0, 0, 0], array_column($state['reset'], 'reset_perms'));
        self::assertSame($state['initial_session'], $state['session']);
        self::assertTrue($state['perms_valid']);
    }

    public static function absentDeleteCases(): array
    {
        return [[false, 'remove'], [true, 'remove'], [false, 'bulk'], [true, 'bulk']];
    }

    /** @dataProvider emptySelectionCases */
    public function testEmptySelectionPreservesAllEpochsAndSessions(bool $group, string $operation): void
    {
        $state = $this->runController(['group' => $group, 'operation' => $operation, 'kind' => 'host', 'type_id' => 3, 'replace' => false, 'self' => true, 'selected' => []]);
        self::assertSame([], $state['write_outcomes']);
        self::assertSame([0, 0, 0, 0], array_column($state['reset'], 'reset_perms'));
        self::assertSame($state['initial_session'], $state['session']);
        self::assertTrue($state['perms_valid']);
        self::assertSame([41 => true, 42 => true, 43 => true, 44 => true], $state['next_valid_accounts']);
        self::assertCount(12, $state['permissions']);
        self::assertCount(3, $state['memberships']);
    }

    public static function emptySelectionCases(): array
    {
        return [[false, 'bulk'], [true, 'bulk'], [false, 'membership'], [true, 'membership']];
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
                $childCoverage = NativeChildCoverageEvidence::load($reports[0], $root, 'tests/Fixtures/admin-permission-native.php', json_encode($scenario, JSON_THROW_ON_ERROR), array('src/IdentityAccess/Infrastructure/Legacy/PermissionMutation.php', 'src/IdentityAccess/Infrastructure/Legacy/PermissionAssociations.php', 'tests/Unit/Security/Auth/AdminPermissionPersistenceNativeCoverageTest.php', 'tests/Unit/Security/Auth/AdminPolicyAndMembershipNativeCoverageTest.php', 'user_admin.php', 'user_group_admin.php', 'lib/auth.php', 'include/global_constants.php', 'tests/Fixtures/rrd-process-coverage.php', 'tests/Helpers/NativeChildCoverageEvidence.php', 'lib/rrd.php', 'src/Graphing/Infrastructure/Rrd/ProxyCipher.php', 'lib/dsdebug.php', 'lib/rrd_maintenance.php', 'lib/poller.php', 'lib/boost.php', 'lib/api_data_source.php', 'lib/rrdcheck.php', 'lib/dsstats.php'), array_merge(array('admin-state-readback', 'permission-epoch-checked', 'mutation-sql-outcomes-readback'), in_array($scenario['operation'], array('add', 'policy', 'bulk'), true) || ($scenario['operation'] === 'membership' && isset($scenario['replace'])) ? array('next-request-epoch-checked') : array()), array_merge(array($scenario['group'] ? 'user_group_admin.php' : 'user_admin.php', 'lib/auth.php'), $scenario['operation'] === 'bulk' || ($scenario['operation'] === 'membership' && isset($scenario['replace'])) ? array('src/IdentityAccess/Infrastructure/Legacy/PermissionAssociations.php') : array(), in_array($scenario['operation'], array('add', 'policy', 'remove', 'bulk'), true) || ($scenario['operation'] === 'membership' && isset($scenario['replace'])) ? ((!isset($scenario['selected']) || $scenario['selected'] !== array()) && ($scenario['type'] ?? '') !== 'unknown' ? array('src/IdentityAccess/Infrastructure/Legacy/PermissionMutation.php') : array()) : array()));
                if (!self::$coverageEvidenceChecked) {
                    self::assertSame(32, NativeChildCoverageEvidence::verifyRejections($reports[0], $root, 'tests/Fixtures/admin-permission-native.php', json_encode($scenario, JSON_THROW_ON_ERROR), array('src/IdentityAccess/Infrastructure/Legacy/PermissionMutation.php', 'src/IdentityAccess/Infrastructure/Legacy/PermissionAssociations.php', 'tests/Unit/Security/Auth/AdminPermissionPersistenceNativeCoverageTest.php', 'tests/Unit/Security/Auth/AdminPolicyAndMembershipNativeCoverageTest.php', 'user_admin.php', 'user_group_admin.php', 'lib/auth.php', 'include/global_constants.php', 'tests/Fixtures/rrd-process-coverage.php', 'tests/Helpers/NativeChildCoverageEvidence.php', 'lib/rrd.php', 'src/Graphing/Infrastructure/Rrd/ProxyCipher.php', 'lib/dsdebug.php', 'lib/rrd_maintenance.php', 'lib/poller.php', 'lib/boost.php', 'lib/api_data_source.php', 'lib/rrdcheck.php', 'lib/dsstats.php'), array_merge(array('admin-state-readback', 'permission-epoch-checked', 'mutation-sql-outcomes-readback'), in_array($scenario['operation'], array('add', 'policy', 'bulk'), true) || ($scenario['operation'] === 'membership' && isset($scenario['replace'])) ? array('next-request-epoch-checked') : array()), array_merge(array($scenario['group'] ? 'user_group_admin.php' : 'user_admin.php', 'lib/auth.php'), $scenario['operation'] === 'bulk' || ($scenario['operation'] === 'membership' && isset($scenario['replace'])) ? array('src/IdentityAccess/Infrastructure/Legacy/PermissionAssociations.php') : array(), in_array($scenario['operation'], array('add', 'policy', 'remove', 'bulk'), true) || ($scenario['operation'] === 'membership' && isset($scenario['replace'])) ? ((!isset($scenario['selected']) || $scenario['selected'] !== array()) && ($scenario['type'] ?? '') !== 'unknown' ? array('src/IdentityAccess/Infrastructure/Legacy/PermissionMutation.php') : array()) : array()), 'lib/rrd.php'));
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
