<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Kadupul\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/Helpers/PhpSource.php';
require_once dirname(__DIR__) . '/Helpers/NativeChildCoverageEvidence.php';

final class LegacyLinkSessionTest extends TestCase
{
    private string $directory;
    private bool $fullPageEvidenceChecked = false;
    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/native-link-session-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
    }
    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/include/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->directory . '/include')) {
            rmdir($this->directory . '/include');
        }
        foreach (glob($this->directory . '/*') as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }
    private function request(string $scenario, string $storage, string $stage): array
    {
        $coverage = $stage !== 'init' && \PHPUnit\Runner\CodeCoverage::instance()->isActive() ? \PHPUnit\Runner\CodeCoverage::instance()->codeCoverage() : null;
        $result = \test_php_run([PHP_BINARY, '-d', 'auto_prepend_file=', '-d', 'pcov.directory=' . dirname(__DIR__, 2), '-d', 'pcov.exclude=~/(include/vendor|tests)/~', dirname(__DIR__) . '/Fixtures/legacy-link-session-native.php', $scenario, $this->directory, $storage, $stage, $coverage !== null ? 'coverage' : '']);
        self::assertSame(0, $result['status'], $result['err'] . $result['out']);
        self::assertSame('', $result['err']);
        $state = json_decode($result['out'], true, 512, JSON_THROW_ON_ERROR);
        if ($coverage !== null) {
            $hits = ['lib/auth.php', $stage === 'save' ? 'src/Navigation/Infrastructure/Legacy/LegacyLinkStore.php' : 'link.php'];
            if ($stage === 'save') {
                $hits[] = 'src/Navigation/Infrastructure/Legacy/LegacyLinkAccess.php';
            }
            $report = $this->directory . '/' . $stage . '.coverage';
            $child = \NativeChildCoverageEvidence::load($report, dirname(__DIR__, 2), 'tests/Fixtures/legacy-link-session-native.php', "$scenario:$storage:$stage", self::sources(), ['persisted-link-state-observed'], $hits);
            if (($scenario === 'current' || ($scenario === 'grant-full-edit' && $stage === 'viewer-after' && !$this->fullPageEvidenceChecked)) && $storage === 'file') {
                self::assertSame(24, \NativeChildCoverageEvidence::verifyRejections($report, dirname(__DIR__, 2), 'tests/Fixtures/legacy-link-session-native.php', "$scenario:$storage:$stage", self::sources(), ['persisted-link-state-observed'], $hits, 'src/Navigation/Infrastructure/Legacy/LegacyLinkStore.php'));
                $this->fullPageEvidenceChecked = true;
            }
            $coverage->merge($child);
        }
        return $state;
    }
    private static function sources(): array
    {
        return ['composer.lock', 'tests/Helpers/PhpSource.php', 'tests/Helpers/NativeChildCoverageEvidence.php', 'include/session.php', 'lib/functions.php', 'lib/auth.php', 'link.php', 'src/Navigation/Domain/ExternalLink.php', 'src/Navigation/Infrastructure/Legacy/LegacyLinkStore.php', 'src/Navigation/Infrastructure/Legacy/LegacyLinkAccess.php', 'src/IdentityAccess/Infrastructure/Legacy/LegacyAuthenticatedSession.php', 'src/IdentityAccess/Infrastructure/Legacy/SharedSession.php', 'src/IdentityAccess/Infrastructure/Legacy/ReadOnlyDatabaseSessionHandler.php'];
    }
    #[DataProvider('viewerCases')]
    public function testHeaderSuppressedViewerRechecksCredentialsBeforeLookup(string $scenario, string $storage): void
    {
        $this->request($scenario, $storage, 'init');
        $state = $this->request($scenario, $storage, 'viewer');
        if (in_array($scenario, ['current', 'disabled-guest', 'auth-disabled'], true)) {
            self::assertSame(200, $state['status']);
            self::assertStringContainsString('<iframe id="content"', $state['output']);
            self::assertSame($scenario === 'auth-disabled' ? null : 9, $state['actor']);
            self::assertSame(1, $state['protected_queries']);
            self::assertSame($scenario === 'auth-disabled' && $storage === 'database', $state['revoked']);
        } elseif ($scenario === 'anonymous') {
            self::assertSame('permission_denied', $state['message']);
            self::assertNull($state['actor']);
            self::assertSame(1, $state['protected_queries']);
            self::assertSame('', $state['output']);
        } else {
            self::assertSame(403, $state['status']);
            self::assertSame('', $state['output']);
            self::assertNull($state['actor']);
            self::assertSame(0, $state['protected_queries']);
            self::assertTrue($state['revoked']);
            self::assertSame(2, $state['remaining']);
            if ($scenario === 'stale' || str_starts_with($scenario, 'unbound')) {
                self::assertFalse($state['replay']);
            }
        }
    }
    public static function viewerCases(): iterable
    {
        foreach (['file', 'database'] as $storage) {
            foreach (['current', 'stale', 'unbound', 'unbound-missing-cookie', 'unbound-malformed-cookie', 'locked', 'disabled', 'disabled-guest', 'missing', 'query-failure', 'anonymous', 'auth-disabled'] as $scenario) {
                yield "$scenario:$storage" => [$scenario, $storage];
            }
        }
    }
    #[DataProvider('grantCases')]
    public function testCommittedGrantInvalidatesActualCachedViewerDenial(string $scenario, string $storage): void
    {
        $this->request($scenario, $storage, 'init');
        $denied = $this->request($scenario, $storage, 'viewer-before');
        self::assertSame('permission_denied', $denied['message']);
        $saved = $this->request($scenario, $storage, 'save');
        self::assertFalse($saved['transaction']);
        if (in_array($scenario, ['grant-rollback', 'grant-coerce'], true)) {
            self::assertTrue($saved['failed']);
            self::assertSame(0, $saved['grant']);
            self::assertSame(0, $saved['epoch']);
            self::assertSame('Native viewer', $saved['title']);
            self::assertSame('permission_denied', $this->request($scenario, $storage, 'viewer-after')['message']);
        } else {
            self::assertFalse($saved['failed']);
            self::assertSame(1, $saved['grant']);
            self::assertSame(1, $saved['epoch']);
            self::assertSame('Saved viewer', $saved['title']);
            $refresh = $this->request($scenario, $storage, 'viewer-after');
            self::assertStringContainsString('cactiRedirect', $refresh['output']);
            self::assertStringContainsString('<iframe id="content"', $this->request($scenario, $storage, 'viewer-next')['output']);
        }
    }
    public static function grantCases(): iterable
    {
        foreach (['file', 'database'] as $storage) {
            foreach (['grant', 'grant-wrap', 'grant-rollback', 'grant-coerce'] as $scenario) {
                yield "$scenario:$storage" => [$scenario, $storage];
            }
        }
    }

    #[DataProvider('fullPageCases')]
    public function testFirstFullPageViewerAfterSaveRefreshesPermissionsSynchronously(string $scenario, string $storage): void
    {
        $this->request($scenario, $storage, 'init');
        $saved = $this->request($scenario, $storage, 'save');
        self::assertFalse($saved['failed']);
        self::assertSame(1, $saved['epoch']);
        self::assertSame(1, $saved['grant']);
        $viewer = $this->request($scenario, $storage, 'viewer-after');
        self::assertSame(200, $viewer['status']);
        self::assertStringContainsString('<iframe id="content"', $viewer['output']);
        self::assertStringNotContainsString('cactiRedirect', $viewer['output']);
        self::assertSame(1, $viewer['permission_key']);
        self::assertTrue($viewer['realms'][$saved['id'] + 10000]);
        self::assertSame(9, $viewer['actor']);
        self::assertSame(1, $viewer['protected_queries']);
        self::assertFalse($viewer['revoked']);
        $repeated = $this->request($scenario, $storage, 'viewer-after');
        self::assertSame(200, $repeated['status']);
        self::assertStringContainsString('<iframe id="content"', $repeated['output']);
        self::assertStringNotContainsString('cactiRedirect', $repeated['output']);
        self::assertSame(1, $repeated['permission_key']);
        self::assertTrue($repeated['realms'][$saved['id'] + 10000]);
    }

    public static function fullPageCases(): iterable
    {
        foreach (['file', 'database'] as $storage) {
            foreach (['grant-full-create', 'grant-full-edit', 'grant-full-true'] as $scenario) {
                yield "$scenario:$storage" => [$scenario, $storage];
            }
        }
    }

    #[DataProvider('fullPageFailureCases')]
    public function testFullPageRefreshPreservesRevocationAndRejectsMalformedHeader(string $scenario, string $storage, string $stage): void
    {
        $this->request($scenario, $storage, 'init');
        if (in_array($scenario, ['stale', 'locked'], true)) {
            $state = $this->request($scenario, $storage, $stage);
            self::assertSame(403, $state['status']);
            self::assertNull($state['actor']);
            self::assertSame(0, $state['protected_queries']);
        } else {
            self::assertFalse($this->request($scenario, $storage, 'save')['failed']);
            $state = $this->request($scenario, $storage, 'viewer-after');
            self::assertSame(9, $state['actor']);
            if ($scenario === 'grant-full-malformed') {
                self::assertSame(400, $state['status']);
                self::assertSame(0, $state['protected_queries']);
            } else {
                self::assertSame('permission_denied', $state['message']);
                self::assertSame(2, $state['permission_key']);
                self::assertFalse($state['realms'][10001]);
                self::assertSame(1, $state['protected_queries']);
            }
        }
        self::assertSame('', $state['output']);
    }

    public static function fullPageFailureCases(): iterable
    {
        foreach (['file', 'database'] as $storage) {
            foreach (['grant-full-revoked' => ['viewer-after'], 'grant-full-malformed' => ['viewer-after'], 'stale' => ['viewer-malformed', 'viewer-full'], 'locked' => ['viewer-full']] as $scenario => $stages) {
                foreach ($stages as $stage) {
                    yield "$scenario:$storage:$stage" => [$scenario, $storage, $stage];
                }
            }
        }
    }
}
