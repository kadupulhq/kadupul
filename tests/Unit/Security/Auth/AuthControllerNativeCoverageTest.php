<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/Helpers/NativeChildCoverageEvidence.php';

final class AuthControllerNativeCoverageTest extends TestCase
{
    /** @dataProvider loginCases */
    public function testLoginPersistsAuthenticationDecision(array $scenario, array $expected): void
    {
        $state = $this->runController($scenario);
        foreach ($expected as $key => $value) {
            self::assertSame($value, $state[$key], $key);
        }
        if (!$expected['error']) {
            self::assertSame(array('sess_user_id', 'sess_user_credential'), array_keys($state['session']));
            self::assertSame(42, $state['session']['sess_user_id']);
            self::assertTrue($state['credential_valid']);
            self::assertContains('ROTATE_SESSION', $state['events']);
        } else {
            self::assertArrayNotHasKey('sess_user_id', $state['session']);
            self::assertNotSame('', $state['error_message']);
        }
        self::assertSame(array(42, 43), $state['cache_users']);
        self::assertSame(array(42, 43), $state['session_users']);
    }

    public static function loginCases(): array
    {
        return array(
            'valid local login' => array(array(), array('error' => false, 'failed_attempts' => 0, 'lastlogin' => true, 'audit' => array(1))),
            'legacy password rehashed' => array(array('legacy_hash' => true), array('error' => false, 'legacy_hash_retained' => false, 'password_matches_original' => true, 'audit' => array(1))),
            'wrong password increments attempts' => array(array('request' => array('login_password' => 'Wrong1!')), array('error' => true, 'failed_attempts' => 1, 'locked' => '', 'audit' => array(0, 0))),
            'empty password increments attempts' => array(array('request' => array('login_password' => '')), array('error' => true, 'failed_attempts' => 1, 'audit' => array(0, 0))),
            'unknown username' => array(array('request' => array('login_username' => 'missing')), array('error' => true, 'failed_attempts' => 0, 'audit' => array(0))),
            'empty username' => array(array('request' => array('login_username' => '')), array('error' => true, 'failed_attempts' => 0, 'audit' => array(0))),
            'disabled account' => array(array('account' => array('enabled' => '')), array('error' => true, 'failed_attempts' => 0, 'audit' => array(0))),
            'active lockout' => array(array('account' => array('locked' => 'on', 'lastfail' => 'recent')), array('error' => true, 'locked' => 'on', 'lastlogin' => false, 'audit' => array(0))),
            'expired lockout' => array(array('account' => array('locked' => 'on', 'lastfail' => 1, 'failed_attempts' => 3)), array('error' => false, 'locked' => '', 'failed_attempts' => 0, 'audit' => array(1))),
            'threshold locks account' => array(array('request' => array('login_password' => 'Wrong1!'), 'account' => array('failed_attempts' => 2, 'lastfail' => 'recent')), array('error' => true, 'failed_attempts' => 3, 'locked' => 'on', 'audit' => array(0, 0))),
            'no area access' => array(array('no_realm' => true), array('error' => true, 'failed_attempts' => 0, 'audit' => array(1))),
            'group area access' => array(array('no_realm' => true, 'group_realm' => true), array('error' => false, 'audit' => array(1))),
        );
    }

    /** @dataProvider passwordCases */
    public function testPasswordChangePreservesOrRevokesCredentials(array $request, bool $changed, string $message): void
    {
        $request = array_merge(array('action' => 'changepassword', 'password' => 'NewCorrect2!', 'password_confirm' => 'NewCorrect2!', 'current_password' => 'Correct1!'), $request);
        $state = $this->runController(array('mode' => 'password', 'request' => $request));
        self::assertSame($changed, $state['password_matches_new']);
        self::assertSame(!$changed, $state['password_matches_original']);
        self::assertSame($changed ? array(43) : array(42, 43), $state['cache_users']);
        self::assertSame($changed ? array(43) : array(42, 43), $state['session_users']);
        if ($changed) {
            self::assertSame(array(3), $state['audit']);
            self::assertContains('password_success', $state['messages']);
            self::assertArrayNotHasKey('sess_user_id', $state['session']);
            self::assertArrayNotHasKey('sess_change_password', $state['session']);
        } else {
            self::assertSame($request['current_password'] === 'Wrong1!' ? array(0) : array(), $state['audit']);
            self::assertStringContainsString($message, $state['password_error']);
            self::assertSame(42, $state['session']['sess_user_id']);
        }
    }

