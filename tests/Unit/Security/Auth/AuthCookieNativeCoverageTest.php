<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;

final class AuthCookieNativeCoverageTest extends TestCase
{
    private static bool $coverageEvidenceChecked = false;

    /** @dataProvider acceptedCookies */
    public function testAValidCookieRotatesOnlyItsPersistedPrincipalAndAuditsTheActualIdentity(array $scenario, int $principal, int $realm): void
    {
        $state = $this->runCookie($scenario);
        self::assertSame($principal, $state['result']);
        self::assertSame(array('clear', 'issue'), $state['events']);
        self::assertSame($principal, $state['issued']['id']);
        self::assertSame($realm, $state['issued']['realm']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $state['issued']['token']);
        self::assertSame(array(array('user_id' => $principal, 'result' => 2)), $state['audit']);
        self::assertCount(3, $state['rows']);
        foreach ($state['rows'] as $row) {
            self::assertSame($row['user_id'] === $principal ? hash('sha512', $state['issued']['token']) : $state['old_hash'], $row['token']);
        }
    }

    public static function acceptedCookies(): array
    {
        return array(
            'legacy local username' => array(array(), 42, 0),
            'explicit local username' => array(array('realm' => '0,'), 42, 0),
            'domain username' => array(array('realm' => '3,'), 43, 3),
            'legacy numeric principal' => array(array('identity' => '42'), 42, 0),
            'domain numeric principal' => array(array('identity' => '43', 'realm' => '3,'), 43, 3),
        );
    }

    /** @dataProvider refusedCookies */
    public function testARefusedCookieCannotRotateCredentialsOrCreateAnAuthenticationAudit(array $scenario): void
    {
        $state = $this->runCookie($scenario);
        self::assertFalse($state['result']);
        self::assertNull($state['issued']);
        self::assertSame(array(), $state['events']);
        self::assertSame(array(), $state['audit']);
        foreach ($state['rows'] as $row) {
            self::assertSame($state['old_hash'], $row['token']);
        }
    }

    public static function refusedCookies(): array
    {
        return array(
            'unknown username' => array(array('identity' => 'unknown')),
            'unknown realm' => array(array('realm' => '4,')),
            'wrong token' => array(array('wrong_token' => true)),
            'wrong host' => array(array('wrong_host' => true)),
            'disabled local account' => array(array('enabled' => '')),
            'locked local account' => array(array('locked' => 'on')),
            'disabled domain account' => array(array('realm' => '3,', 'principal' => 43, 'enabled' => '')),
            'guest account' => array(array('guest' => 42)),
            'cache disabled' => array(array('cache_disabled' => true)),
            'cache table absent' => array(array('missing_table' => true)),
            'cookie absent' => array(array('missing_cookie' => true)),
        );
    }

    /** @dataProvider revokedCookies */
    public function testClearingAUsernameCookieRevokesOnlyItsRealmAndLeavesOtherTokens(array $scenario, int $principal): void
    {
        $state = $this->runCookie(array_merge($scenario, array('operation' => 'clear')));
        self::assertSame(array_values(array_diff(array(42, 43, 44), array($principal))), array_column($state['rows'], 'user_id'));
        self::assertSame(array('clear'), $state['events']);
        self::assertNull($state['issued']);
        self::assertSame(array(), $state['audit']);
    }

    public static function revokedCookies(): array
    {
        return array('legacy local' => array(array(), 42), 'explicit domain' => array(array('realm' => '3,'), 43));
    }

    /** @dataProvider domainCases */
    public function testDirectoryLoginBindsBeforeAcceptingOnlyTheConfiguredRealmAndScopesLockout(array $scenario, ?int $principal, bool $error, array $events, int $failures): void
    {
        $state = $this->runCookie(array_merge($scenario, array('operation' => 'domain')));
        self::assertSame($principal, $state['result']['id'] ?? null);
        self::assertSame($error, $state['error']);
        self::assertSame($events, $state['events']);
        self::assertSame($failures, $state['failed_attempts'][43]);
        self::assertSame(0, $state['failed_attempts'][42]);
        self::assertSame(0, $state['failed_attempts'][44]);
        self::assertNull($state['issued']);
    }

    public static function domainCases(): array
    {
        return array(
            'existing configured realm' => array(array(), 43, false, array('bind'), 0),
            'matching name in foreign realm is not accepted' => array(array('foreign_realm' => true), null, false, array('bind'), 0),
            'missing template cannot create a principal' => array(array('foreign_realm' => true, 'missing_template' => true), null, true, array('bind'), 0),
            'rejected bind increments only its realm' => array(array('bind_failure' => true), null, true, array('bind'), 1),
            'search failure never attempts bind' => array(array('search_failure' => true), null, true, array(), 0),
        );
    }

    private function runCookie(array $scenario): array
    {
        $root = dirname(__DIR__, 4);
        $directory = sys_get_temp_dir() . '/native-auth-cookie-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $coverage = $this->getTestResultObject()->getCodeCoverage();
        $command = array(PHP_BINARY, '-d', 'auto_prepend_file=', '-d', 'error_reporting=24575', '-d', 'pcov.directory=' . $root, '-d', 'pcov.exclude=~/(include/vendor|tests)/~', $root . '/tests/Fixtures/auth-cookie-native.php', json_encode($scenario, JSON_THROW_ON_ERROR), $directory);
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
                $childCoverage = NativeChildCoverageEvidence::load($reports[0], $root, 'tests/Fixtures/auth-cookie-native.php', json_encode($scenario, JSON_THROW_ON_ERROR), array('lib/auth.php', 'include/global_constants.php', 'tests/Fixtures/rrd-process-coverage.php', 'tests/Helpers/NativeChildCoverageEvidence.php', 'lib/rrd.php', 'src/Graphing/Infrastructure/Rrd/ProxyCipher.php', 'lib/dsdebug.php', 'lib/rrd_maintenance.php', 'lib/poller.php', 'lib/boost.php', 'lib/api_data_source.php', 'lib/rrdcheck.php', 'lib/dsstats.php'), array('native-auth-operation-returned', 'credential-and-audit-readback'), array('lib/auth.php'));
                if (!self::$coverageEvidenceChecked) {
                    self::assertSame(25, NativeChildCoverageEvidence::verifyRejections($reports[0], $root, 'tests/Fixtures/auth-cookie-native.php', json_encode($scenario, JSON_THROW_ON_ERROR), array('lib/auth.php', 'include/global_constants.php', 'tests/Fixtures/rrd-process-coverage.php', 'tests/Helpers/NativeChildCoverageEvidence.php', 'lib/rrd.php', 'src/Graphing/Infrastructure/Rrd/ProxyCipher.php', 'lib/dsdebug.php', 'lib/rrd_maintenance.php', 'lib/poller.php', 'lib/boost.php', 'lib/api_data_source.php', 'lib/rrdcheck.php', 'lib/dsstats.php'), array('native-auth-operation-returned', 'credential-and-audit-readback'), array('lib/auth.php'), 'lib/rrd.php'));
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
            rmdir($directory);
        }
    }
}
