<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Platform\Infrastructure\Legacy\BrowserWorkerSession;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class BrowserWorkerSessionTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/browser-worker-session-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->directory, 0700));
        session_save_path($this->directory);
        session_id(bin2hex(random_bytes(16)));
        ini_set('session.use_cookies', '0');
    }

    protected function tearDown(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_abort();
        }
        if (is_dir($this->directory . '/coverage')) {
            foreach (glob($this->directory . '/coverage/*') ?: [] as $report) unlink($report);
            rmdir($this->directory . '/coverage');
        }
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    public static function previousResults(): iterable
    {
        yield 'explicit handled' => [true];
        yield 'legacy null handled' => [null];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('previousResults')]
    public function testClosePersistsThroughNativeHandlerAndRestoresAndDelegatesTheErrorHandler(?bool $result): void
    {
        session_set_save_handler(new class extends \SessionHandler {
            public function write(string $id, string $data): bool
            {
                trigger_error('Unrelated native fixture notice', E_USER_NOTICE);
                return parent::write($id, $data);
            }
        }, true);
        $noticed = 0;
        $handler = static function (int $severity) use (&$noticed, $result): ?bool {
            if ($severity === E_USER_NOTICE) {
                $noticed++;
                return $result;
            }
            return false;
        };
        set_error_handler($handler);
        try {
            self::assertTrue(session_start());
            $_SESSION = ['state' => 'kept'];
            $id = session_id();
            BrowserWorkerSession::close();
            self::assertSame(PHP_SESSION_NONE, session_status());
            self::assertSame(1, $noticed);
            self::assertSame('state|s:4:"kept";', file_get_contents($this->directory . '/sess_' . $id));
            $restored = set_error_handler(static fn() => false);
            restore_error_handler();
            self::assertSame($handler, $restored);
        } finally {
            restore_error_handler();
        }
    }

    public function testInactiveSessionIsRefusedAndRestoresThePreviousErrorHandler(): void
    {
        self::assertSame(PHP_SESSION_NONE, session_status());
        $handler = static fn(): bool => false;
        set_error_handler($handler);
        try {
            $failure = null;
            try {
                BrowserWorkerSession::close();
            } catch (\RuntimeException $error) {
                $failure = $error;
            }
            self::assertNotNull($failure);
            self::assertSame('Native session close could not be confirmed.', $failure->getMessage());
            self::assertSame(PHP_SESSION_NONE, session_status());
            $restored = set_error_handler(static fn(): bool => false);
            restore_error_handler();
            self::assertSame($handler, $restored);
        } finally {
            restore_error_handler();
        }
    }

    public function testUnavailableNativeCloseAbortsTheRealSessionWithoutPublishingChangedState(): void
    {
        self::assertTrue(session_start());
        $_SESSION = ['state' => 'kept'];
        $identity = session_id();
        self::assertTrue(session_write_close());
        $root = dirname(__DIR__, 2);
        $environment = [];
        $stack = null;
        if (\PHPUnit\Runner\CodeCoverage::instance()->isActive()) {
            require_once __DIR__ . '/NativeCoverageStack.php';
            $stack = NativeCoverageStack::capture($root);
            self::assertTrue(mkdir($this->directory . '/coverage', 0700));
            $environment['KADUPUL_SESSION_CLOSE_COVERAGE_STACK'] = json_encode($stack, JSON_THROW_ON_ERROR);
        }
        $child = new \Symfony\Component\Process\Process([PHP_BINARY,'-d','auto_prepend_file=','-d','pcov.directory=' . $root,'-d','disable_functions=' . trim((string) ini_get('disable_functions') . ',session_write_close', ','),$root . '/tests/Fixtures/browser-session-close-unavailable.php'], null, $environment, json_encode(['directory' => $this->directory,'id' => $identity], JSON_THROW_ON_ERROR));
        $child->mustRun();
        self::assertSame('', $child->getErrorOutput());
        self::assertSame(['available' => false,'failure' => \Error::class,'status' => PHP_SESSION_NONE,'unchanged' => true], json_decode($child->getOutput(), true, 8, JSON_THROW_ON_ERROR));
        self::assertSame('state|s:4:"kept";', file_get_contents($this->directory . '/sess_' . $identity));
        self::assertStringNotContainsString('PRIVATE-', $child->getOutput());
        if ($stack !== null) {
            require_once $root . '/tests/Helpers/NativeChildCoverageEvidence.php';
            $reports = glob($this->directory . '/coverage/*.coverage');
            self::assertIsArray($reports);
            self::assertCount(1, $reports);
            $sources = ['composer.lock','tests/composer.lock','tests/Symfony/NativeCoverageStack.php','tests/Fixtures/rrd-process-coverage.php','tests/Helpers/NativeChildCoverageEvidence.php','tests/Symfony/BrowserWorkerSessionTest.php','src/Platform/Infrastructure/Legacy/BrowserWorkerSession.php'];
            $producer = 'tests/Fixtures/browser-session-close-unavailable.php';
            $markers = ['native-close-refusal-and-persisted-state-observed'];
            $helper = 'src/Platform/Infrastructure/Legacy/BrowserWorkerSession.php';
            $coverage = \NativeChildCoverageEvidence::load($reports[0], $root, $producer, 'disabled-native-close', $sources, $markers, [$helper], $stack);
            self::assertSame(count($sources) + count($markers) + 15, \NativeChildCoverageEvidence::verifyRejections($reports[0], $root, $producer, 'disabled-native-close', $sources, $markers, [$helper], $helper, $stack));
            $abort = array_keys(array_map('trim', file($root . '/' . $helper, FILE_IGNORE_NEW_LINES)), 'session_abort();', true);
            self::assertCount(1, $abort);
            self::assertNotEmpty($coverage->getData()->lineCoverage()[realpath($root . '/' . $helper)][$abort[0] + 1]);
            \PHPUnit\Runner\CodeCoverage::instance()->codeCoverage()->merge($coverage);
        }
    }

    public function testHandlerWriteFailureDoesNotBecomeAConfirmedClose(): void
    {
        session_set_save_handler(new class extends \SessionHandler {
            public function write(string $id, string $data): bool
            {
                return false;
            }
        }, true);
        self::assertTrue(session_start());
        $_SESSION = ['private_canary' => 'never echo this'];
        $failure = null;
        try {
            BrowserWorkerSession::close();
        } catch (\RuntimeException $error) {
            $failure = $error;
        }
        self::assertNotNull($failure);
        self::assertSame('Native session handler write could not be confirmed.', $failure->getMessage());
        self::assertSame(PHP_SESSION_NONE, session_status());
    }
}
