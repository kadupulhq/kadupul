<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

require_once dirname(__DIR__, 3) . '/Helpers/PestCodeCoverageCompatibility.php';

use PHPUnit\Framework\TestCase;

final class AdminPermissionPersistenceNativeCoverageTest extends TestCase
{
    use \PestCodeCoverageCompatibility;

    private static bool $coverageEvidenceChecked = false;
    private static bool $parentCoverageEvidenceChecked = false;
    private static bool $userCoverageEvidenceChecked = false;

    /** @dataProvider affectedUserCases */
    public function testMembershipAdditionRequiresItsLockedUser(bool $group, bool $associate, bool $missing, bool $caller): void
    {
        $state = $this->runController(array('group' => $group, 'operation' => 'membership', 'replace' => $associate, 'selected' => array(43), 'affected_user_missing' => $missing, 'parent_contract' => true, 'caller_transaction' => $caller));
        $denied = $associate && $missing;
        self::assertSame($denied ? array(2) : array(), $state['messages']);
        self::assertSame(array(), $state['parent_refusal_logs']);
        self::assertSame($caller, $state['transaction_open']);
        $target = array_values(array_filter($state['memberships'], static fn($row) => (int) $row['group_id'] === ($group ? 42 : 43) && (int) $row['user_id'] === ($group ? 43 : 42)));
        self::assertCount($associate && !$missing ? 1 : 0, $target);
        if ($denied) {
            self::assertSame(array(), $state['write_outcomes']);
            self::assertSame($state['parent_before_state']['memberships'], $state['memberships']);
            self::assertSame($state['parent_before_state']['reset'], $state['reset']);
        } else {
            self::assertCount(1, $state['write_outcomes']);
            self::assertTrue($state['write_outcomes'][0]['success']);
            $epochs = array_column($state['reset'], 'reset_perms', 'id');
            if (!$missing) {
                self::assertSame(1, $epochs[$group ? 43 : 42]);
            } else {
                self::assertSame($state['parent_before_state']['reset'], $state['reset']);
            }
        }
        if ($caller) {
            self::assertSame(1, $state['caller_work']);
            self::assertTrue($state['caller_rollback_confirmed']);
            self::assertSame(0, $state['after_caller_rollback']['caller_work']);
        }
    }

    public static function affectedUserCases(): iterable
    {
        foreach (array(false, true) as $group) {
            foreach (array(false, true) as $associate) {
                foreach (array(false, true) as $missing) {
                    foreach (array(false, true) as $caller) {
                        yield ($group ? 'group ' : 'user ') . ($associate ? 'add ' : 'remove ') . ($missing ? 'missing ' : 'present ') . ($caller ? 'caller' : 'owned') => array($group, $associate, $missing, $caller);
                    }
                }
            }
        }
    }

    /** @dataProvider affectedUserReadFaults */
    public function testFailedLockedUserReadRefusesMembership(bool $group, string $fault, bool $caller): void
    {
        $state = $this->runController(array('group' => $group, 'operation' => 'membership', 'replace' => true, 'selected' => array(43), 'user_read_fault' => true, 'parent_read_fault' => $fault, 'parent_contract' => true, 'caller_transaction' => $caller));
        self::assertSame(array(2), $state['messages']);
        self::assertSame(array(), $state['write_outcomes']);
        self::assertSame(array(), $state['parent_refusal_logs']);
        self::assertSame($state['parent_before_state']['memberships'], $state['memberships']);
        self::assertSame($state['parent_before_state']['reset'], $state['reset']);
        self::assertSame($caller, $state['transaction_open']);
        if ($caller) {
            self::assertSame(1, $state['caller_work']);
            self::assertSame(0, $state['after_caller_rollback']['caller_work']);
        }
    }

    public static function affectedUserReadFaults(): iterable
    {
        foreach (array(false, true) as $group) {
            foreach (array('early', 'late') as $fault) {
                foreach (array(false, true) as $caller) {
                    yield ($group ? 'group ' : 'user ') . $fault . ($caller ? ' caller' : ' owned') => array($group, $fault, $caller);
                }
            }
        }
    }

