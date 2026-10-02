<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;

final class AdminPolicyAndMembershipNativeCoverageTest extends TestCase
{
    private static bool $coverageEvidenceChecked = false;

    /** @dataProvider grantCases */
    public function testTypedGrantAddsPreserveOtherItemsTypesAndUsers(string $type, string $field, int $typeId, int $item, bool $error, bool $self = false): void
    {
        $state = $this->runController(array('group' => false, 'operation' => 'add', 'type' => $type, 'field' => $field, 'item' => $item, 'error' => $error, 'self' => $self));
        $expected = array();
        foreach (array(42 => array(100, 101), 43 => array(100)) as $principal => $items) {
            foreach ($items as $existingItem) {
                foreach (range(1, 4) as $existingType) {
                    $expected[] = array('user_id' => $principal, 'item_id' => $existingItem, 'type' => $existingType);
                }
            }
        }
        if (!$error && $item === 102) {
            array_splice($expected, 8, 0, array(array('user_id' => 42, 'item_id' => 102, 'type' => $typeId)));
        }
        self::assertSame($expected, $state['permissions']);
        self::assertSame(array(array('user_id' => 42, 'realm_id' => 7), array('user_id' => 43, 'realm_id' => 9)), $state['realms']);
        foreach ($state['policies'] as $row) {
            foreach (array('policy_graphs', 'policy_trees', 'policy_hosts', 'policy_graph_templates') as $policy) {
                self::assertSame(1, $row[$policy]);
            }
        }
        $this->assertInvalidation($state, false, !$error, $self);
        self::assertSame('', $state['output']);
    }

    public static function grantCases(): array
    {
        $cases = array();
        foreach (array('graph' => array('graphs', 1), 'tree' => array('trees', 2), 'host' => array('hosts', 3), 'graph_template' => array('graph_templates', 4)) as $type => $details) {
            $cases[$type . ' add'] = array($type, $details[0], $details[1], 102, false);
            $cases[$type . ' replace'] = array($type, $details[0], $details[1], 100, false);
            $cases[$type . ' self add'] = array($type, $details[0], $details[1], 102, false, true);
            $cases[$type . ' existing error'] = array($type, $details[0], $details[1], 102, true);
        }
        return $cases;
    }

    /** @dataProvider policyCases */
    public function testPolicyUpdateChangesOnlyPostedPoliciesForTheTarget(bool $group, array $policies, bool $self = false): void
    {
        $state = $this->runController(array('group' => $group, 'operation' => 'policy', 'policies' => $policies, 'self' => $self));
        foreach ($state['policies'] as $row) {
            foreach (array('policy_graphs', 'policy_trees', 'policy_hosts', 'policy_graph_templates') as $policy) {
                self::assertSame($row['id'] === 42 ? ($policies[$policy] ?? 1) : 1, $row[$policy]);
            }
        }
        self::assertCount(12, $state['permissions']);
        $this->assertInvalidation($state, $group, $policies !== array(), $self);
        self::assertSame('', $state['output']);
    }

    public static function policyCases(): array
    {
        return array(
            'user subset' => array(false, array('policy_graphs' => 2, 'policy_hosts' => 2)),
            'user all' => array(false, array('policy_graphs' => 2, 'policy_trees' => 2, 'policy_hosts' => 2, 'policy_graph_templates' => 2)),
            'group subset' => array(true, array('policy_trees' => 2, 'policy_graph_templates' => 2)),
            'self user policy' => array(false, array('policy_graphs' => 2), true),
            'self group policy' => array(true, array('policy_graphs' => 2), true),
            'user none' => array(false, array()),
            'group none' => array(true, array()),
        );
    }

