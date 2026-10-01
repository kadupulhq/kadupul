<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;

final class AuthPolicyNativeCoverageTest extends TestCase
{
    /** @dataProvider realmCases */
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

    /** @dataProvider viewCases */
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

    /** @dataProvider simpleCases */
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
            'typed direct exceptions' => [['exceptions' => [1, 2, 4]], false],
            'typed group exceptions do not simplify deny policy' => [['policy' => 2, 'groups' => [['exceptions' => [1, 2, 4]]]], false],
            'foreign group exceptions ignored with direct default' => [['groups' => [['user' => 43, 'exceptions' => [1, 2, 4]]]], true],
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
                $coverage->merge(unserialize(file_get_contents($reports[0])));
            }
            return json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
        } finally {
            foreach (glob($directory . '/*.coverage') as $report) {
                unlink($report);
            }
            rmdir($directory);
        }
    }
}