    public static function passwordCases(): array
    {
        return array(
            'successful change' => array(array(), true, ''),
            'incorrect current password' => array(array('current_password' => 'Wrong1!'), false, 'current password is not correct'),
            'mismatched confirmation' => array(array('password_confirm' => 'Mismatch2!'), false, 'new passwords do not match'),
            'too short' => array(array('password' => 'x', 'password_confirm' => 'x'), false, 'at least 8'),
            'same as current' => array(array('password' => 'Correct1!', 'password_confirm' => 'Correct1!'), false, 'same as the old password'),
        );
    }

    /** @dataProvider logoutCases */
    public function testLogoutClearsSessionAndRendersReason(string $action, string $reason): void
    {
        $state = $this->runController(array('mode' => 'logout', 'request' => array('action' => $action)));
        self::assertSame(array(), $state['session']);
        self::assertSame(array('logout_pre_session_destroy', 'CLEAR_COOKIES', 'DESTROY_SESSION', 'logout_post_session_destroy'), $state['events']);
        self::assertStringContainsString($reason, $state['html']);
        self::assertSame(array(), $state['audit']);
    }

    public static function logoutCases(): array
    {
        return array('timeout' => array('timeout', 'session timeout'), 'disabled' => array('disabled', 'account suspension'));
    }

    private function runController(array $scenario): array
    {
        $root = dirname(__DIR__, 4);
        $directory = sys_get_temp_dir() . '/auth-controller-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        mkdir($directory . '/include', 0700);
        foreach (array('auth', 'global', 'global_session') as $file) {
            file_put_contents($directory . '/include/' . $file . '.php', '<?php');
        }
        $coverage = $this->getTestResultObject()->getCodeCoverage();
        $command = array(PHP_BINARY, '-d', 'auto_prepend_file=', '-d', 'error_reporting=24575', '-d', 'pcov.directory=' . $root, '-d', 'pcov.exclude=~/(include/vendor|tests)/~', $root . '/tests/Fixtures/auth-controller-native.php', json_encode($scenario, JSON_THROW_ON_ERROR), $directory);
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
                $sources = array('tests/Unit/Security/Auth/AuthControllerNativeCoverageTest.php', 'composer.lock', 'tests/composer.lock', 'tests/Fixtures/auth-controller-native.php', 'tests/Fixtures/rrd-process-coverage.php', 'tests/Helpers/NativeChildCoverageEvidence.php', 'lib/auth.php', 'auth_login.php', 'auth_changepassword.php', 'logout.php', 'lib/ldap.php', 'include/global_constants.php', 'lib/rrd.php', 'src/Graphing/Infrastructure/Rrd/ProxyCipher.php', 'lib/dsdebug.php', 'lib/rrd_maintenance.php', 'lib/poller.php', 'lib/boost.php', 'lib/api_data_source.php', 'lib/rrdcheck.php', 'lib/dsstats.php');
                $mode = $scenario['mode'] ?? 'login';
                $controller = array('login' => 'auth_login.php', 'password' => 'auth_changepassword.php', 'logout' => 'logout.php')[$mode];
                $markers = array('auth-controller-observed:' . $mode, 'auth-controller-persisted-state-readback');
                $hits = $mode === 'logout' ? array($controller) : array('lib/auth.php', $controller);
                $arguments = array($reports[0], $root, 'tests/Fixtures/auth-controller-native.php', json_encode($scenario, JSON_THROW_ON_ERROR), $sources, $markers, $hits);
                $measured = NativeChildCoverageEvidence::load(...$arguments);
                static $verifiedOmissions = false;
                if (!$verifiedOmissions) {
                    self::assertSame(count($sources) + 12, NativeChildCoverageEvidence::verifyRejections(...array_merge($arguments, array('lib/boost.php'))));
                    $verifiedOmissions = true;
                }
                $coverage->merge($measured);
            }
            return json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
        } finally {
            foreach (glob($directory . '/child-*') as $report) {
                unlink($report);
            }
            foreach (glob($directory . '/include/*') as $file) {
                unlink($file);
            }
            rmdir($directory . '/include');
            rmdir($directory);
        }
    }
}