    public function testMembershipAndRealmQueriesUseBothPrincipalAndItem(): void
    {
        $state = $this->runController(array('group' => true, 'operation' => 'membership'));
        self::assertSame(array('target_member' => 1, 'other_member' => 1, 'foreign_member' => 0, 'foreign_group' => 0, 'target_realm' => 1, 'foreign_realm' => 0, 'foreign_realm_owner' => 1, 'missing_group' => 0), $state['membership']);
        self::assertCount(12, $state['permissions']);
        self::assertSame(array(0, 0, 0, 0), array_column($state['reset'], 'reset_perms'));
        self::assertSame('', $state['output']);
    }

    /** @dataProvider failedWriteCases */
    public function testFailedAuthorizationWritesLeavePermissionEpochsUnchanged(bool $group, string $operation): void
    {
        $state = $this->runController(array('group' => $group, 'operation' => $operation, 'type' => 'graph', 'field' => 'graphs', 'item' => 102, 'policies' => array('policy_graphs' => 2), 'write_error' => true));
        $this->assertInvalidation($state, $group, false, false);
        self::assertCount(12, $state['permissions']);
        self::assertFalse($state['controller_returned'], 'Failed writes retain the existing clicked-button redirect/exit instead of returning through the fallback save path.');
        foreach ($state['policies'] as $row) {
            self::assertSame(1, $row['policy_graphs']);
        }
    }

    public static function failedWriteCases(): array
    {
        return array('typed add failure' => array(false, 'add'), 'user policy failure' => array(false, 'policy'), 'group policy failure' => array(true, 'policy'));
    }

    private function assertInvalidation(array $state, bool $group, bool $changed, bool $self): void
    {
        foreach ($state['reset'] as $account) {
            if ($changed && ($account['id'] === 42 || ($group && $account['id'] === 44))) {
                self::assertGreaterThan(0, $account['reset_perms']);
            } else {
                self::assertSame(0, $account['reset_perms']);
            }
        }
        self::assertSame(!$changed, $state['next_valid'], 'An existing target session must observe the persistent epoch on its next request.');
        self::assertSame(!($self && $changed), $state['perms_valid']);
        self::assertSame($self && $changed && !$group ? array('sess_user_id' => 42, 'sess_user_perms_key' => 0) : $state['initial_session'], $state['session']);
    }

