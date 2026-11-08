<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

require_once dirname(__DIR__, 3) . '/Helpers/PestCodeCoverageCompatibility.php';

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/Helpers/NativeChildCoverageEvidence.php';

final class AuthControllerNativeCoverageTest extends TestCase
{
    use \PestCodeCoverageCompatibility;

    #[\PHPUnit\Framework\Attributes\DataProvider('malformedLogins')]
    public function testMalformedLoginStopsBeforeVerificationAndCredentialPublication(array $request): void
    {
        $state = $this->runController(['malformed_login' => true, 'request' => $request]);
        self::assertSame(400, $state['status']);
        self::assertSame('', $state['html']);
        self::assertSame([], $state['events']);
        self::assertSame([], $state['audit']);
        self::assertSame(0, $state['failed_attempts']);
        self::assertArrayNotHasKey('sess_user_id', $state['session']);
        self::assertTrue($state['password_matches_original']);
        self::assertSame([42, 43], $state['cache_users']);
    }

    public static function malformedLogins(): iterable
    {
        yield 'username collection' => [['login_username' => ['alice']]];
        yield 'password collection' => [['login_password' => ['fixture-value']]];
        yield 'nested password collection' => [['login_password' => [['fixture-value']]]];
        yield 'realm collection' => [['realm' => ['0']]];
        yield 'invalid scalar realm' => [['realm' => 'invalid']];
    }

    public function testLoginRendersAnAccessibleDocumentHeading(): void
    {
        $state = $this->runController(array('render_login' => true, 'request' => array('action' => '', 'login_username' => '')));
        $document = new DOMDocument();
        self::assertTrue($document->loadHTML($state['html'], LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING));
        $xpath = new DOMXPath($document);
        self::assertSame('en-US', $document->documentElement->getAttribute('lang'));
        self::assertSame(1, $xpath->query('//h1[@class="loginHeading"]')->length);
        self::assertSame('User Login', $xpath->evaluate('string(//h1)'));
        self::assertSame(0, $xpath->query('//legend')->length);
        self::assertSame(1, $xpath->query('//form[@id="login"]//input[@name="login_password"]')->length);
        self::assertSame(array(), $state['audit']);
    }

    public function testRememberedCredentialIsPublishedAfterAuthenticationTransition(): void
    {
        $state = $this->runController(array(
            'options' => array('auth_cache_enabled' => 'on'),
            'request' => array('remember_me' => 'on'),
        ));

        self::assertFalse($state['error']);
        self::assertTrue($state['remember_cookie_had_identity']);
        self::assertLessThan(
            array_search('REMEMBER_COOKIE_PUBLISHED', $state['events'], true),
            array_search('AUTH_TRANSITION_COMPLETED', $state['events'], true),
        );
        self::assertContains(42, $state['cache_users']);
    }

