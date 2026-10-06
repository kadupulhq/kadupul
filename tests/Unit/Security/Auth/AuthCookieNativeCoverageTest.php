<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../Helpers/PestCodeCoverageCompatibility.php';

final class AuthCookieNativeCoverageTest extends TestCase
{
    use \PestCodeCoverageCompatibility;

    private static bool $coverageEvidenceChecked = false;

    #[\PHPUnit\Framework\Attributes\DataProvider('acceptedCookies')]
    public function testAValidCookieRotatesOnlyItsPersistedPrincipalAndAuditsTheActualIdentity(array $scenario, int $principal, int $realm): void
    {
        $state = $this->runCookie($scenario);
        self::assertSame($principal, $state['result']);
        self::assertSame(array('clear', 'issue'), $state['events']);
        self::assertCount(isset($scenario['identity']) && is_numeric($scenario['identity']) ? 0 : 2, $state['identity_queries']);
        if ($state['identity_queries'] !== array()) {
            foreach ($state['identity_queries'] as $lookup) {
                self::assertSame(isset($scenario['realm']) ? array('alice', (string) $realm) : array('alice'), $lookup['params']);
            }
        }
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

    #[\PHPUnit\Framework\Attributes\DataProvider('refusedCookies')]
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

    #[\PHPUnit\Framework\Attributes\DataProvider('revokedCookies')]
    public function testClearingAUsernameCookieRevokesOnlyItsRealmAndLeavesOtherTokens(array $scenario, int $principal): void
    {
        $state = $this->runCookie(array_merge($scenario, array('operation' => 'clear')));
        self::assertSame(array_values(array_diff(array(42, 43, 44), array($principal))), array_column($state['rows'], 'user_id'));
        self::assertSame(array('clear'), $state['events']);
        self::assertCount(isset($scenario['identity']) ? 0 : 1, $state['identity_queries']);
        self::assertNull($state['issued']);
        self::assertSame(array(), $state['audit']);
    }

    public static function revokedCookies(): array
    {
        return array('legacy local' => array(array(), 42), 'explicit local' => array(array('realm' => '0,'), 42), 'explicit domain' => array(array('realm' => '3,'), 43), 'numeric local bypasses name lookup' => array(array('identity' => '42'), 42), 'numeric domain bypasses name lookup' => array(array('identity' => '43', 'realm' => '3,'), 43));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('missingClearIdentities')]
    public function testClearingAnUnresolvedUsernameDoesNotRevokeOtherAccounts(array $scenario): void
    {
        $state = $this->runCookie(['operation' => 'clear'] + $scenario);
        self::assertSame([42,43,44], array_column($state['rows'], 'user_id'));
        self::assertSame([], $state['events']);
        self::assertNull($state['issued']);
        self::assertSame([], $state['audit']);
        self::assertCount(1, $state['identity_queries']);
    }

    public static function missingClearIdentities(): array
    {
        return [[['identity' => 'unknown']], [['realm' => '4,']]];
    }

    public function testBasicAccountLookupUsesItsRecordedRealmWithoutCookieRotation(): void
    {
        $state = $this->runCookie(['operation' => 'basic']);
        self::assertSame(43, $state['result']['id']);
        self::assertSame(2, $state['result']['realm']);
        self::assertSame([], $state['events']);
        self::assertSame([], $state['audit']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('localPasswordCases')]
    public function testLocalPasswordVerificationAndLockoutStayInTheLocalRealm(string $password, bool $accepted, int $failed, bool $legacy = false): void
    {
        $state = $this->runCookie(['operation' => 'local-password','password' => $password,'legacy_schema' => $legacy,'config' => ['secpass_lockfailed' => $legacy ? 0 : 3]]);
        self::assertSame($accepted ? 42 : null, $state['result']['id'] ?? null);
        self::assertSame(!$accepted, $state['error']);
        self::assertSame($failed, $state['failed_attempts'][42]);
        self::assertSame(0, $state['failed_attempts'][43]);
        self::assertSame([], $state['events']);
    }

    public static function localPasswordCases(): array
    {
        return [['test-password',true,0], ['wrong-test-password',false,1], ['',false,1], ['test-password',true,0,true], ['wrong-test-password',false,0,true], ['',false,0,true]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('historyCases')]
    public function testNativePasswordHistoryEnforcesCurrentAndRetainedHashesButExpiresOldHashes(string $password, int $history, bool $allowed): void
    {
        $state = $this->runCookie(['operation' => 'password-history','password' => $password,'config' => ['secpass_history' => $history]]);
        self::assertSame($allowed, $state['result']);
        self::assertSame([], $state['events']);
        self::assertSame([], $state['audit']);
    }

    public static function historyCases(): array
    {
        return [['current-test-password',1,false], ['retained-test-password',1,false], ['expired-test-password',1,true], ['new-test-password',2,true], ['retained-test-password',0,true]];
    }

    public function testDirectoryCnSearchUsesOnlyItsConfiguredDomainRow(): void
    {
        $state = $this->runCookie(['operation' => 'domain-cn']);
        self::assertSame(['error_num' => 0,'cn' => ['cn' => 'Fixture User','mail' => 'fixture@example.invalid']], $state['result']);
        self::assertSame([], $state['events']);
        $missing = $this->runCookie(['operation' => 'domain-cn','directory_realm' => 1004]);
        self::assertSame([['username' => 'alice','host' => 'fixture.example','cn' => ['cn','mail']]], $state['directory_calls']);
        self::assertFalse($missing['result']);
        self::assertSame([], $missing['directory_calls']);
        self::assertSame([], $missing['events']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('domainCases')]
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
            'matching name in foreign realm is not accepted' => array(array('foreign_realm' => true), null, true, array('bind'), 0),
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
                $childCoverage = NativeChildCoverageEvidence::load($reports[0], $root, 'tests/Fixtures/auth-cookie-native.php', json_encode($scenario, JSON_THROW_ON_ERROR), array('lib/auth.php', 'lib/graph_item_choices.php', 'include/global_constants.php', 'tests/Fixtures/rrd-process-coverage.php', 'tests/Helpers/NativeChildCoverageEvidence.php', 'lib/rrd.php', 'src/Graphing/Infrastructure/Rrd/ProxyCipher.php', 'lib/dsdebug.php', 'lib/rrd_maintenance.php', 'lib/poller.php', 'lib/boost.php', 'lib/api_data_source.php', 'lib/rrdcheck.php', 'lib/dsstats.php'), array('native-auth-operation-returned', 'credential-and-audit-readback'), array('lib/auth.php'));
                if (!self::$coverageEvidenceChecked) {
                    self::assertSame(26, NativeChildCoverageEvidence::verifyRejections($reports[0], $root, 'tests/Fixtures/auth-cookie-native.php', json_encode($scenario, JSON_THROW_ON_ERROR), array('lib/auth.php', 'lib/graph_item_choices.php', 'include/global_constants.php', 'tests/Fixtures/rrd-process-coverage.php', 'tests/Helpers/NativeChildCoverageEvidence.php', 'lib/rrd.php', 'src/Graphing/Infrastructure/Rrd/ProxyCipher.php', 'lib/dsdebug.php', 'lib/rrd_maintenance.php', 'lib/poller.php', 'lib/boost.php', 'lib/api_data_source.php', 'lib/rrdcheck.php', 'lib/dsstats.php'), array('native-auth-operation-returned', 'credential-and-audit-readback'), array('lib/auth.php'), 'lib/rrd.php'));
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