    private function runController(array $scenario): array
    {
        $root = dirname(__DIR__, 4);
        $directory = sys_get_temp_dir() . '/admin-permission-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        mkdir($directory . '/include', 0700);
        file_put_contents($directory . '/include/auth.php', '<?php');
        $coverage = $this->getTestResultObject()->getCodeCoverage();
        $command = array(PHP_BINARY, '-d', 'auto_prepend_file=', '-d', 'error_reporting=24575', '-d', 'pcov.directory=' . $root, '-d', 'pcov.exclude=~/(include/vendor|tests)/~', $root . '/tests/Fixtures/admin-permission-native.php', json_encode($scenario, JSON_THROW_ON_ERROR), $directory);
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
                $childCoverage = NativeChildCoverageEvidence::load($reports[0], $root, 'tests/Fixtures/admin-permission-native.php', json_encode($scenario, JSON_THROW_ON_ERROR), array('src/IdentityAccess/Infrastructure/Legacy/PermissionMutation.php', 'src/IdentityAccess/Infrastructure/Legacy/PermissionAssociations.php', 'tests/Unit/Security/Auth/AdminPermissionPersistenceNativeCoverageTest.php', 'tests/Unit/Security/Auth/AdminPolicyAndMembershipNativeCoverageTest.php', 'user_admin.php', 'user_group_admin.php', 'lib/auth.php', 'include/global_constants.php', 'tests/Fixtures/rrd-process-coverage.php', 'tests/Helpers/NativeChildCoverageEvidence.php', 'lib/rrd.php', 'src/Graphing/Infrastructure/Rrd/ProxyCipher.php', 'lib/dsdebug.php', 'lib/rrd_maintenance.php', 'lib/poller.php', 'lib/boost.php', 'lib/api_data_source.php', 'lib/rrdcheck.php', 'lib/dsstats.php'), array_merge(array('admin-state-readback', 'permission-epoch-checked', 'mutation-sql-outcomes-readback'), in_array($scenario['operation'], array('add', 'policy', 'bulk'), true) || ($scenario['operation'] === 'membership' && isset($scenario['replace'])) ? array('next-request-epoch-checked') : array()), array_merge(array($scenario['group'] ? 'user_group_admin.php' : 'user_admin.php', 'lib/auth.php'), $scenario['operation'] === 'bulk' || ($scenario['operation'] === 'membership' && isset($scenario['replace'])) ? array('src/IdentityAccess/Infrastructure/Legacy/PermissionAssociations.php') : array(), in_array($scenario['operation'], array('add', 'policy', 'remove', 'bulk'), true) || ($scenario['operation'] === 'membership' && isset($scenario['replace'])) ? ((!isset($scenario['selected']) || $scenario['selected'] !== array()) && ($scenario['type'] ?? '') !== 'unknown' && !($scenario['error'] ?? false) && ($scenario['operation'] !== 'policy' || ($scenario['policies'] ?? array()) !== array()) ? array('src/IdentityAccess/Infrastructure/Legacy/PermissionMutation.php') : array()) : array()));
                if (!self::$coverageEvidenceChecked) {
                    self::assertSame(33, NativeChildCoverageEvidence::verifyRejections($reports[0], $root, 'tests/Fixtures/admin-permission-native.php', json_encode($scenario, JSON_THROW_ON_ERROR), array('src/IdentityAccess/Infrastructure/Legacy/PermissionMutation.php', 'src/IdentityAccess/Infrastructure/Legacy/PermissionAssociations.php', 'tests/Unit/Security/Auth/AdminPermissionPersistenceNativeCoverageTest.php', 'tests/Unit/Security/Auth/AdminPolicyAndMembershipNativeCoverageTest.php', 'user_admin.php', 'user_group_admin.php', 'lib/auth.php', 'include/global_constants.php', 'tests/Fixtures/rrd-process-coverage.php', 'tests/Helpers/NativeChildCoverageEvidence.php', 'lib/rrd.php', 'src/Graphing/Infrastructure/Rrd/ProxyCipher.php', 'lib/dsdebug.php', 'lib/rrd_maintenance.php', 'lib/poller.php', 'lib/boost.php', 'lib/api_data_source.php', 'lib/rrdcheck.php', 'lib/dsstats.php'), array_merge(array('admin-state-readback', 'permission-epoch-checked', 'mutation-sql-outcomes-readback'), in_array($scenario['operation'], array('add', 'policy', 'bulk'), true) || ($scenario['operation'] === 'membership' && isset($scenario['replace'])) ? array('next-request-epoch-checked') : array()), array_merge(array($scenario['group'] ? 'user_group_admin.php' : 'user_admin.php', 'lib/auth.php'), $scenario['operation'] === 'bulk' || ($scenario['operation'] === 'membership' && isset($scenario['replace'])) ? array('src/IdentityAccess/Infrastructure/Legacy/PermissionAssociations.php') : array(), in_array($scenario['operation'], array('add', 'policy', 'remove', 'bulk'), true) || ($scenario['operation'] === 'membership' && isset($scenario['replace'])) ? ((!isset($scenario['selected']) || $scenario['selected'] !== array()) && ($scenario['type'] ?? '') !== 'unknown' && !($scenario['error'] ?? false) && ($scenario['operation'] !== 'policy' || ($scenario['policies'] ?? array()) !== array()) ? array('src/IdentityAccess/Infrastructure/Legacy/PermissionMutation.php') : array()) : array()), 'lib/rrd.php'));
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
            unlink($directory . '/include/auth.php');
            rmdir($directory . '/include');
            if (is_file($directory . '/state.sqlite')) {
                unlink($directory . '/state.sqlite');
            }
            rmdir($directory);
        }
    }
}
