<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Kadupul\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/Helpers/NativeChildCoverageEvidence.php';

final class SessionCredentialBindingTest extends TestCase
{
    #[DataProvider('cases')]
    public function testRealSharedSessionsRequireCurrentCredentialBindings(string $scenario, string $storage, bool $accepted): void
    {
        $directory = sys_get_temp_dir() . '/session-binding-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $coverage = \PHPUnit\Runner\CodeCoverage::instance()->isActive()
            ? \PHPUnit\Runner\CodeCoverage::instance()->codeCoverage()
            : null;
        try {
            $process = proc_open([PHP_BINARY, '-d', 'auto_prepend_file=', '-d', 'pcov.directory=' . dirname(__DIR__, 2), '-d', 'pcov.exclude=~/(include/vendor|tests)/~', dirname(__DIR__) . '/Fixtures/symfony-credential-session-native.php', $scenario, $directory, $storage, $coverage !== null ? 'coverage' : ''], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            self::assertIsResource($process);
            $output = stream_get_contents($pipes[1]);
            $errors = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), $errors . $output);
            self::assertSame('', $errors);
            if ($coverage !== null) {
                $reports = glob($directory . '/*.coverage');
                self::assertCount(1, $reports);
                $requiredMarkers = ['session-state-observed'];
                $hitSources = ['src/IdentityAccess/Infrastructure/Legacy/SharedSession.php'];
                if ($accepted || !str_contains($scenario, '-policy-')) {
                    $hitSources[] = 'lib/auth.php';
                }
                if (str_ends_with($scenario, '-rollback') || str_ends_with($scenario, '-write')) {
                    $requiredMarkers[] = 'response-dispatched';
                    $hitSources[] = 'src/IdentityAccess/Infrastructure/Symfony/CompleteSessionRevocation.php';
                }
                if (str_starts_with($scenario, 'about-')) {
                    $hitSources[] = 'src/IdentityAccess/Infrastructure/Legacy/LegacyAboutAccess.php';
                    $hitSources[] = 'src/IdentityAccess/Infrastructure/Legacy/LegacyBrowserAuthentication.php';
                }
                if (str_starts_with($scenario, 'about-restore-') && $accepted) {
                    $requiredMarkers[] = 'restore-handoff-observed';
                    $hitSources[] = 'src/IdentityAccess/Infrastructure/Legacy/BrowserAuthenticationSql.php';
                    $hitSources[] = 'src/IdentityAccess/Infrastructure/Legacy/NativeAuthenticationSession.php';
                    $hitSources[] = 'src/IdentityAccess/Infrastructure/Legacy/' . ($storage === 'database' ? 'AuthenticationDatabaseSessionHandler.php' : 'AuthenticationFileSessionHandler.php');
                }
                if (str_contains($scenario, '-policy-')) {
                    $requiredMarkers[] = 'restoration-policy-state-observed';
                    $hitSources[] = 'src/IdentityAccess/Infrastructure/Legacy/BrowserAuthenticationSql.php';
                }
                $childCoverage = \NativeChildCoverageEvidence::load($reports[0], dirname(__DIR__, 2), 'tests/Fixtures/symfony-credential-session-native.php', $scenario . ':' . $storage, self::coverageSources(), $requiredMarkers, $hitSources);
                if (($scenario === 'current' || $scenario === 'about-current' || $scenario === 'about-restore-basic') && $storage === 'file'
                    || $scenario === 'about-restore-remember' && $storage === 'database'
                    || $scenario === 'about-restore-remember-policy-forced' && $storage === 'file') {
                    self::assertSame(36 + count($requiredMarkers), \NativeChildCoverageEvidence::verifyRejections($reports[0], dirname(__DIR__, 2), 'tests/Fixtures/symfony-credential-session-native.php', $scenario . ':' . $storage, self::coverageSources(), $requiredMarkers, $hitSources, 'src/IdentityAccess/Infrastructure/Symfony/CompleteSessionRevocation.php'));
                    if ($scenario !== 'current') {
                        $receipt = file_get_contents($reports[0] . '.json');
                        $evidence = json_decode($receipt, true, 512, JSON_THROW_ON_ERROR);
                        $source = 'src/IdentityAccess/Infrastructure/Legacy/' . ($scenario === 'about-current' ? 'LegacyAboutAccess.php' : 'NativeAuthenticationSession.php');
                        $evidence['sources'][$source] = str_repeat('0', 64);
                        try {
                            file_put_contents($reports[0] . '.json', json_encode($evidence, JSON_THROW_ON_ERROR));
                            try {
                                \NativeChildCoverageEvidence::load($reports[0], dirname(__DIR__, 2), 'tests/Fixtures/symfony-credential-session-native.php', $scenario . ':' . $storage, self::coverageSources(), $requiredMarkers, $hitSources);
                                self::fail('Changed native source hash was accepted.');
                            } catch (\RuntimeException $error) {
                                self::assertStringContainsString('source', $error->getMessage());
                            }
                        } finally {
                            file_put_contents($reports[0] . '.json', $receipt);
                        }
                    }
                }
                $coverage->merge($childCoverage);
            }
            $state = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
            self::assertSame($accepted, $state['accepted']);
            self::assertSame(!$accepted, $state['unauthenticated']);
            if (str_starts_with($scenario, 'unbound-remembered')) {
                self::assertSame(['cookie_cleared' => true, 'legacy' => false, 'remaining' => 2], $state['transition']);
            }
            self::assertSame(!$accepted && !str_contains($scenario, '-policy-'), $state['revoked']);
            if (str_starts_with($scenario, 'about-restore-') && $accepted) {
                self::assertTrue($state['legacy_valid']);
                self::assertTrue($state['next_console']);
            }
            if (str_contains($scenario, '-policy-') && !$accepted) {
                self::assertSame($state['policy_before'], $state['policy_after']);
            }
            if (str_ends_with($scenario, '-rollback')) {
                self::assertTrue($state['refused_while_active']);
            }
            if (str_ends_with($scenario, '-write')) {
                self::assertSame(9, $state['initial']);
            }
        } finally {
            foreach (glob($directory . '/*') as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }

    private static function coverageSources(): array
    {
        return ['composer.lock', 'tests/composer.lock', 'tests/Symfony/SessionCredentialBindingTest.php', 'lib/rrd.php', 'src/Graphing/Infrastructure/Rrd/ProxyCipher.php', 'lib/dsdebug.php', 'lib/rrd_maintenance.php', 'lib/poller.php', 'lib/boost.php', 'lib/api_data_source.php', 'lib/rrdcheck.php', 'lib/dsstats.php', 'tests/Fixtures/rrd-process-coverage.php', 'tests/Helpers/NativeChildCoverageEvidence.php', 'lib/auth.php', 'src/IdentityAccess/Infrastructure/Legacy/LegacyAuthenticatedSession.php', 'src/IdentityAccess/Infrastructure/Legacy/SharedSession.php', 'src/IdentityAccess/Infrastructure/Legacy/ReadOnlyDatabaseSessionHandler.php', 'src/Navigation/Infrastructure/Legacy/LegacyLinkAccess.php', 'src/IdentityAccess/Infrastructure/Symfony/CompleteSessionRevocation.php', 'src/IdentityAccess/Infrastructure/Legacy/LegacyAboutAccess.php', 'src/IdentityAccess/Infrastructure/Legacy/LegacyBrowserAuthentication.php', 'src/IdentityAccess/Infrastructure/Legacy/BrowserAuthenticationSql.php', 'src/IdentityAccess/Infrastructure/Legacy/NativeAuthenticationSession.php', 'src/IdentityAccess/Infrastructure/Legacy/AuthenticationDatabaseSessionHandler.php', 'src/IdentityAccess/Infrastructure/Legacy/AuthenticationFileSessionHandler.php'];
    }

    public static function cases(): iterable
    {
        foreach (['file', 'database'] as $storage) {
            foreach (['current' => true, 'unbound' => false, 'reset' => false, 'reset-write' => false, 'rehash' => true, 'rehash-write' => true, 'successive-rehash' => true, 'unbound-remembered' => false, 'unbound-remembered-name' => false, 'unbound-remembered-rollback' => false, 'unbound-remembered-marker-missing-rollback' => false, 'unbound-remembered-marker-malformed-rollback' => false, 'about-current' => true, 'about-unbound' => false, 'about-reset' => false, 'about-rehash' => true, 'about-restore-basic' => true, 'about-restore-remember' => true, 'about-restore-remember-policy-forced' => false, 'about-restore-remember-policy-must-off' => true, 'about-restore-remember-policy-change-off' => true, 'about-restore-remember-policy-nonlocal' => true, 'about-restore-basic-policy-forced' => true, 'empty' => false, 'malformed' => false, 'deleted-live' => false, 'about-empty' => false, 'about-malformed' => false, 'about-deleted-live' => false] as $scenario => $accepted) {
                yield $storage . '-' . $scenario => [$scenario, $storage, $accepted];
            }
        }
    }
}
