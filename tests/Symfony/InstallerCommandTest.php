<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Doctrine\DBAL\DriverManager;
use Kadupul\Platform\Contract\LegacyWorkerRunner;
use Kadupul\Platform\Infrastructure\Legacy\InstallationVersion;
use Kadupul\Platform\Infrastructure\Legacy\LegacyInstallerClient;
use Kadupul\Platform\Infrastructure\Symfony\Console\CliPresentation;
use Kadupul\Platform\Infrastructure\Symfony\Console\InstallerCommand;
use Kadupul\Platform\Infrastructure\Symfony\Console\InstallerLegacyArguments;
use Kadupul\Platform\Infrastructure\Symfony\Console\LegacyRequest;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

final class InstallerCommandTest extends TestCase
{
    private function plan(): array
    {
        return ['mode' => 'install', 'schema' => 'new_install', 'target' => '1.2.38',
            'ready' => true, 'discovery' => false, 'job' => ['state' => 'not_started'],
            'checks' => [['name' => 'PHP', 'ok' => true, 'observed' => '8.4.25', 'remedy' => 'Enable the required runtime.']]];
    }

    private function statusReceipt(string $state): array
    {
        $complete = in_array($state, ['succeeded', 'partial'], true);
        return ['state' => $state, 'schema' => $complete ? '1.2.38' : 'new_install', 'phase' => 'Waiting for the installation worker',
            'progress' => $complete ? 100 : 0, 'attempt' => 1, 'pending_collectors' => $state === 'partial' ? 1 : 0,
            'error' => in_array($state, ['failed', 'partial'], true) ? 'Installation stopped.' : null];
    }

