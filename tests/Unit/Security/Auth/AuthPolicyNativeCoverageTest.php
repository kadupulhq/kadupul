<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../Helpers/PestCodeCoverageCompatibility.php';

final class AuthPolicyNativeCoverageTest extends TestCase
{
    use \PestCodeCoverageCompatibility;

    #[\PHPUnit\Framework\Attributes\DataProvider('graphItemChoiceCases')]
    public function testGraphInputChoicesUseActualActorAndDataSourcePolicies(array $scenario, array $ids, int $status): void
    {
        $state = $this->runPolicy(array_merge(['operation' => 'graph-item-choices', 'hide_disabled' => 'on',
            'config' => ['graph_auth_method' => 3, 'autocomplete_rows' => 30]], $scenario));
        self::assertSame($status, $state['result']['status']);
        $actual = array_column($state['result']['choices'], 'id');
        sort($actual);
        self::assertSame($ids, $actual);
        self::assertSame([10 => true, 11 => false, 12 => true, 13 => !in_array($scenario['config']['graph_auth_method'] ?? 3, [2,4], true)], $state['result']['admitted']);
        foreach ($state['result']['choices'] as $choice) {
            self::assertSame($choice['name'], $choice['label']);
        }
        self::assertSame($status === 200 ? 1 : 0, $state['result']['protected_reads']);
        self::assertSame(0, $state['result']['count_reads']);
        self::assertSame(0, $state['result']['device_inventory_reads']);
        self::assertLessThanOrEqual(20, $state['result']['queries']);
    }

    public function testGraphInputChoicesHaveConstantQueryBudgetAtConfiguredMaximum(): void
    {
        $small = $this->runPolicy(['operation' => 'graph-item-choices', 'choice_count' => 1,
            'config' => ['graph_auth_method' => 3, 'autocomplete_rows' => 5000]]);
        $large = $this->runPolicy(['operation' => 'graph-item-choices', 'choice_count' => 6000, 'device_count' => 20000, 'allow_inventory' => true,
            'config' => ['graph_auth_method' => 3, 'autocomplete_rows' => 5000]]);
        self::assertCount(5000, $large['result']['choices']);
        self::assertSame($small['result']['queries'], $large['result']['queries']);
        self::assertSame(1, $large['result']['protected_reads']);
        self::assertSame(0, $large['result']['count_reads']);
        self::assertSame(0, $large['result']['device_inventory_reads']);
        self::assertLessThanOrEqual(5, $large['result']['max_policy_rows']);
        self::assertNotContains(21, array_column($large['result']['choices'], 'id'));
    }

    public function testGraphInputChoicesRejectInvalidConfiguredLimitsBeforeListing(): void
    {
        foreach ([0, -1, '1e3', '1.5', '5001', '5000 UNION SELECT 1', null, []] as $limit) {
            $state = $this->runPolicy(['operation' => 'graph-item-choices',
                'config' => ['graph_auth_method' => 3, 'autocomplete_rows' => $limit]]);
            self::assertSame(500, $state['result']['status']);
            self::assertSame([], $state['result']['choices']);
            self::assertSame(0, $state['result']['protected_reads']);
            self::assertSame(0, $state['result']['device_inventory_reads']);
        }
    }

    public static function graphItemChoiceCases(): iterable
    {
        yield 'Any includes disabled, unattached and non-device sources' => [[], [20,22,23], 200];
        yield 'zero filter preserves Any' => [['choices_request' => ['host_id' => 0]], [20,22,23], 200];
        yield 'allowed host filter' => [['choices_request' => ['host_id' => 100]], [20], 200];
        yield 'denied host' => [['choices_request' => ['host_id' => 101]], [], 403];
        yield 'denied pinned source' => [['choices_request' => ['host_id' => 100, 'rrd_id' => 21]], [20], 200];
        yield 'allowed pinned cross-host bypasses search' => [['choices_request' => ['host_id' => 102, 'rrd_id' => 20, 'term' => 'absent']], [20], 200];
        yield 'unattached source search' => [['choices_request' => ['term' => 'Unattached']], [23], 200];
        yield 'device-less pinned bypasses host filter' => [['choices_request' => ['host_id' => 100, 'rrd_id' => 22]], [20,22], 200];
        yield 'missing pinned source' => [['choices_request' => ['rrd_id' => 999]], [20,22,23], 200];
        yield 'no management realm' => [['no_realm' => true], [], 403];
        foreach (['host_id', 'rrd_id', 'term'] as $field) {
            yield 'array ' . $field => [['choices_request' => [$field => []]], [], 400];
        }
        foreach (['host_id', 'rrd_id'] as $field) {
            foreach ([-1, '1e2', '1.5', '100x'] as $invalid) {
                yield $field . ' malformed ' . $invalid => [['choices_request' => [$field => $invalid]], [], 400];
            }
        }
        for ($method = 1; $method <= 4; $method++) {
            foreach ([false,true] as $group) {
                yield 'actual method ' . $method . ' group ' . (int) $group => [['group_grant' => $group,
                    'config' => ['graph_auth_method' => $method, 'autocomplete_rows' => 30]], in_array($method, [2,4], true) ? [20,22] : [20,22,23], 200];
            }
        }
    }

    public function testRealPermissionCachesSeparateOwnersAndRecheckAdministrativeResets(): void
    {
        $state = $this->runPolicy(array('operation' => 'cache-owner-isolation'));
        self::assertSame(array(array(true, true, true), array(false, false, false), array(true, true, true), array(true, true, true), array(true, true, true)), $state['result']);
        self::assertSame(array(42 => array(100 => true), 43 => array(100 => true)), $state['session']['sess_tree_perms']);
        self::assertSame(array(42 => true, 43 => true), $state['session']['sess_simple_perms']);
        self::assertSame(array(42 => true, 43 => true), $state['session']['sess_simple_template_perms']);
    }
    #[\PHPUnit\Framework\Attributes\DataProvider('graphCacheActors')]
    public function testGraphCacheRechecksPersistedRevocationForEveryActor(string $actor, bool $keepAllowed): void
    {
        $state = $this->runPolicy(['operation' => 'graph-cache-revocation', 'actor' => $actor,
            'keep_allowed' => $keepAllowed, 'config' => ['graph_auth_method' => 1]]);
        self::assertSame([true, true, true], $state['result']['initial']);
        self::assertSame(array_fill(0, 4, true), $state['result']['repeated']);
        self::assertSame([$keepAllowed, $keepAllowed, false], $state['result']['after']);
        self::assertSame(['policy_graphs' => 2, 'reset_perms' => 1], $state['result']['stored']);
        self::assertGreaterThan(0, $state['result']['reset_queries']);
    }

    public static function graphCacheActors(): iterable
    {
        foreach (['session', 'guest', 'report'] as $actor) {
            yield $actor . ' revoked' => [$actor, false];
            yield $actor . ' retains explicit grant' => [$actor, true];
        }
    }

