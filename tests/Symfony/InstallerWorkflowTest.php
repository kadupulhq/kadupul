<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Kadupul\Tests;

use Kadupul\Platform\Application\Installer\InstallerBackend;
use Kadupul\Platform\Application\Installer\InstallerWorkflow;
use Kadupul\Platform\Domain\Installer\InstallerOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class InstallerWorkflowTest extends TestCase
{
    public static function invalidOptions(): iterable
    {
        yield 'untouched' => [[]];
        yield 'unchecked license' => [['Eula' => false]];
        yield 'nested license' => [['Eula' => [true]]];
        yield 'array profile' => [['Eula' => true, 'Profile' => []]];
        yield 'step injection' => [['Eula' => true, 'Step' => 97]];
        yield 'runtime injection' => [['Eula' => true, 'Runtime' => 'Cli']];
        yield 'nested map' => [['Eula' => true, 'Paths' => ['path_php_binary' => ['bad']]]];
        yield 'oversized value' => [['Eula' => true, 'AutomationRange' => str_repeat('x', 4097)]];
        yield 'null byte' => [['Eula' => true, 'Paths' => ['path_php_binary' => "bad\0path"]]];
        yield 'oversized batch' => [['Eula' => true, 'Templates' => array_fill(0, 501, true)]];
    }

    #[DataProvider('invalidOptions')]
    public function testInvalidFormsFailBeforeNativeSettingsOrWorkerCalls(array $options): void
    {
        $backend = $this->createMock(InstallerBackend::class);
        $backend->expects($this->once())->method('authorize');
        $backend->expects($this->never())->method('configure');
        $backend->expects($this->never())->method('start');
        $this->expectException(\InvalidArgumentException::class);
        (new InstallerWorkflow($backend))->configure($options);
    }

    public function testAcceptedFalseAndOmittedValuesAreNotCoerced(): void
    {
        foreach ([false, 0, '', '0', 'false', null] as $value) {
            $options = InstallerOptions::fromArray(['Eula' => true, 'AutomationMode' => $value]);
            $this->assertSame($value, $options->values['AutomationMode']);
            $this->assertArrayNotHasKey('AutomationOverride', $options->values);
        }
    }

    public function testBlankBrowserSecretsRetainValuesWhileExplicitCliClearsRemainWritable(): void
    {
        foreach ([false, true] as $retainBlankSecrets) {
            $backend = $this->createMock(InstallerBackend::class);
            $backend->expects($this->once())->method('authorize');
            $backend->expects($this->once())->method('configure')->with($this->callback(
                static fn(InstallerOptions $options): bool => array_key_exists('SnmpPassword', $options->values['SnmpOptions']) === !$retainBlankSecrets
                    && $options->values['SnmpOptions']['SnmpUsername'] === ''
            ))->willReturn([]);
            (new InstallerWorkflow($backend))->configure(['Eula' => true,
                'SnmpOptions' => ['SnmpPassword' => '', 'SnmpUsername' => '']], $retainBlankSecrets);
        }
    }

    public static function invalidStarts(): iterable
    {
        yield 'no confirmation' => [str_repeat('a', 64), false, false, true, false];
        yield 'invalid revision' => ['1', true, false, true, false];
        yield 'discovery not confirmed' => [str_repeat('a', 64), true, true, true, false];
        yield 'preflight blocked' => [str_repeat('a', 64), true, false, false, false];
    }

    #[DataProvider('invalidStarts')]
    public function testInvalidStartsNeverReserveOrLaunchWork(string $revision, bool $confirmed, bool $discovery, bool $ready, bool $discoveryConfirmed): void
    {
        $backend = $this->createMock(InstallerBackend::class);
        $backend->expects($this->once())->method('authorize');
        $backend->method('inspect')->willReturn(['ready' => $ready, 'discovery' => $discovery]);
        $backend->expects($this->never())->method('start');
        $this->expectException(\Exception::class);
        (new InstallerWorkflow($backend))->start($revision, $confirmed, $discoveryConfirmed);
    }

    public function testConfirmedStartPassesTheReviewedRevisionAndExplicitRetry(): void
    {
        $backend = $this->createMock(InstallerBackend::class);
        $backend->expects($this->once())->method('authorize');
        $backend->method('inspect')->willReturn(['ready' => true, 'discovery' => true]);
        $backend->expects($this->once())->method('start')->with(str_repeat('a', 64), true, true);
        (new InstallerWorkflow($backend))->start(str_repeat('a', 64), true, true, true);
    }

    public function testStatusCannotConfigureInspectOrStartInstallation(): void
    {
        $backend = $this->createMock(InstallerBackend::class);
        $backend->expects($this->once())->method('authorize');
        $backend->expects($this->never())->method('inspect');
        $backend->expects($this->never())->method('configure');
        $backend->expects($this->never())->method('start');
        $backend->expects($this->once())->method('status')->willReturn(['state' => 'failed', 'progress' => 41]);
        $this->assertSame(['state' => 'failed', 'progress' => 41], (new InstallerWorkflow($backend))->status());
    }

    public function testForcedCompletedRunReturnsTheVerifiedReceiptWithoutMutations(): void
    {
        $backend = $this->createMock(InstallerBackend::class);
        $backend->expects($this->once())->method('authorize');
        $backend->expects($this->once())->method('inspect')->willReturn(['mode' => 'complete']);
        $backend->expects($this->once())->method('status')->willReturn(['state' => 'succeeded']);
        $backend->expects($this->never())->method('configure');
        $backend->expects($this->never())->method('start');
        $this->assertSame(['state' => 'succeeded'], (new InstallerWorkflow($backend))->run(['Eula' => true], true, false));
    }

    public function testCompletedRunWithoutExplicitRetryKeepsTheNativeRefusal(): void
    {
        $backend = $this->createMock(InstallerBackend::class);
        $backend->expects($this->never())->method('inspect');
        $backend->expects($this->never())->method('status');
        $backend->expects($this->once())->method('configure')->willThrowException(new \RuntimeException('already complete'));
        $backend->expects($this->never())->method('start');
        $this->expectException(\RuntimeException::class);
        (new InstallerWorkflow($backend))->run(['Eula' => true], false, false);
    }

    #[DataProvider('invalidOptions')]
    public function testCompletedRunStillValidatesSuppliedOptionsBeforeInspecting(array $options): void
    {
        if ($options === []) {
            $options = ['Step' => 97];
        }
        $backend = $this->createMock(InstallerBackend::class);
        $backend->expects($this->once())->method('authorize');
        $backend->expects($this->never())->method('inspect');
        $backend->expects($this->never())->method('status');
        $backend->expects($this->never())->method('configure');
        $backend->expects($this->never())->method('start');
        $this->expectException(\InvalidArgumentException::class);
        (new InstallerWorkflow($backend))->run($options, true, false);
    }

    public static function unfinishedReceipts(): iterable
    {
        foreach (['failed', 'partial', 'running', 'not_started'] as $state) {
            yield $state => [$state];
        }
    }

    #[DataProvider('unfinishedReceipts')]
    public function testCurrentSchemaWithoutSucceededReceiptCannotInventSuccess(string $state): void
    {
        $backend = $this->createMock(InstallerBackend::class);
        $backend->method('inspect')->willReturn(['mode' => 'complete']);
        $backend->method('status')->willReturn(['state' => $state]);
        $backend->expects($this->once())->method('configure')->willThrowException(new \RuntimeException('already complete'));
        $backend->expects($this->never())->method('start');
        $this->expectException(\RuntimeException::class);
        (new InstallerWorkflow($backend))->run(['Eula' => true], true, false);
    }

    public function testFinishingRetryRetainsItsPlanAndReturnsItsActualOutcome(): void
    {
        $backend = $this->createMock(InstallerBackend::class);
        $backend->method('inspect')->willReturn(['mode' => 'finish', 'revision' => str_repeat('a', 64), 'ready' => true]);
        $backend->expects($this->never())->method('configure');
        $backend->expects($this->once())->method('start')->with(str_repeat('a', 64), true, false);
        $backend->expects($this->once())->method('status')->willReturn(['state' => 'partial']);
        $this->assertSame(['state' => 'partial'], (new InstallerWorkflow($backend))->run(['Eula' => true], true, false));
    }

    public function testRecoveryCannotProceedWithoutStoppedWorkerConfirmation(): void
    {
        $backend = $this->createMock(InstallerBackend::class);
        $backend->expects($this->once())->method('authorize');
        $backend->expects($this->never())->method('recover');
        $backend->expects($this->never())->method('start');
        $this->expectException(\InvalidArgumentException::class);
        (new InstallerWorkflow($backend))->recover(1, false);
    }

    public function testConfirmedRecoveryOnlyRecoversTheSpecifiedAttempt(): void
    {
        $backend = $this->createMock(InstallerBackend::class);
        $backend->expects($this->once())->method('authorize');
        $backend->expects($this->once())->method('recover')->with(12);
        $backend->expects($this->never())->method('start');
        $backend->expects($this->never())->method('configure');
        (new InstallerWorkflow($backend))->recover(12, true);
    }

    public function testDenialPrecedesProtectedReadsAndInputProcessing(): void
    {
        foreach (['inspect', 'configure', 'start', 'status'] as $operation) {
            $backend = $this->createMock(InstallerBackend::class);
            $backend->method('authorize')->willThrowException(new \RuntimeException('denied'));
            $backend->expects($this->never())->method('inspect');
            $backend->expects($this->never())->method('configure');
            $backend->expects($this->never())->method('start');
            $backend->expects($this->never())->method('status');
            $workflow = new InstallerWorkflow($backend);
            try {
                match ($operation) {
                    'configure' => $workflow->configure([]),
                    'start' => $workflow->start('', false, false),
                    default => $workflow->$operation(),
                };
                $this->fail('Denied operation was admitted.');
            } catch (\RuntimeException $error) {
                $this->assertSame('denied', $error->getMessage());
            }
        }
    }
}