    /** @dataProvider affectedUserCleanupFaults */
    public function testUnconfirmedUserCleanupPreservesOriginalError(bool $group, bool $caller, bool|string $fault): void
    {
        $state = $this->runController(array('group' => $group, 'operation' => 'membership', 'replace' => true, 'selected' => array(43), 'affected_user_missing' => true, 'parent_cleanup_failure' => $fault, 'parent_contract' => true, 'caller_transaction' => $caller));
        self::assertTrue($state['child_failed']);
        self::assertTrue($state['transaction_open']);
        self::assertSame(array(), $state['messages']);
        self::assertSame(array(), $state['parent_refusal_logs']);
        self::assertSame(array(), $state['write_outcomes']);
        self::assertSame($state['parent_before_state']['memberships'], $state['memberships']);
        self::assertSame($state['parent_before_state']['reset'], $state['reset']);
        if ($caller) {
            self::assertSame(1, $state['caller_work']);
            self::assertFalse($state['caller_rollback_confirmed']);
            self::assertSame(1, $state['caller_cleanup_state']['caller_work']);
        }
    }

    public static function affectedUserCleanupFaults(): iterable
    {
        foreach (array(false, true) as $group) {
            foreach (array(false, true) as $caller) {
                yield ($group ? 'group ' : 'user ') . ($caller ? 'caller' : 'owned') => array($group, $caller, true);
            }
            foreach (array('executed-hy000', 'not-executed-hy000', 'active') as $fault) {
                yield ($group ? 'group ' : 'user ') . $fault => array($group, $fault !== 'active', $fault);
            }
        }
    }

    /** @dataProvider reverseMembershipCases */
    public function testReverseMembershipRequiresAParentOnlyForAdmission(bool $associate, bool $missing, bool $caller): void
    {
        $state = $this->runController(array('group' => false, 'operation' => 'membership', 'replace' => $associate, 'selected' => array(43), 'selected_group_missing' => $missing, 'parent_contract' => true, 'caller_transaction' => $caller));
        self::assertSame($caller, $state['transaction_open']);
        self::assertSame(array(), $state['parent_refusal_logs']);
        $denied = $associate && $missing;
        self::assertSame($denied ? array(2) : array(), $state['messages']);
        if ($denied) {
            self::assertSame(array(), $state['write_outcomes']);
            self::assertSame($state['parent_before_state']['memberships'], $state['memberships']);
            self::assertSame($state['parent_before_state']['reset'], $state['reset']);
        } else {
            self::assertCount(1, $state['write_outcomes']);
            self::assertTrue($state['write_outcomes'][0]['success']);
            $target = array_values(array_filter($state['memberships'], static fn($row) => (int) $row['group_id'] === 43 && (int) $row['user_id'] === 42));
            self::assertCount($associate ? 1 : 0, $target);
            self::assertSame(1, $state['reset'][1]['reset_perms']);
        }
        if ($caller) {
            self::assertSame(1, $state['caller_work']);
            self::assertTrue($state['caller_rollback_confirmed']);
            self::assertSame(0, $state['after_caller_rollback']['caller_work']);
            self::assertSame($state['parent_before_state']['permissions'], $state['after_caller_rollback']['permissions']);
        }
    }

    public static function reverseMembershipCases(): iterable
    {
        foreach (array(false, true) as $associate) {
            foreach (array(false, true) as $missing) {
                foreach (array(false, true) as $caller) {
                    yield ($associate ? 'add' : 'remove') . ($missing ? ' missing' : ' present') . ($caller ? ' caller' : ' owned') => array($associate, $missing, $caller);
                }
            }
        }
    }

