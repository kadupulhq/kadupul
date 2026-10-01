<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;

final class AdminPermissionPersistenceNativeCoverageTest extends TestCase
{
    /** @dataProvider realmCases */
    public function testRealmSavesReplaceOnlyTheTargetPrincipalAndResetItsUsers(bool $group, array $realms, bool $self): void
    {
        $state = $this->runController(array('group' => $group, 'operation' => 'realm', 'realms' => $realms, 'self' => $self));
        $principal = $group ? 'group_id' : 'user_id';
        $expected = array();
        sort($realms);
        foreach ($realms as $realm) {
            $expected[] = array($principal => 42, 'realm_id' => $realm);
        }
        $expected[] = array($principal => 43, 'realm_id' => 9);
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
            self::assertSame(array('sess_user_id' => 42), $state['session']);
        } else {
            self::assertSame($state['initial_session'], $state['session']);
        }
        self::assertSame(array(1), $state['messages']);
        self::assertSame('', $state['output']);
    }

    public static function realmCases(): array
    {
        return array(
            'user replaces realms' => array(false, array(21, 8), false),
            'self user clears cached permissions' => array(false, array(21, 8), true),
            'user removes all realms' => array(false, array(), false),
            'group replaces realms' => array(true, array(21, 8), false),
            'group removes all realms' => array(true, array(), false),
        );
    }

    /** @dataProvider permissionCases */
    public function testPermissionRemovalPreservesOtherTypesItemsAndPrincipals(bool $group, string $typeName, int $typeId): void
    {
        $state = $this->runController(array('group' => $group, 'operation' => 'remove', 'type' => $typeName));
        $principal = $group ? 'group_id' : 'user_id';
        $expected = array();
        foreach (array(42 => array(100, 101), 43 => array(100)) as $id => $items) {
            foreach ($items as $item) {
                foreach (range(1, 4) as $type) {
                    if ($id === 42 && $item === 100 && $type === $typeId) {
                        continue;
                    }
                    $expected[] = array($principal => $id, 'item_id' => $item, 'type' => $type);
                }
            }
        }
        self::assertSame($expected, $state['permissions']);
        self::assertSame(array(array($principal => 42, 'realm_id' => 7), array($principal => 43, 'realm_id' => 9)), $state['realms']);
        foreach ($state['reset'] as $account) {
            if ($account['id'] === 42 || ($group && $account['id'] === 44)) {
                self::assertGreaterThan(0, $account['reset_perms']);
            } else {
                self::assertSame(0, $account['reset_perms']);
            }
        }
        self::assertSame($state['initial_session'], $state['session']);
        self::assertSame('', $state['output']);
    }

    public static function permissionCases(): array
    {
        $cases = array();
        foreach (array(false, true) as $group) {
            foreach (array('graph' => 1, 'tree' => 2, 'host' => 3, 'graph_template' => 4, 'unknown' => 0) as $name => $id) {
                $cases[($group ? 'group ' : 'user ') . $name] = array($group, $name, $id);
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
                $coverage->merge(unserialize(file_get_contents($reports[0])));
            }
            return json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
        } finally {
            foreach (glob($directory . '/*.coverage') as $report) {
                unlink($report);
            }
            unlink($directory . '/include/auth.php');
            rmdir($directory . '/include');
            rmdir($directory);
        }
    }
}
