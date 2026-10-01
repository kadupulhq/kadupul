<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;

final class AdminPolicyAndMembershipNativeCoverageTest extends TestCase
{
    /** @dataProvider grantCases */
    public function testTypedGrantAddsPreserveOtherItemsTypesAndUsers(string $type, string $field, int $typeId, int $item, bool $error): void
    {
        $state = $this->runController(array('group' => false, 'operation' => 'add', 'type' => $type, 'field' => $field, 'item' => $item, 'error' => $error));
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
        self::assertSame('', $state['output']);
    }

    public static function grantCases(): array
    {
        $cases = array();
        foreach (array('graph' => array('graphs', 1), 'tree' => array('trees', 2), 'host' => array('hosts', 3), 'graph_template' => array('graph_templates', 4)) as $type => $details) {
            $cases[$type . ' add'] = array($type, $details[0], $details[1], 102, false);
            $cases[$type . ' replace'] = array($type, $details[0], $details[1], 100, false);
            $cases[$type . ' existing error'] = array($type, $details[0], $details[1], 102, true);
        }
        return $cases;
    }

    /** @dataProvider policyCases */
    public function testPolicyUpdateChangesOnlyPostedPoliciesForTheTarget(bool $group, array $policies): void
    {
        $state = $this->runController(array('group' => $group, 'operation' => 'policy', 'policies' => $policies));
        foreach ($state['policies'] as $row) {
            foreach (array('policy_graphs', 'policy_trees', 'policy_hosts', 'policy_graph_templates') as $policy) {
                self::assertSame($row['id'] === 42 ? ($policies[$policy] ?? 1) : 1, $row[$policy]);
            }
        }
        self::assertCount(12, $state['permissions']);
        self::assertSame('', $state['output']);
    }

    public static function policyCases(): array
    {
        return array(
            'user subset' => array(false, array('policy_graphs' => 2, 'policy_hosts' => 2)),
            'user all' => array(false, array('policy_graphs' => 2, 'policy_trees' => 2, 'policy_hosts' => 2, 'policy_graph_templates' => 2)),
            'group subset' => array(true, array('policy_trees' => 2, 'policy_graph_templates' => 2)),
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
            if (is_file($directory . '/state.sqlite')) {
                unlink($directory . '/state.sqlite');
            }
            rmdir($directory);
        }
    }
}
