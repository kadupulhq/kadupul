<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../Helpers/PestCodeCoverageCompatibility.php';

final class AuthPolicyNativeCoverageTest extends TestCase
{
    use \PestCodeCoverageCompatibility;

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
        self::assertSame($expected, $state['session']['sess_simple_perms']);
        self::assertSame($expected, $state['session']['sess_simple_template_perms']);
        self::assertSame(0, $state['extra_queries']);
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
        self::assertSame($expected, $state['session']['sess_tree_perms'][100]);
        self::assertSame(0, $state['extra_queries']);
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
                $childCoverage = NativeChildCoverageEvidence::load($reports[0], $root, 'tests/Fixtures/auth-policy-native.php', json_encode($scenario, JSON_THROW_ON_ERROR), array('lib/auth.php', 'tests/Fixtures/rrd-process-coverage.php', 'tests/Helpers/NativeChildCoverageEvidence.php', 'lib/rrd.php', 'src/Graphing/Infrastructure/Rrd/ProxyCipher.php', 'lib/dsdebug.php', 'lib/rrd_maintenance.php', 'lib/poller.php', 'lib/boost.php', 'lib/api_data_source.php', 'lib/rrdcheck.php', 'lib/dsstats.php'), $scenario['operation'] === 'revoked-account' ? array('permission-revocation-shutdown', 'credential-readback') : array('native-policy-operation-returned', 'policy-session-observed'), array('lib/auth.php'));
                $evidenceKind = $scenario['operation'] === 'revoked-account' ? 'shutdown' : 'returned';
                if (!isset(self::$coverageEvidenceChecked[$evidenceKind])) {
                    self::assertSame(24, NativeChildCoverageEvidence::verifyRejections($reports[0], $root, 'tests/Fixtures/auth-policy-native.php', json_encode($scenario, JSON_THROW_ON_ERROR), array('lib/auth.php', 'tests/Fixtures/rrd-process-coverage.php', 'tests/Helpers/NativeChildCoverageEvidence.php', 'lib/rrd.php', 'src/Graphing/Infrastructure/Rrd/ProxyCipher.php', 'lib/dsdebug.php', 'lib/rrd_maintenance.php', 'lib/poller.php', 'lib/boost.php', 'lib/api_data_source.php', 'lib/rrdcheck.php', 'lib/dsstats.php'), $scenario['operation'] === 'revoked-account' ? array('permission-revocation-shutdown', 'credential-readback') : array('native-policy-operation-returned', 'policy-session-observed'), array('lib/auth.php'), 'lib/rrd.php'));
                    self::$coverageEvidenceChecked[$evidenceKind] = true;
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
            rmdir($directory);
        }
    }
}