    public function testGraphCacheRepeatedRenderingHasConstantQueriesPerGraph(): void
    {
        $small = $this->runPolicy(['operation' => 'graph-cache-revocation', 'repeat' => 1,
            'config' => ['graph_auth_method' => 1]]);
        $large = $this->runPolicy(['operation' => 'graph-cache-revocation', 'repeat' => 100,
            'config' => ['graph_auth_method' => 1]]);
        self::assertSame($small['result']['repeated_queries'] * 100, $large['result']['repeated_queries']);
        self::assertLessThanOrEqual(6, $small['result']['repeated_queries']);
        self::assertSame(array_fill(0, 100, true), $large['result']['repeated']);
        self::assertSame([false, false, false], $large['result']['after']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('graphImageCacheActors')]
    public function testGraphImageCollectorChecksLiveStoredPolicyBeforeTransport(string $actor, bool $keepAllowed): void
    {
        $state = $this->runPolicy(['operation' => 'graph-image-cache', 'actor' => $actor,
            'keep_allowed' => $keepAllowed, 'config' => ['graph_auth_method' => 1]]);
        self::assertTrue($state['result']['image']['session_closed']);
        self::assertSame($keepAllowed ? 'REMOTE_IMAGE' : 'GRAPH ACCESS DENIED', $state['result']['image']['output']);
        self::assertCount($keepAllowed ? 1 : 0, $state['result']['image']['remote_calls']);
        if ($keepAllowed) {
            self::assertSame(1, $state['result']['image']['remote_calls'][0][0]);
            self::assertStringContainsString('effective_user=42', $state['result']['image']['remote_calls'][0][1]);
        }
    }

    public static function graphImageCacheActors(): iterable
    {
        foreach (self::graphCacheActors() as $name => $case) {
            if ($case[0] !== 'report') yield $name => $case;
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('graphCacheFailures')]
    public function testGraphCacheRefusesMissingAccountsAndFailedGenerationReads(string $actor, string $failure, bool $image): void
    {
        $state = $this->runPolicy(['operation' => $image ? 'graph-image-cache' : 'graph-cache-revocation',
            'actor' => $actor, 'failure' => $failure, 'config' => ['graph_auth_method' => 1]]);
        self::assertTrue($state['result']['failed']);
        self::assertSame('Permission generation could not be confirmed.', $state['result']['error']);
        self::assertSame([], $state['result']['remote_calls']);
        foreach ($state['result']['queries_after'] as $sql) {
            self::assertStringNotContainsString('FROM graph_templates_graph', $sql);
        }
    }

    public static function graphCacheFailures(): iterable
    {
        foreach (['session', 'guest', 'report'] as $actor) {
            foreach (['missing', 'backend'] as $failure) {
                yield $actor . ' ' . $failure => [$actor, $failure, false];
                if ($actor !== 'report') yield $actor . ' image ' . $failure => [$actor, $failure, true];
            }
        }
    }

    public function testGraphCacheRetainsTrustedNegativeAndAuthDisabledZeroCallers(): void
    {
        foreach ([-1, 0] as $user) {
            $state = $this->runPolicy(['operation' => 'graph-cache-revocation', 'compatibility' => $user,
                'anonymous' => true, 'auth_method' => $user === 0 ? 0 : 1,
                'config' => ['graph_auth_method' => 1]]);
            self::assertSame([100], $state['result']['ids']);
            self::assertSame(1, $state['result']['total']);
            self::assertSame($user === 0 ? 0 : 1, $state['result']['generation_queries']);
        }
    }

    public function testGraphCacheNormalizesMalformedLegacyResetCache(): void
    {
        foreach ([true, false, 'legacy', 7] as $cache) {
            $state = $this->runPolicy(['operation' => 'graph-cache-revocation', 'actor' => 'report',
                'reset_cache' => $cache, 'config' => ['graph_auth_method' => 1]]);
            self::assertSame([false, false, false], $state['result']['after']);
            self::assertSame([42 => 1], $state['session']['sess_perms_reset_key']);
        }
    }

    public function testDeviceAuthorizationDoesNotInheritGraphViewVisibility(): void
    {
        $state = $this->runPolicy(['operation' => 'device-filter-policy', 'hide_disabled' => 'on', 'policy' => 2, 'exceptions' => [3], 'config' => ['graph_auth_method' => 1]]);
        self::assertSame(['view' => [], 'graph_view' => [], 'management' => [100], 'target' => true, 'foreign' => false, 'deleted' => false, 'missing' => false, 'graphs' => [true, false, false, false]], $state['result']);
        $state = $this->runPolicy(['operation' => 'device-filter-policy', 'hide_disabled' => 'on']);
        self::assertSame(['view' => [], 'graph_view' => [], 'management' => [100, 101, 102], 'target' => true, 'foreign' => true, 'deleted' => true, 'missing' => false, 'graphs' => [true, true, true, false]], $state['result']);
    }
    public function testMalformedResourceIdentifiersAreDeniedBeforePolicyQueries(): void
    {
        $state = $this->runPolicy(['operation' => 'resource-ids']);
        self::assertSame(array_fill(0, 10, [false, false]), $state['result']['refused']);
        self::assertSame(array_fill(0, 9, [[], 0, [], 0]), $state['result']['invalid_lists']);
        self::assertSame(0, $state['result']['invalid_queries']);
        self::assertSame(array_fill(0, 4, [true, true]), $state['result']['admitted']);
    }
    private static array $coverageEvidenceChecked = [];

    #[\PHPUnit\Framework\Attributes\DataProvider('realmCases')]
    public function testRealmDecisionsUseScopedRealmsAndEnabledMembershipThenCurrentUserCache(array $scenario, bool $expected, bool $cached): void
    {
        $state = $this->runPolicy(array_merge(['operation' => 'realm'], $scenario));
        self::assertSame($expected, $state['result']);
        self::assertSame($cached, $state['cached']);
        if (!isset($scenario['check_user']) && !isset($scenario['anonymous'])) {
            self::assertSame($expected, $state['session']['sess_user_realms'][21]);
            self::assertSame(0, $state['extra_queries']);
        } elseif (isset($scenario['check_user'])) {
            self::assertArrayNotHasKey('sess_user_realms', $state['session']);
            self::assertGreaterThan(0, $state['extra_queries']);
        }
    }

    public static function realmCases(): array
    {
        return [
            'direct current grant' => [['realms' => [42]], true, true],
            'foreign direct realm ignored' => [['realms' => [43]], false, false],
            'enabled group grant' => [['groups' => [[]]], true, true],
            'disabled group ignored' => [['groups' => [['enabled' => '']]], false, false],
            'foreign membership ignored' => [['groups' => [['user' => 43]]], false, false],
            'explicit user bypasses current cache' => [['realms' => [43], 'check_user' => 43], true, false],
            'anonymous denied' => [['anonymous' => true], false, false],
            'authentication disabled' => [['auth_method' => 0], true, true],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('viewCases')]
    public function testViewUsesActualGroupJoinVetoAndUserFallback(array $scenario, bool $expected): void
    {
        $state = $this->runPolicy(array_merge(['operation' => 'view'], $scenario));
        self::assertSame($expected, $state['result']);
        self::assertCount(isset($scenario['view']) && $scenario['view'] === 'invalid' ? 1 : 0, $state['logs']);
    }

    public static function viewCases(): array
    {
        return [
            'user fallback grant' => [['view_default' => 'on'], true],
            'user fallback deny' => [[], false],
            'enabled group textual grant' => [['groups' => [['view' => 'on']]], true],
            'enabled group numeric grant' => [['groups' => [['view' => '2']]], true],
            'veto beats user and other group grant' => [['view_default' => 'on', 'groups' => [['view' => '2'], ['view' => '3']]], false],
            'disabled group grant ignored' => [['groups' => [['view' => 'on', 'enabled' => '']]], false],
            'foreign group veto ignored' => [['view_default' => 'on', 'groups' => [['view' => '3', 'user' => 43]]], true],
            'anonymous denied' => [['anonymous' => true], false],
            'invalid view fails closed' => [['view' => 'invalid', 'auth_method' => 0], false],
            'authentication disabled' => [['auth_method' => 0], true],
        ];
    }

    public function testPluginRolesResolveListAndDisplayNamesWithoutDuplicatesAndReuseLookupCache(): void
    {
        $state = $this->runPolicy(['operation' => 'roles']);
        self::assertSame(['extension' => [7, 105], 'new' => [105]], $state['result']);
        self::assertSame(['first.php' => 105, 'middle.php' => 105, 'last.php' => 105, 'Extension realm' => 105], $state['session']['sess_auth_names']);
        self::assertSame(0, $state['extra_queries']);
        self::assertArrayNotHasKey('missing', $state['result']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('simpleCases')]
    public function testSimplePermissionHelpersDistinguishDefaultsAndTypedExceptionsAndCacheGraphResults(array $scenario, bool $expected): void
    {
        $state = $this->runPolicy(array_merge(['operation' => 'simple'], $scenario));
        self::assertSame(array_fill(0, 3, $expected), $state['result']);
        self::assertSame(array_fill(0, 2, $expected), $state['cached']);
        self::assertSame(array(42 => $expected), $state['session']['sess_simple_perms']);
        self::assertSame(array(42 => $expected), $state['session']['sess_simple_template_perms']);
        self::assertSame(2, $state['extra_queries']);
    }

    public static function simpleCases(): array
    {
        return [
            'default allow without exceptions' => [[], true],
            'default deny without memberships' => [['policy' => 2], false],
            'typed direct exceptions' => [['exceptions' => [1, 3, 4]], false],
            'typed group exceptions do not simplify deny policy' => [['policy' => 2, 'groups' => [['exceptions' => [1, 3, 4]]]], false],
            'foreign group exceptions ignored with direct default' => [['groups' => [['user' => 43, 'exceptions' => [1, 3, 4]]]], true],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('graphMatrix')]
    public function testGraphPermissionDescriptionsMatchActualQueriesAndSingleGraphAuthorization(array $scenario, bool $allowed): void
    {
        $state = $this->runPolicy(array_merge(['operation' => 'graphs'], $scenario));
        self::assertSame([
            'policy_rows' => $allowed ? [100] : [],
            'allowed_rows' => $allowed ? [100] : [],
            'total' => $allowed ? 1 : 0,
            'allowed' => $allowed,
            'missing' => false,
        ], $state['result']);
        self::assertSame([], $state['logs']);
    }

    public static function graphMatrix(): iterable
    {
        // Expected decisions are the documented truth table, exercised through
        // persisted permission exceptions and all three production entry points.
        $grants = [
            'none' => [[], [false, false, false, false]],
            'graph only' => [[1], [true, true, true, true]],
            'device only' => [[3], [true, false, true, false]],
            'template only' => [[4], [true, false, false, true]],
            'device and template' => [[3, 4], [true, true, true, true]],
            'graph and device' => [[1, 3], [true, true, true, true]],
            'graph and template' => [[1, 4], [true, true, true, true]],
            'all' => [[1, 3, 4], [true, true, true, true]],
        ];
        foreach ([1, 2] as $policy) {
            foreach ($grants as $name => [$types, $decisions]) {
                $exceptions = $policy === 2 ? $types : array_values(array_diff([1, 3, 4], $types));
                foreach ([1, 2, 3, 4] as $mode) {
                    foreach (['user', 'group'] as $source) {
                        $scenario = ['config' => ['graph_auth_method' => $mode], 'policy' => $source === 'user' ? $policy : 2];
                        if ($source === 'user') {
                            $scenario['graph_exceptions'] = $exceptions;
                        } else {
                            $scenario['groups'] = [['graph_policy' => $policy, 'graph_exceptions' => $exceptions]];
                        }
                        yield "$source policy $policy mode $mode $name" => [$scenario, $decisions[$mode - 1]];
                    }
                }
            }
        }
        yield 'restrictive does not combine grants from two groups' => [
            ['policy' => 2, 'config' => ['graph_auth_method' => 2], 'groups' => [
                ['graph_exceptions' => [3]], ['graph_exceptions' => [4]],
            ]], false,
        ];
        yield 'restrictive does not combine user and group grants' => [
            ['policy' => 2, 'config' => ['graph_auth_method' => 2], 'graph_exceptions' => [3], 'groups' => [
                ['graph_exceptions' => [4]],
            ]], false,
        ];
        yield 'disabled group cannot grant restrictive access' => [
            ['policy' => 2, 'config' => ['graph_auth_method' => 2], 'groups' => [
                ['enabled' => '', 'graph_exceptions' => [1, 3, 4]],
            ]], false,
        ];
        yield 'foreign membership cannot grant restrictive access' => [
            ['policy' => 2, 'config' => ['graph_auth_method' => 2], 'groups' => [
                ['user' => 43, 'graph_exceptions' => [1, 3, 4]],
            ]], false,
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('treeCases')]
    public function testTreesRespectDirectPolicyAndEnabledMembershipAndReuseCurrentCache(array $scenario, bool $expected): void
    {
        $state = $this->runPolicy(array_merge(['operation' => 'tree'], $scenario));
        self::assertSame($expected, $state['result']);
        self::assertSame($expected, $state['cached']);
        self::assertSame(array(!empty($scenario['anonymous']) ? 0 : 42 => array(100 => $expected)), $state['session']['sess_tree_perms']);
        self::assertSame(!empty($scenario['anonymous']) ? 0 : 1, $state['extra_queries']);
    }

    public static function treeCases(): array
    {
        return [
            'default allow' => [[], true],
            'default deny' => [['tree_policy' => 2], false],
            'direct allow exception' => [['tree_policy' => 2, 'exceptions' => [2]], true],
            'direct deny exception' => [['exceptions' => [2]], false],
            'group default grant' => [['tree_policy' => 2, 'groups' => [[]]], true],
            'group exception grant' => [['tree_policy' => 2, 'groups' => [['tree_policy' => 2, 'exceptions' => [2]]]], true],
            'disabled group ignored' => [['tree_policy' => 2, 'groups' => [['enabled' => '']]], false],
            'foreign membership ignored' => [['tree_policy' => 2, 'groups' => [['user' => 43]]], false],
            'anonymous denied' => [['anonymous' => true], false],
            'authentication disabled' => [['auth_method' => 0], true],
        ];
    }

    public function testPolicyRowsKeepOnlyEnabledGroupsOfTheRequestedPrincipal(): void
    {
        $state = $this->runPolicy(['operation' => 'policies', 'tree_policy' => 2, 'groups' => [[], ['enabled' => ''], ['user' => 43]]]);
        self::assertCount(2, $state['result']);
        self::assertSame([1, 42], array_column($state['result'], 'id'));
        self::assertSame(['group', 'user'], array_column($state['result'], 'type'));
        self::assertSame([1, 2], array_column($state['result'], 'policy_trees'));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('branchCases')]
    public function testBranchEmptinessUsesActualChildrenGraphsAndOrphanSites(array $scenario, array $expected): void
    {
        $state = $this->runPolicy(['operation' => 'branch'] + $scenario);
        self::assertSame($expected, $state['result']);
    }

    public static function branchCases(): array
    {
        return ['empty nested branches' => [[], [true,true,true]], 'nested visible graph' => [['graph' => true], [false,false,true]], 'orphan site has no permitted device' => [['site' => true], [true,true,true]]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('contentCases')]
    public function testTreeContentIncludesVisibleNestedGraphOrSiteAndOmitsEmptyBranches(array $scenario, array $ids): void
    {
        $state = $this->runPolicy(['operation' => 'tree-content'] + $scenario);
        self::assertSame($ids, array_column($state['result'], 'id'));
    }

    public static function contentCases(): array
    {
        return [[[],[]], [['graph' => true],[11]], [['site' => true],[11]]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('levelCases')]
    public function testTreeLevelsScopeActualParentAndTreeInPositionOrder(int $parent, bool $editing, array $ids): void
    {
        $state = $this->runPolicy(['operation' => 'tree-level', 'parent' => $parent, 'editing' => $editing]);
        self::assertSame($ids, array_column($state['result'], 'id'));
    }

    public static function levelCases(): array
    {
        return [[0,false,[11]], [11,false,[12]], [99,false,[]], [0,true,[11]]];
    }

    public function testAllowedTreeListAndCountUseTheSameEnabledPolicyRows(): void
    {
        $state = $this->runPolicy(['operation' => 'trees']);
        self::assertSame([102,100], array_column($state['result']['rows'], 'id'));
        self::assertSame(2, $state['result']['total']);
    }

    public function testActualRowCountCacheReusesThenRefreshesOnlyItsPrincipalAndClass(): void
    {
        $state = $this->runPolicy(['operation' => 'row-cache']);
        self::assertSame(2, $state['result']['first']);
        self::assertSame(2, $state['result']['cached']);
        self::assertSame(3, $state['result']['refreshed']);
        self::assertSame([['user_id' => 42,'class' => 'tree-test','total_rows' => 3],['user_id' => 43,'class' => 'foreign','total_rows' => 77]], $state['result']['stored']);
    }

    public function testFailedNativeCountQueryDoesNotCreateACacheSuccessRow(): void
    {
        $state = $this->runPolicy(['operation' => 'row-cache', 'failure' => true]);
        self::assertSame('PDOException', $state['result']['error']);
        self::assertSame([['user_id' => 43,'class' => 'foreign','total_rows' => 77]], $state['result']['stored']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('ownershipCases')]
    public function testResourceOwnershipUsesPersistedOwnerAndParentJoin(array $scenario, bool $expected): void
    {
        $state = $this->runPolicy(['operation' => 'ownership'] + $scenario);
        self::assertSame($expected, $state['result']);
    }

    public static function ownershipCases(): array
    {
        return [ [['type' => 'reports','resource' => 1],true], [['type' => 'reports','resource' => 2],false], [['type' => 'reports','resource' => 999],false], [['type' => 'report_item','resource' => 10],true], [['type' => 'report_item','resource' => 20],false], [['type' => 'report_item','resource' => 30],false], [['type' => 'report_item','resource' => 999],false], [['type' => 'reports','resource' => 1,'user' => 0],false], [['type' => 'unknown','resource' => 1],false] ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('revokedCases')]
    public function testActualStalePermissionExitPreservesForeignTokensAndClearsStaleSession(bool $disabled): void
    {
        $state = $this->runPolicy(['operation' => 'revoked-account','disabled' => $disabled]);
        self::assertStringContainsString($disabled ? 'cactiLoginSuspend' : 'cactiRedirect', $state['output']);
        self::assertSame(5, $state['session']['sess_user_perms_key']);
        foreach (['sess_user_realms','sess_user_config_array','sess_config_array','sess_auth_names','sess_tree_perms','sess_simple_perms','sess_simple_template_perms'] as $key) {
            self::assertArrayNotHasKey($key, $state['session']);
        }
        if ($disabled) {
            self::assertArrayNotHasKey('sess_user_id', $state['session']);
            self::assertSame([['user_id' => 43,'token' => 'foreign']], $state['tokens']);
        } else {
            self::assertSame(42, $state['session']['sess_user_id']);
            self::assertSame([['user_id' => 42,'token' => 'target'],['user_id' => 43,'token' => 'foreign']], $state['tokens']);
        }
    }

    public static function revokedCases(): array
    {
        return [[true],[false]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('managementMaximumCases')]
    public function testManagementMaximumBatchHasBoundedFreshOwnerPolicyRows(string $resource, string $phase): void
    {
        $state = $this->runPolicy(['operation' => 'management-bulk', 'resource' => $resource,
            'phase' => $phase, 'size' => 5000, 'hide_disabled' => 'on', 'config' => ['graph_auth_method' => 3]])['result'];
        self::assertSame($phase === 'execute' ? 'execution' : 'confirmation', $state['stage']);
        self::assertSame(range(1001, 6000), $state['selection']);
        self::assertSame(0, $state['owner_queries']);
        self::assertSame(array_fill(0, $phase === 'execute' ? 10 : 5, 1000), $state['eligibility_rows']);
        self::assertLessThanOrEqual($resource === 'graph' ? 30 : 22, $state['queries']);
        self::assertSame('preserved previous diagnostic', $state['error_restored']);
        if ($phase === 'execute') {
            $prefix = $resource === 'graph' ? 'graphs' : 'data_source';
            self::assertSame([$prefix . '_action_execute', 'snmp', $prefix . '_action_bottom'], array_column($state['events'], 0));
            foreach ($state['events'] as $event) self::assertSame(range(1001, 6000), $event[1]);
            self::assertSame([], $state['title_ids']);
        } else {
            self::assertSame(range(1001, 6000), $state['title_ids']);
            self::assertSame([], $state['events']);
        }
    }

    public static function managementMaximumCases(): array
    {
        return [['graph', 'execute'], ['data', 'execute'], ['graph', 'confirmation'], ['data', 'confirmation']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('managementAdmissionCases')]
    public function testManagementBatchPreservesAdmissionAndFreshBoundaryContracts(string $resource, array $scenario, string $stage, ?array $selection): void
    {
        $state = $this->runPolicy(array_merge(['operation' => 'management-bulk', 'resource' => $resource,
            'config' => ['graph_auth_method' => 3]], $scenario))['result'];
        self::assertSame($stage, $state['stage']);
        self::assertSame($selection, $state['selection']);
        self::assertSame(0, $state['owner_queries']);
        self::assertSame('preserved previous diagnostic', $state['error_restored']);
        if ($stage === 'denied') {
            self::assertSame([], $state['title_ids']);
            if ($resource === 'graph') {
                self::assertSame(['message'], array_column($state['events'], 0));
            } elseif (($scenario['generation_change'] ?? '') === 'once') {
                self::assertSame([['snmp', false], ['data_source_action_bottom', false]], $state['events']);
            } else {
                self::assertSame([], $state['events']);
            }
        }
        if (($scenario['size'] ?? 0) === 10001) {
            self::assertSame(0, $state['queries']);
            self::assertSame([], $state['eligibility_rows']);
        }
        if (isset($scenario['generation_change'])) {
            self::assertGreaterThan(0, $state['injected']);
            self::assertLessThanOrEqual(2, count($state['eligibility_rows']));
        }
    }

    public static function managementAdmissionCases(): array
    {
        $cases = [];
        foreach (['graph', 'data'] as $resource) {
            foreach (['execute', 'confirmation'] as $phase) {
                $cases[$resource . ' oversized ' . $phase] = [$resource, ['size' => 10001, 'phase' => $phase], 'denied', null];
            }
            foreach (['actor_disabled', 'actor_locked', 'read_failure'] as $failure) {
                $cases[$resource . ' ' . $failure] = [$resource, [$failure => true], 'denied', null];
            }
            foreach (['once', 'repeat'] as $change) {
                $cases[$resource . ' generation ' . $change] = [$resource, ['generation_change' => $change], 'denied', null];
            }
            $cases[$resource . ' late chunk failure'] = [$resource, ['size' => 1001, 'read_failure' => 2], 'denied', null];
            $cases[$resource . ' owner handoff'] = [$resource, ['restricted' => true, 'owner_change_between_boundaries' => true], 'denied', null];
            $cases[$resource . ' duplicate representation'] = [$resource, ['selection' => ['01001', 1002, '1001 ', 1001]], 'execution', ['01001', 1002, '1001 ', 1001]];
            $cases[$resource . ' unassigned'] = [$resource, ['owners' => [1001 => 0]], 'execution', [1001, 1002, 1003, 1004]];
            $cases[$resource . ' disabled presentation host'] = [$resource, ['hide_disabled' => 'on'], 'execution', [1001, 1002, 1003, 1004]];
            $cases[$resource . ' no authentication'] = [$resource, ['auth_method' => 0, 'anonymous' => true], 'execution', [1001, 1002, 1003, 1004]];
        }
        foreach ([['owners' => [1001 => 999]], ['owners' => [1001 => -1]], ['selection' => [1001, 9999]], ['selection' => [1001, -1, 0]]] as $index => $scenario) {
            $cases['graph invalid ' . $index] = ['graph', $scenario, 'denied', null];
            $cases['data invalid partial ' . $index] = ['data', $scenario, 'execution', $index < 2 ? [1002, 1003, 1004] : [1001]];
        }
        foreach (['decimal text' => '1001.0', 'exponent text' => '1.001e3', 'fractional float' => 1001.5] as $name => $value) {
            $cases['graph malformed numeric ' . $name] = ['graph', ['selection' => [$value, 1002]], 'denied', null];
            $cases['data malformed numeric ' . $name] = ['data', ['selection' => [$value, 1002]], 'execution', [1002]];
        }
        $cases['graph integral float'] = ['graph', ['integral_float' => true], 'denied', null];
        $cases['data integral float'] = ['data', ['integral_float' => true], 'execution', [1002, 1003, 1004]];
        $cases['graph restricted mixed'] = ['graph', ['restricted' => true, 'owners' => [1001 => 201]], 'denied', null];
        $cases['data restricted mixed'] = ['data', ['restricted' => true, 'owners' => [1001 => 201]], 'execution', [1002, 1003, 1004]];
        $cases['data restricted confirmation'] = ['data', ['restricted' => true, 'owners' => [1001 => 201], 'phase' => 'confirmation'], 'confirmation', [1002, 1003, 1004]];
        return $cases;
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('managementDeviceCases')]
    public function testDeviceManagementUsesBoundedCurrentPolicyBeforeNamesAndHandoff(array $scenario, string $stage, ?array $selection): void
    {
        $state = $this->runPolicy(array_merge(['operation' => 'management-bulk', 'resource' => 'device',
            'config' => ['graph_auth_method' => 3]], $scenario))['result'];
        self::assertSame($stage, $state['stage']);
        self::assertSame($selection, $state['selection']);
        self::assertSame('preserved previous diagnostic', $state['error_restored']);
        if ($stage === 'denied') {
            self::assertSame([], $state['events']);
            self::assertSame([], $state['title_ids']);
            if (($scenario['size'] ?? 0) === 10001) self::assertSame(0, $state['queries']);
        } elseif (($scenario['size'] ?? 0) === 5000) {
            self::assertSame(array_fill(0, 5, 1000), $state['eligibility_rows']);
            $confirmation = ($scenario['phase'] ?? '') === 'confirmation';
            self::assertLessThanOrEqual($confirmation ? 5011 : 11, $state['queries']);
            self::assertSame($confirmation ? range(1001, 6000) : [], $state['title_ids']);
            self::assertSame($confirmation ? [] : ['device_action_execute', 'snmp', 'device_action_bottom'], array_column($state['events'], 0));
            foreach ($state['events'] as $event) self::assertSame(range(1001, 6000), $event[1]);
        }
    }

    public static function managementDeviceCases(): array
    {
        return [
            'maximum execution' => [['size' => 5000], 'execution', range(1001, 6000)],
            'maximum confirmation' => [['size' => 5000, 'phase' => 'confirmation'], 'confirmation', range(1001, 6000)],
            'oversized execution' => [['size' => 10001], 'denied', null],
            'oversized confirmation' => [['size' => 10001, 'phase' => 'confirmation'], 'denied', null],
            'disabled actor' => [['actor_disabled' => true], 'denied', null],
            'locked actor' => [['actor_locked' => true], 'denied', null],
            'read failure' => [['read_failure' => true], 'denied', null],
            'late chunk failure' => [['size' => 1001, 'read_failure' => 2], 'denied', null],
            'generation once' => [['generation_change' => 'once'], 'denied', null],
            'generation repeat' => [['generation_change' => 'repeat'], 'denied', null],
            'zero identifier' => [['selection' => [0, 1001]], 'denied', null],
            'decimal identifier' => [['selection' => ['1001.0', 1002]], 'denied', null],
            'exponent identifier' => [['selection' => ['1.001e3', 1002]], 'denied', null],
            'fractional float identifier' => [['selection' => [1001.5, 1002]], 'denied', null],
            'integral float identifier' => [['integral_float' => true], 'denied', null],
            'negative identifier' => [['selection' => [-1, 1001]], 'denied', null],
            'missing device' => [['selection' => [9999, 1001]], 'denied', null],
            'restricted device' => [['restricted' => true], 'denied', null],
            'duplicate representation' => [['selection' => ['01001', 1002, '1001 ', 1001]], 'execution', ['01001', 1002, '1001 ', 1001]],
            'no authentication' => [['auth_method' => 0, 'anonymous' => true], 'execution', [1001, 1002, 1003, 1004]],
            'hidden disabled devices remain manageable' => [['hide_disabled' => 'on'], 'execution', [1001, 1002, 1003, 1004]],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('managementListCases')]
    public function testManagementListsUseActualPolicyAndMatchingCountBeforeRendering(string $resource, array $scenario, array $ids, ?int $totalRows = null): void
    {
        $state = $this->runPolicy(array_merge(['operation' => 'management-list', 'resource' => $resource,
            'config' => ['graph_auth_method' => 1]], $scenario));
        self::assertTrue($state['completed']);
        self::assertStringNotContainsString('Fatal error', $state['html']);
        $document = new DOMDocument();
        self::assertTrue($document->loadHTML($state['html'], LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET));
        $xpath = new DOMXPath($document);
        $actual = [];
        foreach ($xpath->query('//input[starts-with(@name,"chk_")]') as $input) $actual[] = (int) substr($input->getAttribute('name'), 4);
        self::assertSame($ids, $actual);
        // Request normalization keeps positive client IDs; SQL separately scopes visibility.
        $normalizedRequests = ['1001,1002,0,ordinary,1003' => '1001,1002,1003', '1002,0,ordinary' => '1002', '0,ordinary' => '0'];
        $requested = $scenario['request']['local_graph_ids'] ?? '';
        if (isset($normalizedRequests[$requested])) self::assertSame($normalizedRequests[$requested], $state['request']['local_graph_ids']);
        foreach ($xpath->query('//*[@data-total]') as $total) self::assertSame($totalRows ?? count($ids), (int) $total->getAttribute('data-total'));
        self::assertGreaterThan(0, $xpath->query('//*[@data-total]')->length);
        self::assertStringNotContainsString('Denied record', $state['html']);
        if ($scenario['deny_template'] ?? false) {
            self::assertSame(in_array(1001, $ids, true), $state['admission']['graph1001']);
            self::assertTrue($state['admission']['device101']);
        }
        $inventories = array_filter($state['queries'], static fn(string $sql): bool => str_contains($sql, 'SELECT h1.*'));
        self::assertSame([], array_values($inventories));
        if (($scenario['device_count'] ?? false) || ($scenario['custom_maximum'] ?? false)) {
            self::assertLessThanOrEqual(50, count($state['queries']));
            self::assertLessThanOrEqual(3, max(array_column($state['row_counts'], 'rows')));
        }
    }

    public static function managementListCases(): array
    {
        return [
            'graph permitted disabled and nondevice' => ['graph', [], [1001,1003]],
            'data permitted disabled and nondevice' => ['data', [], [1001,1003]],
            'graph hide-disabled management' => ['graph', ['hide_disabled' => 'on'], [1001,1003]],
            'data hide-disabled management' => ['data', ['hide_disabled' => 'on'], [1001,1003]],
            'graph permissive policy parity' => ['graph', ['deny_template' => true, 'config' => ['graph_auth_method' => 1]], [1001,1003,1004]],
            'graph restrictive policy parity' => ['graph', ['deny_template' => true, 'config' => ['graph_auth_method' => 2]], [1003,1004]],
            'graph device policy parity' => ['graph', ['deny_template' => true, 'config' => ['graph_auth_method' => 3]], [1001,1003,1004]],
            'graph template policy parity' => ['graph', ['deny_template' => true, 'config' => ['graph_auth_method' => 4]], [1003,1004]],
            'graph no admitted device or graph' => ['graph', ['empty_devices' => true], []],
            'data no admitted device keeps nondevice' => ['data', ['empty_devices' => true], [1003]],
            'graph explicit permitted device' => ['graph', ['request' => ['host_id' => 101]], [1001]],
            'data explicit permitted device' => ['data', ['request' => ['host_id' => 101]], [1001]],
            'graph explicit denied device' => ['graph', ['request' => ['host_id' => 201]], []],
            'data explicit denied device' => ['data', ['request' => ['host_id' => 201]], []],
            'graph explicit nondevice' => ['graph', ['request' => ['host_id' => 0]], [1003]],
            'data explicit nondevice' => ['data', ['request' => ['host_id' => 0]], [1003]],
            'graph custom missing owner remains excluded' => ['graph', ['orphan_graph' => true, 'request' => ['local_graph_ids' => '1001,1003,1005']], [1001,1003]],
            'graph maximum inventory' => ['graph', ['device_count' => 20000], [1001,1003]],
            'data maximum inventory' => ['data', ['device_count' => 20000], [1001,1003]],
            'graph next page' => ['graph', ['request' => ['rows' => 1, 'page' => 2]], [1003], 2],
            'data next page' => ['data', ['request' => ['rows' => 1, 'page' => 2]], [1003], 2],
            'graph configured rows sentinel' => ['graph', ['request' => ['rows' => -1], 'config' => ['graph_auth_method' => 1, 'num_rows_table' => 20]], [1001,1003]],
            'data configured rows sentinel' => ['data', ['request' => ['rows' => -1], 'config' => ['graph_auth_method' => 1, 'num_rows_table' => 20]], [1001,1003]],
            'graph maximum custom repeated IDs' => ['graph', ['custom_maximum' => true, 'request' => ['local_graph_ids' => implode(',', array_fill(0, 5000, '01001'))]], [1001]],
            'graph custom malformed numeric IDs' => ['graph', ['request' => ['local_graph_ids' => '1001.5,1e3,1003']], [1003]],
            'graph custom permitted plus denied' => ['graph', ['request' => ['local_graph_ids' => '1001,1002,1003']], [1001,1003]],
            'graph Any with ordinary name filter' => ['graph', ['request' => ['host_id' => -1, 'rfilter' => '^Allowed record$']], [1001]],
            'graph permitted device with ordinary name filter' => ['graph', ['request' => ['host_id' => 101, 'rfilter' => '^Allowed record$']], [1001]],
            'graph permitted device filter excludes nonmatching name' => ['graph', ['request' => ['host_id' => 101, 'rfilter' => '^Non-device record$']], []],
            'graph denied device with ordinary name filter' => ['graph', ['request' => ['host_id' => 201, 'rfilter' => 'record$']], []],
            'graph direct excluded host sentinel' => ['graph', ['request' => ['host_id' => -2]], []],
            'graph excluded host sentinel with ordinary name filter' => ['graph', ['request' => ['host_id' => -2, 'rfilter' => 'record$']], []],
            'graph None with ordinary name filter' => ['graph', ['request' => ['host_id' => 0, 'rfilter' => '^Non-device record$']], [1003]],
            'graph custom mixed permitted denied zero and text IDs' => ['graph', ['request' => ['local_graph_ids' => '1001,1002,0,ordinary,1003']], [1001,1003]],
            'graph custom denied-only projection remains empty' => ['graph', ['request' => ['local_graph_ids' => '1002,0,ordinary']], []],
            'graph custom nonpositive and text IDs use zero fallback' => ['graph', ['request' => ['local_graph_ids' => '0,ordinary']], []],
            'data defensive NULL read projection Any' => ['data', ['null_read_projection' => true], [1001,1003]],
            'data defensive NULL read projection without permitted devices' => ['data', ['null_read_projection' => true, 'empty_devices' => true], [1003]],
            'data defensive NULL read projection None' => ['data', ['null_read_projection' => true, 'request' => ['host_id' => 0]], [1003]],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('graphDeviceChildCases')]
    public function testGraphDeviceChangeReviewsEveryChildBeforeAnyMutation(array $scenario, bool $accepted): void
    {
        $state = $this->runPolicy(array_merge(['operation' => 'graph-device-change','policy' => 2,
            'config' => ['graph_auth_method' => 1]], $scenario))['result'];
        self::assertSame(!($scenario['deny_graph'] ?? false), $state['admission']['graph']);
        if ($scenario['deny_graph'] ?? false) self::assertTrue($state['admission']['source']);
        self::assertFalse($state['admission']['foreign']);
        self::assertSame($accepted, $state['status']);
        self::assertSame('preserved prior diagnostic', $state['diagnostic']);
        self::assertSame($accepted ? [1001] : [], $state['titles']);
        if (($scenario['size'] ?? 0) === 5000) {
            self::assertLessThanOrEqual(60, $state['queries']);
            self::assertCount(5, array_filter($state['sql'], static fn(string $sql): bool => str_contains($sql, 'SELECT id FROM data_local')));
            $chunkRows = [];
            foreach ($state['sql'] as $index => $sql) {
                if (str_contains($sql, 'SELECT id FROM data_local')) $chunkRows[] = $state['rows'][$index]['rows'];
            }
            self::assertSame(array_fill(0, 5, 1000), $chunkRows);
        }
        if (($scenario['size'] ?? 0) === 10001) {
            self::assertSame([], array_values(array_filter($state['sql'], static fn(string $sql): bool => str_contains($sql, 'SELECT id FROM data_local'))));
        }
        if (!$accepted) {
            self::assertSame($state['before'], $state['after']);
            self::assertSame([], $state['writes']);
        } else {
            $destination = $scenario['destination'] ?? 0;
            self::assertSame($destination, $state['after']['graph'][0]['host_id']);
            foreach ($state['after']['data'] as $row) self::assertSame($destination, $row['host_id']);
            foreach ($state['after']['poller'] as $row) {
                self::assertSame($row['local_data_id'] === 0 ? 201 : $destination, $row['host_id']);
            }
        }
    }

    public static function graphDeviceChildCases(): array
    {
        return [
            'persisted graph policy refused' => [['deny_graph' => true, 'config' => ['graph_auth_method' => 2]], false],
            'foreign child' => [['children' => [[5001,201,201]]],false],
            'foreign source without poller' => [['children' => [[5001,201,null]]],false],
            'later foreign child' => [['children' => [[5001,101,101],[5002,201,201]]],false],
            'foreign poller child' => [['children' => [[5001,101,201]]],false],
            'missing child' => [['missing' => true],false],
            'negative child owner' => [['children' => [[5001,-1,null]]],false],
            'malformed adapter child owner' => [['children' => [[5001,'invalid',null]]],false],
            'data read failure' => [['read_failure' => 'data'],false],
            'poller read failure' => [['read_failure' => 'poller'],false],
            'snmp remains refused' => [['snmp' => 1],false],
            'allowed child' => [[],true],
            'allowed destination' => [['destination' => 12],true],
            'nondevice source' => [['source' => 0],true],
            'nondevice child' => [['children' => [[5001,0,0]]],true],
            'duplicate child' => [['children' => [[5001,101,101],[5001,101,101]]],true],
            'template reference' => [['children' => [[0,null,201]]],true],
            'maximum linked sources' => [['size' => 5000],true],
            'oversized linked sources' => [['size' => 10001],false],
            'no children' => [['children' => []],true],
        ];
    }

    private function runPolicy(array $scenario): array
    {
        $root = dirname(__DIR__, 4);
        $directory = sys_get_temp_dir() . '/auth-policy-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $coverage = $this->getTestResultObject()->getCodeCoverage();
        $command = [PHP_BINARY, '-d', 'auto_prepend_file=', '-d', 'error_reporting=24575', '-d', 'pcov.directory=' . $root, '-d', 'pcov.exclude=~/(include/vendor|tests)/~', $root . '/tests/Fixtures/auth-policy-native.php', json_encode($scenario, JSON_THROW_ON_ERROR), $directory];
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
                if ($scenario['operation'] === 'graph-device-change') {
                    require_once $root . '/tests/Helpers/GraphDeviceChangeCoverageRegistration.php';
                    $hits = ['lib/auth.php','lib/api_graph.php'];
                    $childCoverage = NativeChildCoverageEvidence::load($reports[0], $root, 'tests/Fixtures/auth-policy-native.php', json_encode($scenario, JSON_THROW_ON_ERROR), GraphDeviceChangeCoverageRegistration::SOURCES, GraphDeviceChangeCoverageRegistration::MARKERS, $hits);
                    if (!isset(self::$coverageEvidenceChecked['graph-device-change'])) {
                        // Four identity fields, the producer, five report/hit
                        // controls, and every registered source and marker.
                        $expectedControls = 10 + count(GraphDeviceChangeCoverageRegistration::SOURCES) + count(GraphDeviceChangeCoverageRegistration::MARKERS);
                        self::assertSame($expectedControls, NativeChildCoverageEvidence::verifyRejections($reports[0], $root, 'tests/Fixtures/auth-policy-native.php', json_encode($scenario, JSON_THROW_ON_ERROR), GraphDeviceChangeCoverageRegistration::SOURCES, GraphDeviceChangeCoverageRegistration::MARKERS, $hits, 'lib/rrd.php'));
                        self::$coverageEvidenceChecked['graph-device-change'] = true;
                    }
                    $coverage->merge($childCoverage);
                } elseif ($scenario['operation'] === 'management-list') {
                    require_once $root . '/tests/Helpers/ManagementListCoverageRegistration.php';
                    $hits = ['lib/auth.php', $scenario['resource'] === 'graph' ? 'graphs.php' : 'data_sources.php'];
                    $childCoverage = NativeChildCoverageEvidence::load($reports[0], $root, 'tests/Fixtures/auth-policy-native.php', json_encode($scenario, JSON_THROW_ON_ERROR), ManagementListCoverageRegistration::SOURCES, ManagementListCoverageRegistration::MARKERS, $hits);
                    if (!isset(self::$coverageEvidenceChecked['management-list'])) {
                        self::assertSame(44, NativeChildCoverageEvidence::verifyRejections($reports[0], $root, 'tests/Fixtures/auth-policy-native.php', json_encode($scenario, JSON_THROW_ON_ERROR), ManagementListCoverageRegistration::SOURCES, ManagementListCoverageRegistration::MARKERS, $hits, 'lib/rrd.php'));
                        self::$coverageEvidenceChecked['management-list'] = true;
                    }
                    $coverage->merge($childCoverage);
                } elseif ($scenario['operation'] === 'management-bulk') {
                    require_once $root . '/tests/Helpers/ManagementBulkCoverageRegistration.php';
                    $childCoverage = NativeChildCoverageEvidence::load($reports[0], $root, 'tests/Fixtures/auth-policy-native.php', json_encode($scenario, JSON_THROW_ON_ERROR), ManagementBulkCoverageRegistration::SOURCES, ManagementBulkCoverageRegistration::MARKERS, ['lib/auth.php']);
                    if (!isset(self::$coverageEvidenceChecked['management-bulk'])) {
                        self::assertSame(39, NativeChildCoverageEvidence::verifyRejections($reports[0], $root, 'tests/Fixtures/auth-policy-native.php', json_encode($scenario, JSON_THROW_ON_ERROR), ManagementBulkCoverageRegistration::SOURCES, ManagementBulkCoverageRegistration::MARKERS, ['lib/auth.php'], 'lib/rrd.php'));
                        self::$coverageEvidenceChecked['management-bulk'] = true;
                    }
                    $coverage->merge($childCoverage);
                } elseif (in_array($scenario['operation'], ['graph-cache-revocation', 'graph-image-cache'], true)) {
                    require_once $root . '/tests/Helpers/GraphCacheCoverageRegistration.php';
                    $markers = ['native-policy-operation-returned', 'policy-session-observed', 'graph-cache-revocation-observed', 'graph-cache-query-budget-observed'];
                    if ($scenario['operation'] === 'graph-image-cache') $markers[] = 'graph-cache-image-dispatch-observed';
                    $hits = $scenario['operation'] === 'graph-image-cache' ? ['lib/auth.php', 'graph_image.php', 'lib/html_utility.php'] : ['lib/auth.php'];
                    $childCoverage = NativeChildCoverageEvidence::load($reports[0], $root, 'tests/Fixtures/auth-policy-native.php', json_encode($scenario, JSON_THROW_ON_ERROR), GraphCacheCoverageRegistration::SOURCES, $markers, $hits);
                    $kind = $scenario['operation'];
                    if (!isset(self::$coverageEvidenceChecked[$kind])) {
                        self::assertSame($kind === 'graph-image-cache' ? 34 : 33, NativeChildCoverageEvidence::verifyRejections($reports[0], $root, 'tests/Fixtures/auth-policy-native.php', json_encode($scenario, JSON_THROW_ON_ERROR), GraphCacheCoverageRegistration::SOURCES, $markers, $hits, 'lib/rrd.php'));
                        self::$coverageEvidenceChecked[$kind] = true;
                    }
                    $coverage->merge($childCoverage);
                } else {
                    $childCoverage = NativeChildCoverageEvidence::load($reports[0], $root, 'tests/Fixtures/auth-policy-native.php', json_encode($scenario, JSON_THROW_ON_ERROR), array('lib/auth.php', 'lib/graph_item_choices.php', 'tests/Helpers/PhpSource.php', 'tests/Fixtures/rrd-process-coverage.php', 'tests/Helpers/NativeChildCoverageEvidence.php', 'lib/rrd.php', 'src/Graphing/Infrastructure/Rrd/ProxyCipher.php', 'lib/dsdebug.php', 'lib/rrd_maintenance.php', 'lib/poller.php', 'lib/boost.php', 'lib/api_data_source.php', 'lib/rrdcheck.php', 'lib/dsstats.php'), $scenario['operation'] === 'revoked-account' ? array('permission-revocation-shutdown', 'credential-readback') : ($scenario['operation'] === 'graph-item-choices' ? array('native-policy-operation-returned', 'policy-session-observed', 'graph-choice-policy-returned', 'graph-choice-query-budget-observed') : array('native-policy-operation-returned', 'policy-session-observed')), $scenario['operation'] === 'graph-item-choices' ? array('lib/auth.php', 'lib/graph_item_choices.php') : array('lib/auth.php'));
                    $evidenceKind = $scenario['operation'] === 'revoked-account' ? 'shutdown' : ($scenario['operation'] === 'graph-item-choices' ? 'choices' : 'returned');
                    if (!isset(self::$coverageEvidenceChecked[$evidenceKind])) {
                        self::assertSame($scenario['operation'] === 'graph-item-choices' ? 28 : 26, NativeChildCoverageEvidence::verifyRejections($reports[0], $root, 'tests/Fixtures/auth-policy-native.php', json_encode($scenario, JSON_THROW_ON_ERROR), array('lib/auth.php', 'lib/graph_item_choices.php', 'tests/Helpers/PhpSource.php', 'tests/Fixtures/rrd-process-coverage.php', 'tests/Helpers/NativeChildCoverageEvidence.php', 'lib/rrd.php', 'src/Graphing/Infrastructure/Rrd/ProxyCipher.php', 'lib/dsdebug.php', 'lib/rrd_maintenance.php', 'lib/poller.php', 'lib/boost.php', 'lib/api_data_source.php', 'lib/rrdcheck.php', 'lib/dsstats.php'), $scenario['operation'] === 'revoked-account' ? array('permission-revocation-shutdown', 'credential-readback') : ($scenario['operation'] === 'graph-item-choices' ? array('native-policy-operation-returned', 'policy-session-observed', 'graph-choice-policy-returned', 'graph-choice-query-budget-observed') : array('native-policy-operation-returned', 'policy-session-observed')), $scenario['operation'] === 'graph-item-choices' ? array('lib/auth.php', 'lib/graph_item_choices.php') : array('lib/auth.php'), 'lib/rrd.php'));
                        self::$coverageEvidenceChecked[$evidenceKind] = true;
                    }
                    $coverage->merge($childCoverage);
                }
            }
            return json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
        } finally {
            foreach (glob($directory . '/*.coverage') as $report) {
                if (is_file($report . '.json')) {
                    unlink($report . '.json');
                }
                unlink($report);
            }
            rmdir($directory);
        }
    }
}