    /** @dataProvider parentCleanupFailures */
    public function testUnconfirmedParentCleanupPreservesTheOriginalError(bool $group, string $operation, bool $caller, bool|string $fault): void
    {
        $scenario = array('group' => $group, 'operation' => $operation, 'type' => 'graph', 'kind' => 'graph', 'type_id' => 1, 'parent_contract' => true, 'parent_cleanup_failure' => $fault, 'caller_transaction' => $caller);
        $scenario[$group ? 'parent_missing' : 'selected_group_missing'] = true;
        if ($operation !== 'remove') {
            $scenario['replace'] = true;
            $scenario['selected'] = array($group ? 109 : 43);
        }
        $state = $this->runController($scenario);
        self::assertTrue($state['child_failed']);
        self::assertTrue($state['transaction_open']);
        self::assertSame(array(), $state['messages']);
        self::assertSame(array(), $state['parent_refusal_logs']);
        self::assertSame(array(), $state['write_outcomes']);
        foreach (array('permissions', 'memberships', 'reset') as $component) {
            self::assertSame($state['parent_before_state'][$component], $state[$component]);
        }
        if ($caller) {
            self::assertSame(1, $state['caller_work']);
            self::assertFalse($state['caller_rollback_confirmed']);
            self::assertSame(1, $state['caller_cleanup_state']['caller_work']);
            self::assertArrayNotHasKey('after_caller_rollback', $state);
        }
    }

    public static function parentCleanupFailures(): iterable
    {
        foreach (array(array(true, 'remove'), array(true, 'bulk'), array(false, 'membership')) as [$group, $operation]) {
            foreach (array(false, true) as $caller) {
                yield ($group ? 'group ' : 'user ') . $operation . ($caller ? ' caller' : ' owned') => array($group, $operation, $caller, true);
            }
            foreach (array('executed-hy000', 'not-executed-hy000', 'active') as $fault) {
                $caller = $fault !== 'active';
                yield ($group ? 'group ' : 'user ') . $operation . ' ' . $fault => array($group, $operation, $caller, $fault);
            }
        }
    }

    /** @dataProvider parentReadFaults */
    public function testFailedParentReadReceiptRefusesTheGroupWrite(string $fault, bool $caller): void
    {
        $state = $this->runController(array('group' => true, 'operation' => 'remove', 'type' => 'graph', 'type_id' => 1, 'parent_contract' => true, 'parent_read_fault' => $fault, 'caller_transaction' => $caller));
        self::assertSame(array(2), $state['messages']);
        self::assertSame(array(), $state['parent_refusal_logs']);
        self::assertSame(array(), $state['write_outcomes']);
        foreach (array('permissions', 'memberships', 'reset') as $component) {
            self::assertSame($state['parent_before_state'][$component], $state[$component]);
        }
        self::assertSame($caller, $state['transaction_open']);
        if ($caller) {
            self::assertSame(1, $state['caller_work']);
            self::assertSame(0, $state['after_caller_rollback']['caller_work']);
        }
    }

    public static function parentReadFaults(): iterable
    {
        foreach (array('early', 'late') as $fault) {
            foreach (array(false, true) as $caller) {
                yield $fault . ($caller ? ' caller' : ' owned') => array($fault, $caller);
            }
        }
    }

    /** @dataProvider groupParentCases */
    public function testGroupMutationsRequireTheirLockedParent(string $operation, bool $missing, bool $caller, bool $empty): void
    {
        $scenario = array('group' => true, 'operation' => $operation, 'type' => 'graph', 'kind' => 'graph', 'type_id' => 1, 'parent_contract' => true, 'parent_missing' => $missing, 'caller_transaction' => $caller);
        if ($operation === 'remove') {
            $scenario['item_id'] = $empty ? 109 : 100;
        } else {
            $scenario['replace'] = true;
            $scenario['selected'] = $empty ? array() : array($operation === 'membership' ? 43 : 109);
        }
        $state = $this->runController($scenario);
        self::assertSame($caller, $state['transaction_open']);
        self::assertSame('', $state['output']);
        if ($missing) {
            self::assertSame(array('permission_denied'), $state['messages']);
            self::assertCount(1, $state['parent_refusal_logs']);
            self::assertStringContainsString('missing User Group ID 42', $state['parent_refusal_logs'][0]);
            self::assertSame(array(), $state['write_outcomes']);
            self::assertSame($state['parent_before_state']['permissions'], $state['permissions']);
            self::assertSame($state['parent_before_state']['memberships'], $state['memberships']);
            self::assertSame($state['parent_before_state']['reset'], $state['reset']);
        } else {
            self::assertSame(array(), $state['messages']);
            self::assertSame(array(), $state['parent_refusal_logs']);
            if ($empty) {
                self::assertSame($state['parent_before_state']['permissions'], $state['permissions']);
                self::assertSame($state['parent_before_state']['memberships'], $state['memberships']);
                self::assertSame($state['parent_before_state']['reset'], $state['reset']);
            } else {
                self::assertCount(1, $state['write_outcomes']);
                self::assertTrue($state['write_outcomes'][0]['success']);
                self::assertNotSame($state['parent_before_state']['reset'], $state['reset']);
            }
        }
        if ($caller) {
            self::assertSame(1, $state['caller_work']);
            self::assertSame(0, $state['after_caller_rollback']['caller_work']);
            self::assertSame($state['parent_before_state']['permissions'], $state['after_caller_rollback']['permissions']);
        }
    }

