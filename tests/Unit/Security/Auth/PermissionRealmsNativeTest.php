<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;

final class PermissionRealmsNativeTest extends TestCase
{
    /** @dataProvider principalKinds */
    public function testRealmFormsUseFreshPrincipalSelectionsAndPreserveEverySection(bool $group): void
    {
        $state = $this->runController($group);
        self::assertCount(4, $state['snapshots']);
        foreach ($state['snapshots'] as $position => $snapshot) {
            self::assertCount(8, $snapshot['checkboxes']);
            self::assertSame(8, $snapshot['input_count']);
            self::assertCount(1, $snapshot['queries'], 'One prepared realm query per complete render');
            self::assertSame(array($snapshot['principal']), $snapshot['queries'][0][1]);
            self::assertStringContainsString($group ? 'FROM user_auth_group_realm WHERE group_id = ?' : 'FROM user_auth_realm WHERE user_id = ?', $snapshot['queries'][0][0]);
            $selected = array_keys(array_filter($snapshot['checkboxes'], static fn($checkbox) => $checkbox['checked']));
            self::assertSame($position === 0 ? array('section7', 'section101', 'section10001', 'section120', 'section900') : ($position === 3 ? array() : array('section8', 'section10002')), $selected);
            self::assertSame('Graph Administration', $snapshot['checkboxes']['section7']['label']);
            self::assertSame('Device Administration', $snapshot['checkboxes']['section8']['label']);
            self::assertSame('Plugin -> Special', $snapshot['checkboxes']['section101']['label']);
            self::assertSame('Console: External Links/one & link', $snapshot['checkboxes']['section10001']['label']);
            self::assertSame('Top Tab: Two <link>', $snapshot['checkboxes']['section10002']['label']);
            self::assertSame($group ? ' %s Settings' : 'plug Settings', $snapshot['checkboxes']['section120']['label']);
            self::assertSame(' Old & realm', $snapshot['checkboxes']['section900']['label']);
            self::assertFalse($snapshot['checkboxes']['section0']['checked']);
            self::assertStringNotContainsString('Principal <', $snapshot['html']);
            self::assertStringContainsString('Principal &lt;' . $snapshot['principal'] . '&gt;', $snapshot['html']);
        }
        foreach ($state['reset'] as $account) {
            if ($account['id'] === 42 || ($group && $account['id'] === 44)) {
                self::assertGreaterThan(0, $account['reset_perms']);
            } else {
                self::assertSame(0, $account['reset_perms']);
            }
        }
        self::assertSame(array(array('principal' => 42, 'realm_id' => 8), array('principal' => 42, 'realm_id' => 10002), array('principal' => 43, 'realm_id' => 8), array('principal' => 43, 'realm_id' => 10002)), $state['stored_realms']);
    }

    public static function principalKinds(): array
    {
        return array('user' => array(false), 'group' => array(true));
    }

    private function runController(bool $group): array
    {
        $root = dirname(__DIR__, 4);
        $directory = sys_get_temp_dir() . '/permission-realms-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $coverage = $this->getTestResultObject()->getCodeCoverage();
        $command = array(PHP_BINARY, '-d', 'auto_prepend_file=', '-d', 'error_reporting=24575', '-d', 'pcov.directory=' . $root, '-d', 'pcov.exclude=~/(include/vendor|tests)/~', $root . '/tests/Fixtures/permission-realms-native.php', $group ? 'group' : 'user', $directory);
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
