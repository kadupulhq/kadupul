<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Platform\Infrastructure\Legacy\InstallerHealthAssessment;
use Kadupul\Platform\Infrastructure\Legacy\InstallerRuntimeChecks;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;

final class InstallerReadinessEvidenceTest extends TestCase
{
    public function testMissingWorkerFilesAreReportedWithoutCreatingThem(): void
    {
        $directory = sys_get_temp_dir() . '/installer-readiness-' . bin2hex(random_bytes(12));
        mkdir($directory, 0700);
        try {
            $checks = InstallerRuntimeChecks::inspect($directory, false);
            foreach (array_slice($checks, 0, 2) as $check) {
                $this->assertFalse($check['ok']);
                $this->assertStringContainsString('Restore the complete release', $check['remedy']);
            }
            $this->assertSame(['.', '..'], scandir($directory));
            $this->assertNotContains('Background worker launch', array_column($checks, 'name'));
        } finally {
            rmdir($directory);
        }
    }

    public function testInstalledWorkerFilesAndWebLaunchFacilityAreObservedReadOnly(): void
    {
        $checks = InstallerRuntimeChecks::inspect(dirname(__DIR__, 2), true);
        foreach (array_slice($checks, 0, 2) as $check) {
            $this->assertTrue($check['ok'], $check['name']);
        }
        $launch = $checks[array_key_last($checks)];
        $this->assertSame('Background worker launch', $launch['name']);
        $this->assertSame(PHP_OS_FAMILY === 'Windows' ? function_exists('popen') && function_exists('pclose') : function_exists('exec'), $launch['ok']);
        $this->assertStringContainsString('not exercised', $launch['observed']);
    }

    public function testSuccessfulReceiptDoesNotClaimPollerOrRecoveryVerification(): void
    {
        $health = InstallerHealthAssessment::assess('1.2.38', '1.2.38', 'succeeded', 0);
        $this->assertSame(['verified', 'verified', 'verified', 'not_assessed', 'not_assessed', 'not_assessed', 'not_assessed'], array_column($health, 'status'));
        $this->assertStringContainsString('No poller or device', $health[3]['observed']);
        $this->assertStringContainsString('isolated restore', $health[5]['remedy']);
        $this->assertSame('Database privileges', $health[6]['name']);
        $this->assertStringContainsString('were not verified', $health[6]['observed']);
    }

    public function testDisabledNativeBackgroundLaunchFunctionIsActionable(): void
    {
        $function = PHP_OS_FAMILY === 'Windows' ? 'popen' : 'exec';
        $root = dirname(__DIR__, 2);
        $code = 'require $argv[1]; echo json_encode(Kadupul\\Platform\\Infrastructure\\Legacy\\InstallerRuntimeChecks::inspect($argv[2], true), JSON_THROW_ON_ERROR);';
        $process = new Process([PHP_BINARY, '-d', 'disable_functions=' . $function, '-r', $code,
            $root . '/src/Platform/Infrastructure/Legacy/InstallerRuntimeChecks.php', $root]);
        $process->mustRun();
        $checks = json_decode($process->getOutput(), true, 32, JSON_THROW_ON_ERROR);
        $launch = $checks[array_key_last($checks)];
        $this->assertSame('Background worker launch', $launch['name']);
        $this->assertFalse($launch['ok']);
        $this->assertStringContainsString('disabled', $launch['observed']);
        $this->assertStringContainsString($function, $launch['remedy']);
    }

    public function testSchemaAloneCannotEstablishSuccessfulInstallationOrCollectorCompletion(): void
    {
        $health = InstallerHealthAssessment::assess('1.2.38', '1.2.38', 'not_started', 0);
        $this->assertSame(['verified', 'not_assessed', 'not_assessed'], array_slice(array_column($health, 'status'), 0, 3));
    }

    public function testPartialCollectorsAndFailedSchemaRemainActionable(): void
    {
        $partial = InstallerHealthAssessment::assess('1.2.38', '1.2.38', 'partial', 2);
        $this->assertSame(['verified', 'action_required', 'action_required'], array_slice(array_column($partial, 'status'), 0, 3));
        $failed = InstallerHealthAssessment::assess('1.2.37', '1.2.38', 'failed', 0);
        $this->assertSame(['action_required', 'action_required', 'not_assessed'], array_slice(array_column($failed, 'status'), 0, 3));
        $contradictory = InstallerHealthAssessment::assess('1.2.38', '1.2.38', 'succeeded', 2);
        $this->assertSame(['verified', 'action_required', 'action_required'], array_slice(array_column($contradictory, 'status'), 0, 3));
    }

    public static function activity(): iterable
    {
        yield 'fresh missing' => [null, '300', '300', 'not_assessed'];
        yield 'fresh zero' => ['0', '300', '300', 'not_assessed'];
        yield 'native tolerance boundary' => ['692', '300', '300', 'verified'];
        yield 'native stale boundary' => ['691', '300', '300', 'action_required'];
        yield 'recent' => ['990', '300', '60', 'verified'];
        yield 'future' => ['1001', '300', '300', 'action_required'];
        yield 'malformed timestamp' => ['1e3', '300', '300', 'action_required'];
        yield 'whitespace timestamp' => [' 990', '300', '300', 'action_required'];
        yield 'oversized timestamp' => [str_repeat('9', 40), '300', '300', 'action_required'];
        yield 'invalid schedule' => ['990', '0', '300', 'action_required'];
        yield 'invalid poller interval' => ['990', '300', 'invalid', 'action_required'];
    }

    #[DataProvider('activity')]
    public function testPersistedPollerActivityUsesTheNativeSchedulingTolerance(?string $lastStarted, string $cron, string $interval, string $status): void
    {
        $health = InstallerHealthAssessment::pollerActivity($lastStarted, $cron, $interval, 1000);
        $this->assertSame($status, $health['status']);
        if ($status === 'verified') {
            $this->assertStringContainsString('Completion and device health are not verified', $health['observed']);
        }
    }
}