    public function testRejectedPostCredentialTransitionDoesNotPublishRememberedCredential(): void
    {
        $state = $this->runController(array(
            'lock_during_transition' => true,
            'options' => array('auth_cache_enabled' => 'on'),
            'request' => array('remember_me' => 'on'),
        ));

        self::assertTrue($state['error']);
        self::assertSame('on', $state['locked']);
        self::assertNull($state['remember_cookie_had_identity']);
        self::assertNotContains('REMEMBER_COOKIE_PUBLISHED', $state['events']);
        self::assertArrayNotHasKey('sess_remember_token', $state['session']);
        self::assertSame(array(42, 43), $state['cache_users']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('loginCases')]
    public function testLoginPersistsAuthenticationDecision(array $scenario, array $expected): void
    {
        $state = $this->runController($scenario);
        foreach ($expected as $key => $value) {
            self::assertSame($value, $state[$key], $key);
        }
        if (!$expected['error']) {
            $sessionKeys = array_keys($state['session']);
            sort($sessionKeys);
            self::assertSame(array('sess_user_credential', 'sess_user_id'), $sessionKeys);
            self::assertSame(42, $state['session']['sess_user_id']);
            self::assertTrue($state['credential_valid']);
            self::assertContains('ROTATE_SESSION', $state['events']);
        } else {
            self::assertArrayNotHasKey('sess_user_id', $state['session']);
            self::assertNotSame('', $state['error_message']);
        }
        self::assertSame(array('lastlogin' => 0, 'must_change_password' => ''), $state['other_realm_policy']);
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

    #[\PHPUnit\Framework\Attributes\DataProvider('forcedPasswordCases')]
    public function testForcedComplexityRetainsAuthenticatedPasswordChangeHandoff(bool $allowed): void
    {
        $state = $this->runController(array('options' => array('secpass_forceold' => 'on', 'secpass_minlen' => 32),
            'account' => array('password_change' => $allowed ? 'on' : '')));
        self::assertSame(!$allowed, $state['error']);
        self::assertSame($allowed ? 'on' : '', $state['must_change']);
        if ($allowed) {
            self::assertSame(42, $state['session']['sess_user_id']);
            self::assertTrue($state['credential_valid']);
            self::assertTrue($state['session']['sess_change_password'] ?? false);
            self::assertContains('forced_password', $state['messages']);
            self::assertSame(array(1), $state['audit']);
        } else {
            self::assertArrayNotHasKey('sess_user_id', $state['session']);
            self::assertFalse($state['lastlogin']);
            self::assertNotSame('', $state['error_message']);
        }
        self::assertSame(array('lastlogin' => 0, 'must_change_password' => ''), $state['other_realm_policy']);
        self::assertSame(array(42, 43), $state['cache_users']);
        self::assertSame(array(42, 43), $state['session_users']);
    }

    public static function forcedPasswordCases(): array
    {
        return array('password change permitted' => array(true), 'password change prohibited' => array(false));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('passwordCases')]
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

    #[\PHPUnit\Framework\Attributes\DataProvider('complexityCases')]
    public function testPasswordChangeRunsEverySharedComplexityRule(string $password, string $error): void
    {
        $state = $this->runController(array(
            'mode' => 'password',
            'options' => array('secpass_reqnum' => 'on', 'secpass_reqmixcase' => 'on', 'secpass_reqspec' => 'on'),
            'request' => array('action' => 'changepassword', 'password' => $password, 'password_confirm' => $password, 'current_password' => 'Correct1!'),
        ));
        if ($error !== '') {
            self::assertStringContainsString($error, $state['password_error']);
            self::assertTrue($state['password_matches_original']);
            self::assertNotContains('password_success', $state['messages']);
            self::assertSame(array(42, 43), $state['cache_users']);
            self::assertSame(42, $state['session']['sess_user_id']);
        } else {
            self::assertTrue($state['password_matches_new']);
            self::assertContains('password_success', $state['messages']);
            self::assertSame(array(43), $state['cache_users']);
            self::assertArrayNotHasKey('sess_user_id', $state['session']);
        }
        self::assertSame(array('lastlogin' => 0, 'must_change_password' => ''), $state['other_realm_policy']);
    }

    public static function complexityCases(): array
    {
        return array(
            'number' => array('LettersOnly!', 'at least 1 numerical character'),
            'case' => array('lowercase1!', 'mix of lower case and upper case'),
            'special' => array('Mixedcase1', 'at least 1 special character'),
            'all requirements met' => array('NewCorrect2!', ''),
        );
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

    #[\PHPUnit\Framework\Attributes\DataProvider('logoutCases')]
    public function testLogoutClearsSessionAndRendersReason(string $action, string $reason): void
    {
        $state = $this->runController(array('mode' => 'logout', 'request' => array('action' => $action)));
        self::assertSame(array(), $state['session']);
        self::assertSame(array('logout_pre_session_destroy', 'CLEAR_COOKIES', 'DESTROY_SESSION', 'logout_post_session_destroy'), $state['events']);
        self::assertStringContainsString($reason, $state['html']);
        self::assertSame(array(), $state['audit']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('logoutRequests')]
    public function testLogoutRequestBoundary(string $method, string $token, mixed $action, int $status, bool $form): void
    {
        $state = $this->runController(['mode' => 'logout', 'method' => $method, 'csrf' => $token, 'request' => ['action' => $action]]);
        self::assertSame($status, $state['status']);
        self::assertSame([], $state['events']);
        self::assertSame(42, $state['session']['sess_user_id']);
        self::assertSame([42, 43], $state['cache_users']);
        self::assertSame([42, 43], $state['session_users']);
        if ($form) {
            $document = new \DOMDocument();
            @$document->loadHTML($state['html']);
            $xpath = new \DOMXPath($document);
            self::assertSame('post', $xpath->evaluate('string(//form/@method)'));
            // The target comes from the configured path, never from the Host header.
            self::assertSame('/monitoring/logout.php', $xpath->evaluate('string(//form/@action)'));
            self::assertSame($action, $xpath->evaluate('string(//input[@name="action"]/@value)'));
            self::assertStringStartsWith('sid:', $xpath->evaluate('string(//input[@name="__csrf_magic"]/@value)'));
            self::assertSame('Logout', $xpath->evaluate('string(//button)'));
        } else {
            self::assertSame('', $state['html']);
        }
    }

    public static function logoutRequests(): iterable
    {
        foreach (['', 'timeout', 'disabled', 'remote'] as $action) {
            yield 'confirm ' . $action => ['GET', 'missing', $action, 200, true];
        }
        yield 'HEAD is safe' => ['HEAD', 'missing', 'timeout', 200, false];
        foreach (['missing', 'invalid', 'expired'] as $token) {
            yield $token => ['POST', $token, 'timeout', 403, false];
        }
        yield 'unsupported method' => ['DELETE', 'valid', '', 405, false];
        yield 'array action' => ['POST', 'valid', ['timeout'], 400, false];
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
        $command = array(PHP_BINARY, '-d', 'memory_limit=2G', '-d', 'auto_prepend_file=', '-d', 'error_reporting=24575', '-d', 'pcov.directory=' . $root, '-d', 'pcov.exclude=~/(include/vendor|tests)/~', $root . '/tests/Fixtures/auth-controller-native.php', json_encode($scenario, JSON_THROW_ON_ERROR), $directory);
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
                $sources = array('tests/Unit/Security/Auth/AuthControllerNativeCoverageTest.php', 'composer.lock', 'tests/composer.lock', 'include/vendor/csrf/csrf-magic.php', 'tests/Fixtures/auth-controller-native.php', 'tests/Fixtures/rrd-process-coverage.php', 'tests/Helpers/NativeChildCoverageEvidence.php', 'lib/auth.php', 'src/IdentityAccess/Infrastructure/Legacy/LegacyPasswordHasher.php', 'src/IdentityAccess/Application/Port/LocalCredentials.php', 'src/IdentityAccess/Application/Command/VerifyLocalCredentials.php', 'src/IdentityAccess/Infrastructure/Legacy/NativeLocalCredentials.php', 'src/IdentityAccess/Application/Port/LocalLoginPolicy.php', 'src/IdentityAccess/Application/Command/ApplyLocalLoginPolicy.php', 'src/IdentityAccess/Infrastructure/Legacy/NativeLocalLoginPolicy.php', 'src/IdentityAccess/Domain/PasswordComplexity.php', 'src/IdentityAccess/Infrastructure/Legacy/NativePasswordComplexity.php', 'src/IdentityAccess/Application/Port/AccountLockoutStatus.php', 'src/IdentityAccess/Application/Query/IsAccountLocked.php', 'src/IdentityAccess/Infrastructure/Legacy/NativeAccountLockoutStatus.php', 'src/IdentityAccess/Application/Command/TransitionAuthenticationSession.php', 'src/IdentityAccess/Application/Port/AuthenticationTransitionAccounts.php', 'src/IdentityAccess/Application/Port/AuthenticationTransitionSession.php', 'src/IdentityAccess/Infrastructure/Legacy/NativeAuthenticationTransitionAccounts.php', 'src/IdentityAccess/Infrastructure/Legacy/NativeAuthenticationTransitionSession.php', 'auth_login.php', 'src/IdentityAccess/Infrastructure/Symfony/LoginPageRenderer.php', 'templates/identity_access/login.html.twig', 'config/services.yaml', 'auth_changepassword.php', 'logout.php', 'src/Platform/Infrastructure/Symfony/LegacyLogoutPage.php', 'src/Platform/Infrastructure/Symfony/NativeLogoutPage.php', 'src/Platform/Infrastructure/Symfony/Controller/LogoutMessageController.php', 'templates/platform/logout_message.html.twig', 'lib/ldap.php', 'include/global_constants.php', 'lib/rrd.php', 'src/Graphing/Infrastructure/Rrd/ProxyCipher.php', 'lib/dsdebug.php', 'lib/rrd_maintenance.php', 'lib/poller.php', 'lib/boost.php', 'lib/api_data_source.php', 'lib/rrdcheck.php', 'lib/dsstats.php');
                $mode = $scenario['mode'] ?? 'login';
                $controller = array('login' => 'auth_login.php', 'password' => 'auth_changepassword.php', 'logout' => 'logout.php')[$mode];
                $markers = array('auth-controller-observed:' . $mode, 'auth-controller-persisted-state-readback');
                $hits = $mode === 'logout' ? [$controller, 'src/Platform/Infrastructure/Symfony/LegacyLogoutPage.php'] : ['lib/auth.php', $controller];
                if ($scenario['malformed_login'] ?? false) {
                    // Shape refusal precedes any authentication helper execution.
                    $hits = [$controller];
                }
                if ($scenario['render_login'] ?? false) {
                    $hits[] = 'src/IdentityAccess/Infrastructure/Symfony/LoginPageRenderer.php';
                }
                if ($scenario === [] || ($scenario['legacy_hash'] ?? false) || isset($scenario['options']['secpass_forceold'])) {
                    $hits[] = 'src/IdentityAccess/Application/Command/ApplyLocalLoginPolicy.php';
                    $hits[] = 'src/IdentityAccess/Infrastructure/Legacy/NativeLocalLoginPolicy.php';
                }
                if (isset($scenario['options']['secpass_forceold'])) {
                    $hits[] = 'src/IdentityAccess/Domain/PasswordComplexity.php';
                    $hits[] = 'src/IdentityAccess/Infrastructure/Legacy/NativePasswordComplexity.php';
                }
                if ($scenario === [] || ($scenario['legacy_hash'] ?? false)) {
                    $hits[] = 'src/IdentityAccess/Application/Query/IsAccountLocked.php';
                    $hits[] = 'src/IdentityAccess/Infrastructure/Legacy/NativeAccountLockoutStatus.php';
                    $hits[] = 'src/IdentityAccess/Infrastructure/Legacy/LegacyPasswordHasher.php';
                    $hits[] = 'src/IdentityAccess/Application/Command/VerifyLocalCredentials.php';
                    $hits[] = 'src/IdentityAccess/Infrastructure/Legacy/NativeLocalCredentials.php';
                }
                if ($mode === 'login' && ($scenario === [] || ($scenario['legacy_hash'] ?? false)
                    || ($scenario['group_realm'] ?? false) || ($scenario['lock_during_transition'] ?? false))) {
                    $hits = array_merge($hits, array(
                        'src/IdentityAccess/Application/Command/TransitionAuthenticationSession.php',
                        'src/IdentityAccess/Infrastructure/Legacy/NativeAuthenticationTransitionAccounts.php',
                        'src/IdentityAccess/Infrastructure/Legacy/NativeAuthenticationTransitionSession.php',
                    ));
                }
                if ($mode === 'logout' && (!isset($scenario['method']) || $scenario['method'] === 'GET' || $scenario['method'] === 'HEAD')) {
                    $hits = array_merge($hits, ['src/Platform/Infrastructure/Symfony/NativeLogoutPage.php', 'src/Platform/Infrastructure/Symfony/Controller/LogoutMessageController.php']);
                }
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
            foreach (glob($directory . '/sess_*') as $file) {
                unlink($file);
            }
            foreach (glob($directory . '/include/*') as $file) {
                unlink($file);
            }
            rmdir($directory . '/include');
            rmdir($directory);
        }
    }
}