    public static function groupParentCases(): iterable
    {
        foreach (array('remove', 'bulk', 'membership') as $operation) {
            foreach (array(false, true) as $missing) {
                foreach (array(false, true) as $caller) {
                    foreach (array(false, true) as $empty) {
                        yield $operation . ($missing ? ' missing' : ' present') . ($caller ? ' caller' : ' owned') . ($empty ? ' empty' : ' selected') => array($operation, $missing, $caller, $empty);
                    }
                }
            }
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('epochFailureCases')]
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

    #[\PHPUnit\Framework\Attributes\DataProvider('epochPartialCases')]
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

    #[\PHPUnit\Framework\Attributes\DataProvider('realmCases')]
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

    #[\PHPUnit\Framework\Attributes\DataProvider('permissionCases')]
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
        if ($group) {
            self::assertSame($typeId === 0 ? 0 : 1, $state['permission_delete_calls']);
        }
        self::assertSame([[$principal => 42, 'realm_id' => 7], [$principal => 43, 'realm_id' => 9]], $state['realms']);
        foreach ($state['reset'] as $account) {
            if ($typeId !== 0 && ($account['id'] === 42 || ($group && $account['id'] === 44))) {
                self::assertSame(1, $account['reset_perms']);
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

    public function testGroupRemovalPreservesTheCallerTransactionAndSingleAtomicEpoch(): void
    {
        $state = $this->runController(array('group' => true, 'operation' => 'remove', 'type' => 'graph', 'caller_transaction' => true));
        self::assertSame(1, $state['permission_delete_calls']);
        self::assertTrue($state['transaction_open']);
        self::assertSame(1, $state['caller_work']);
        self::assertCount(11, $state['permissions']);
        self::assertSame(array(0, 1, 0, 1), array_column($state['reset'], 'reset_perms'));
        self::assertCount(12, $state['after_caller_rollback']['permissions']);
        self::assertSame(array(0, 0, 0, 0), array_column($state['after_caller_rollback']['reset'], 'reset_perms'));
        self::assertSame(0, $state['after_caller_rollback']['caller_work']);
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

    #[\PHPUnit\Framework\Attributes\DataProvider('bulkCases')]
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

    #[\PHPUnit\Framework\Attributes\DataProvider('failedMutationCases')]
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

    #[\PHPUnit\Framework\Attributes\DataProvider('absentDeleteCases')]
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

    #[\PHPUnit\Framework\Attributes\DataProvider('emptySelectionCases')]
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

    private static function coverageSources(): array
    {
        return array('src/IdentityAccess/Infrastructure/Legacy/PermissionMutation.php', 'src/IdentityAccess/Infrastructure/Legacy/PermissionAssociations.php', 'tests/Unit/Security/Auth/AdminPermissionPersistenceNativeCoverageTest.php', 'tests/Unit/Security/Auth/AdminPolicyAndMembershipNativeCoverageTest.php', 'user_admin.php', 'user_group_admin.php', 'lib/auth.php', 'include/global_constants.php', 'tests/Fixtures/rrd-process-coverage.php', 'tests/Helpers/NativeChildCoverageEvidence.php', 'lib/rrd.php', 'src/Graphing/Infrastructure/Rrd/ProxyCipher.php', 'lib/dsdebug.php', 'lib/rrd_maintenance.php', 'lib/poller.php', 'lib/boost.php', 'lib/api_data_source.php', 'lib/rrdcheck.php', 'lib/dsstats.php');
    }

    private function runController(array $scenario): array
    {
        $root = dirname(__DIR__, 4);
        $directory = sys_get_temp_dir() . '/admin-permission-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        mkdir($directory . '/include', 0700);
        file_put_contents($directory . '/include/auth.php', '<?php');
        $coverage = $this->getTestResultObject()->getCodeCoverage();
        $command = [PHP_BINARY, '-d', 'auto_prepend_file=', '-d', 'display_errors=stderr', '-d', 'error_reporting=24575', '-d', 'pcov.directory=' . $root, '-d', 'pcov.exclude=~/(include/vendor|tests)/~', $root . '/tests/Fixtures/admin-permission-native.php', json_encode($scenario, JSON_THROW_ON_ERROR), $directory];
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
            $status = proc_close($process);
            if ($scenario['parent_cleanup_failure'] ?? false) {
                self::assertNotSame(0, $status, $stderr . $stdout);
                self::assertStringContainsString(($scenario['affected_user_missing'] ?? false) ? 'MissingPermissionUser: Permission user does not exist.' : 'MissingPermissionGroup: Permission group does not exist.', $stderr);
            } else {
                self::assertSame(0, $status, $stderr . $stdout);
                self::assertSame('', $stderr);
            }
            if ($coverage !== null) {
                $reports = glob($directory . '/*.coverage');
                self::assertCount(1, $reports);
                require_once $root . '/tests/Helpers/NativeChildCoverageEvidence.php';
                $markers = array('admin-state-readback', 'permission-epoch-checked', 'mutation-sql-outcomes-readback');
                $association = $scenario['operation'] === 'bulk' || ($scenario['operation'] === 'membership' && isset($scenario['replace']));
                $nextRequest = !str_starts_with(getenv('KADUPUL_ADMIN_PERMISSION_TEST_DSN') ?: 'sqlite:', 'mysql:') && (in_array($scenario['operation'], array('add', 'policy', 'bulk'), true) || $association);
                if ($nextRequest) {
                    $markers[] = 'next-request-epoch-checked';
                }
                $hits = array($scenario['group'] ? 'user_group_admin.php' : 'user_admin.php', 'lib/auth.php');
                if ($association || ($scenario['operation'] === 'add' && !($scenario['error'] ?? false)) || $scenario['operation'] === 'remove') {
                    $hits[] = 'src/IdentityAccess/Infrastructure/Legacy/PermissionAssociations.php';
                }
                $mutation = in_array($scenario['operation'], array('add', 'policy', 'remove', 'bulk'), true) || $association;
                if ($mutation && ($scenario['type'] ?? '') !== 'unknown' && (($scenario['group'] && $association) || !isset($scenario['selected']) || $scenario['selected'] !== array())) {
                    $hits[] = 'src/IdentityAccess/Infrastructure/Legacy/PermissionMutation.php';
                }
                $childCoverage = NativeChildCoverageEvidence::load($reports[0], $root, 'tests/Fixtures/admin-permission-native.php', json_encode($scenario, JSON_THROW_ON_ERROR), self::coverageSources(), $markers, $hits);
                $parentRejection = ($scenario['parent_missing'] ?? false) && !self::$parentCoverageEvidenceChecked;
                $userRejection = ($scenario['affected_user_missing'] ?? false) && ($scenario['replace'] ?? false) && !self::$userCoverageEvidenceChecked;
                if (!self::$coverageEvidenceChecked || $parentRejection || $userRejection) {
                    self::assertSame(count(self::coverageSources()) + 10 + count($markers), NativeChildCoverageEvidence::verifyRejections($reports[0], $root, 'tests/Fixtures/admin-permission-native.php', json_encode($scenario, JSON_THROW_ON_ERROR), self::coverageSources(), $markers, $hits, 'lib/rrd.php'));
                    self::$coverageEvidenceChecked = true;
                    if ($userRejection) {
                        self::$userCoverageEvidenceChecked = true;
                    }
                    if ($parentRejection) {
                        self::$parentCoverageEvidenceChecked = true;
                    }
                }
                $coverage->merge($childCoverage);
            }
            $state = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
            $state['child_failed'] = $status !== 0;
            return $state;
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