    private function command(LegacyWorkerRunner $worker, ?CliPresentation $presentation = null): CommandTester
    {
        $version = new InstallationVersion(
            dirname(__DIR__, 2),
            DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]),
            new Filesystem(),
            new MockClock()
        );
        return new CommandTester(new InstallerCommand(new LegacyInstallerClient($worker), $presentation ?? new CliPresentation(), $version));
    }

    public function testLegacyHelpAndVersionDoNotStartAnyWorker(): void
    {
        foreach ([LegacyRequest::Help, LegacyRequest::Version] as $request) {
            $worker = $this->createMock(LegacyWorkerRunner::class);
            $worker->expects($this->never())->method('run');
            $presentation = new CliPresentation();
            $presentation->forLegacy($request);
            $command = $this->command($worker, $presentation);
            $this->assertSame(0, $command->execute([]));
            $this->assertStringContainsString('Kadupul Installer', $command->getDisplay());
        }
    }

    public function testUntouchedConfigureFailsBeforeStartingNativeWorker(): void
    {
        $worker = $this->createMock(LegacyWorkerRunner::class);
        $worker->expects($this->never())->method('run');
        $command = $this->command($worker);
        $this->assertSame(2, $command->execute(['--action' => 'configure']));
        $this->assertStringContainsString('Accept the license', $command->getDisplay());
    }

    public function testValidOptionsPreserveDisabledModeAndProfileIdentityThroughStdin(): void
    {
        $worker = $this->createMock(LegacyWorkerRunner::class);
        $worker->expects($this->once())->method('run')->with(
            'legacy-installer.php',
            'KADUPUL_INSTALLER_RESULT',
            $this->callback(static fn(array $request): bool => $request['options']['AutomationMode'] === '0'
                && $request['options']['Profile'] === '01' && $request['action'] === 'configure'),
            null
        )
            ->willReturn(['ok' => true, 'output' => '', 'errors' => '', 'result' => ['data' => $this->plan()]]);
        $command = $this->command($worker);
        $this->assertSame(0, $command->execute(['--action' => 'configure', '--accept-eula' => true,
            '--automationmode' => '0', '--collection-profile' => '01', '--output' => 'json']));
        $this->assertSame($this->plan(), json_decode($command->getDisplay(), true));
    }

    public function testEmptyWorkerReceiptCannotBeReportedAsSuccess(): void
    {
        $worker = $this->createMock(LegacyWorkerRunner::class);
        $worker->method('run')->willReturn(['ok' => true, 'output' => '', 'errors' => '', 'result' => ['data' => []]]);
        $command = $this->command($worker);
        $this->assertSame(1, $command->execute(['--output' => 'json']));
        $this->assertArrayHasKey('error', json_decode($command->getDisplay(), true));
    }

    public function testDiscoveryReceiptWithoutItsRangeFailsBeforeTextRendering(): void
    {
        $plan = $this->plan();
        $plan['discovery'] = true;
        $worker = $this->createMock(LegacyWorkerRunner::class);
        $worker->method('run')->willReturn(['ok' => true, 'output' => '', 'errors' => '', 'result' => ['data' => $plan]]);
        $command = $this->command($worker);
        $this->assertSame(1, $command->execute([]));
        $this->assertStringContainsString('Installer worker did not confirm an outcome.', $command->getDisplay());
    }

    public function testNonemptyButIncompleteReceiptCannotPublishSuccess(): void
    {
        $worker = $this->createMock(LegacyWorkerRunner::class);
        $worker->method('run')->willReturn(['ok' => true, 'output' => '', 'errors' => '', 'result' => ['data' => ['state' => 'succeeded']]]);
        $command = $this->command($worker);
        $this->assertSame(1, $command->execute(['--action' => 'status', '--output' => 'json']));
        $this->assertArrayHasKey('error', json_decode($command->getDisplay(), true));
    }

    public function testWorkerExceptionsRemainUnconfirmedWithoutPrintingSensitiveDiagnostics(): void
    {
        $worker = $this->createMock(LegacyWorkerRunner::class);
        $worker->expects($this->once())->method('run')->willThrowException(new \RuntimeException('private-worker-stdin-canary'));
        $command = $this->command($worker);
        $this->assertSame(1, $command->execute(['--output' => 'json']));
        $this->assertStringNotContainsString('private-worker-stdin-canary', $command->getDisplay());
        $this->assertStringContainsString('Check status before retrying', $command->getDisplay());
    }

    public function testStartDoesNotClaimCompletionForAWaitingReceipt(): void
    {
        $worker = $this->createMock(LegacyWorkerRunner::class);
        $worker->method('run')->willReturn(['ok' => true, 'output' => '', 'errors' => '', 'result' => ['data' => $this->statusReceipt('waiting')]]);
        $this->assertSame(1, $this->command($worker)->execute(['--install' => true, '--accept-eula' => true, '--output' => 'json']));
    }

    public function testStartRequiresACompleteReceiptForTheExpectedSchema(): void
    {
        foreach (['1.2.38' => 0, '1.2.33' => 1] as $schema => $expectedExit) {
            $receipt = $this->statusReceipt('succeeded');
            $receipt['schema'] = $schema;
            $worker = $this->createMock(LegacyWorkerRunner::class);
            $worker->method('run')->willReturn(['ok' => true, 'output' => '', 'errors' => '', 'result' => ['data' => $receipt]]);
            $this->assertSame($expectedExit, $this->command($worker)->execute(['--install' => true, '--accept-eula' => true, '--output' => 'json']));
        }
    }

    public function testPartialCollectorCompletionStillReturnsFailure(): void
    {
        $worker = $this->createMock(LegacyWorkerRunner::class);
        $worker->method('run')->willReturn(['ok' => true, 'output' => '', 'errors' => '', 'result' => ['data' => $this->statusReceipt('partial')]]);
        $command = $this->command($worker);
        $this->assertSame(1, $command->execute(['--install' => true, '--accept-eula' => true, '--output' => 'json']));
        $this->assertSame('partial', json_decode($command->getDisplay(), true)['state']);
    }

    public function testCompletedSchemaDoesNotHideARejectedRuntimeCheck(): void
    {
        $plan = $this->plan();
        $plan['mode'] = 'complete';
        $plan['schema'] = $plan['target'];
        $plan['ready'] = false;
        $plan['checks'][0]['ok'] = false;
        $worker = $this->createMock(LegacyWorkerRunner::class);
        $worker->method('run')->willReturn(['ok' => true, 'output' => '', 'errors' => '', 'result' => ['data' => $plan]]);
        $this->assertSame(1, $this->command($worker)->execute(['--output' => 'json']));
    }

    public function testRecoveryRequiresAnExactAttemptAndExplicitStoppedWorkerAcknowledgement(): void
    {
        foreach ([[], ['--attempt' => '1'], ['--attempt' => '1e2', '--force' => true], ['--attempt' => '0', '--force' => true]] as $arguments) {
            $worker = $this->createMock(LegacyWorkerRunner::class);
            $worker->expects($this->never())->method('run');
            $this->assertSame(2, $this->command($worker)->execute(['--action' => 'recover'] + $arguments));
        }
        $worker = $this->createMock(LegacyWorkerRunner::class);
        $worker->expects($this->once())->method('run')->with(
            'legacy-installer.php',
            'KADUPUL_INSTALLER_RESULT',
            $this->callback(static fn(array $request): bool => $request['action'] === 'recover'
                && $request['attempt'] === 12 && $request['retry'] === true),
            null
        )
            ->willReturn(['ok' => true, 'output' => '', 'errors' => '', 'result' => ['data' => $this->statusReceipt('failed')]]);
        $this->assertSame(0, $this->command($worker)->execute(['--action' => 'recover', '--attempt' => '12', '--force' => true, '--output' => 'json']));
    }

    public function testMalformedIniDoesNotPrintSourceValuesAndRestoresTheErrorHandler(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'installer-ini-');
        $this->assertIsString($file);
        file_put_contents($file, 'SnmpPassword = "private-parser-canary');
        $notices = [];
        set_error_handler(static function (int $level, string $message) use (&$notices): bool {
            $notices[] = $message;
            return true;
        });
        try {
            $worker = $this->createMock(LegacyWorkerRunner::class);
            $worker->expects($this->never())->method('run');
            $command = $this->command($worker);
            $this->assertSame(2, $command->execute(['--action' => 'configure', '--ini' => $file]));
            $this->assertStringNotContainsString('private-parser-canary', $command->getDisplay());
            $this->assertSame([], $notices);
            trigger_error('handler-restored', E_USER_NOTICE);
            $this->assertSame(['handler-restored'], $notices);
        } finally {
            restore_error_handler();
            unlink($file);
        }
    }

    public function testLegacyRepeatedFlagsAndAliasesPreserveTheOriginalArgumentBoundaries(): void
    {
        [$input, $special] = (new InstallerLegacyArguments())->translate(['--path=php_binary:/path with spaces/php',
            '--path=rrdtool:/usr/bin/rrdtool', '--snmp=Password:fixture:delimiter', '-p=01', '-am=0', '-j=/tmp/config with spaces.json']);
        $this->assertNull($special);
        $this->assertSame(['php_binary:/path with spaces/php', 'rrdtool:/usr/bin/rrdtool'], $input['--path']);
        $this->assertSame(['Password:fixture:delimiter'], $input['--snmp']);
        $this->assertSame('01', $input['--collection-profile']);
        $this->assertSame('0', $input['--automationmode']);
        $this->assertSame('/tmp/config with spaces.json', $input['--configuration']);
        $this->assertStringNotContainsString('sensitive-value', implode(' ', (new InstallerLegacyArguments())->invalid('--snmp=sensitive-value')));
    }
}
