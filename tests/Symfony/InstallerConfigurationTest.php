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
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

final class InstallerConfigurationTest extends TestCase
{
    private function command(LegacyWorkerRunner $worker): CommandTester
    {
        $version = new InstallationVersion(
            dirname(__DIR__, 2),
            DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]),
            new Filesystem(),
            new MockClock()
        );
        return new CommandTester(new InstallerCommand(new LegacyInstallerClient($worker), new CliPresentation(), $version));
    }

    /** @param callable(string): void $run */
    private function configuration(string $contents, callable $run): void
    {
        $file = tempnam(sys_get_temp_dir(), 'installer-config-');
        self::assertNotFalse($file);
        try {
            self::assertSame(strlen($contents), file_put_contents($file, $contents));
            $run($file);
        } finally {
            unlink($file);
        }
    }

    public function testLocalValidationNeverCallsTheNativeWorkerOrReturnsSecretValues(): void
    {
        $worker = $this->createMock(LegacyWorkerRunner::class);
        $worker->expects($this->never())->method('run');
        $this->configuration('{"Eula":true,"AutomationMode":"0","Profile":"01","SnmpOptions":{"SnmpPassword":"private-validation-canary"}}', function (string $file) use ($worker): void {
            $command = $this->command($worker);
            self::assertSame(0, $command->execute(['--validate' => true, '--configuration' => $file, '--output' => 'json']));
            self::assertSame(['ok' => true, 'valid' => true], json_decode($command->getDisplay(), true, 8, JSON_THROW_ON_ERROR));
            self::assertStringNotContainsString('private-validation-canary', $command->getDisplay());
        });
    }

    public function testEnvironmentReferencesReachOnlyTheSensitiveWorkerInput(): void
    {
        $name = 'KADUPUL_INSTALLER_TEST_REFERENCE_' . bin2hex(random_bytes(8));
        putenv($name . '=private-reference-canary');
        try {
            $worker = $this->createMock(LegacyWorkerRunner::class);
            $worker->expects($this->once())->method('run')->with(
                'legacy-installer.php',
                'KADUPUL_INSTALLER_RESULT',
                self::callback(static fn(array $request): bool => $request['options']['SnmpOptions'] === [
                    'SnmpCommunity' => 'literal-${UNCHANGED}', 'SnmpPassword' => 'private-reference-canary', 'SnmpPrivPassphrase' => '']),
                null
            )
                ->willReturn(['ok' => false, 'output' => '', 'errors' => '', 'result' => ['data' => ['error' => 'Fixture refused persistence.']]]);
            $this->configuration(json_encode(['Eula' => true, 'SnmpOptions' => ['SnmpCommunity' => 'literal-${UNCHANGED}',
                'SnmpPassword' => ['env' => $name], 'SnmpPrivPassphrase' => '']], JSON_THROW_ON_ERROR), function (string $file) use ($worker): void {
                    $command = $this->command($worker);
                    self::assertSame(1, $command->execute(['--action' => 'configure', '--configuration' => $file, '--output' => 'json']));
                    self::assertStringNotContainsString('private-reference-canary', $command->getDisplay());
                });
        } finally {
            putenv($name);
        }
    }

    public function testMissingOrMalformedReferencesFailWithoutExposingNamesOrConfiguration(): void
    {
        $name = 'KADUPUL_INSTALLER_MISSING_' . bin2hex(random_bytes(8));
        $references = [['SnmpPassword' => ['env' => $name]], ['SnmpPassword' => ['env' => 'bad-private-name!']],
            ['SnmpPassword' => ['env' => $name, 'fallback' => 'private-reference-canary']], ['SnmpUsername' => ['env' => $name]]];
        foreach ($references as $reference) {
            $worker = $this->createMock(LegacyWorkerRunner::class);
            $worker->expects($this->never())->method('run');
            $this->configuration(json_encode(['Eula' => true, 'SnmpOptions' => $reference], JSON_THROW_ON_ERROR), function (string $file) use ($worker, $name): void {
                $command = $this->command($worker);
                self::assertSame(2, $command->execute(['--validate' => true, '--configuration' => $file, '--output' => 'json']));
                $error = json_decode($command->getDisplay(), true, 8, JSON_THROW_ON_ERROR);
                self::assertFalse($error['ok']);
                self::assertSame('invalid_configuration', $error['error']['code']);
                self::assertStringNotContainsString($name, $command->getDisplay());
                self::assertStringNotContainsString('private-reference-canary', $command->getDisplay());
                self::assertStringNotContainsString('bad-private-name', $command->getDisplay());
            });
        }
    }

    public function testResolvedSecretsKeepTheExistingEmptyAndMaximumLengthRules(): void
    {
        $name = 'KADUPUL_INSTALLER_BOUNDARY_' . bin2hex(random_bytes(8));
        try {
            foreach (['' => 0, str_repeat('x', 4096) => 0, str_repeat('x', 4097) => 2] as $value => $exit) {
                putenv($name . '=' . $value);
                $worker = $this->createMock(LegacyWorkerRunner::class);
                $worker->expects($this->never())->method('run');
                $this->configuration(json_encode(['Eula' => true, 'SnmpOptions' => ['SnmpPrivPassphrase' => ['env' => $name]]], JSON_THROW_ON_ERROR), function (string $file) use ($worker, $exit): void {
                    $command = $this->command($worker);
                    self::assertSame($exit, $command->execute(['--validate' => true, '--configuration' => $file, '--output' => 'json']));
                    self::assertStringNotContainsString(str_repeat('x', 20), $command->getDisplay());
                });
            }
        } finally {
            putenv($name);
        }
    }

    public function testExplicitFlagsOverrideAnUnavailableJsonSecretReference(): void
    {
        $worker = $this->createMock(LegacyWorkerRunner::class);
        $worker->expects($this->never())->method('run');
        $name = 'KADUPUL_INSTALLER_MISSING_' . bin2hex(random_bytes(8));
        $this->configuration(json_encode(['Eula' => true, 'SnmpOptions' => ['SnmpPassword' => ['env' => $name]]], JSON_THROW_ON_ERROR), function (string $file) use ($worker): void {
            $command = $this->command($worker);
            self::assertSame(0, $command->execute(['--validate' => true, '--configuration' => $file, '--snmp' => ['Password:private-override-canary'], '--output' => 'json']));
            self::assertStringNotContainsString('private-override-canary', $command->getDisplay());
        });
    }

    public function testInvalidJsonAndDomainOptionsUseTheSameValidationEnvelope(): void
    {
        foreach (['{"SnmpOptions":{"SnmpPassword":"private-json-canary"', '{"Eula":true,"Profile":"1e2"}', '{}'] as $contents) {
            $worker = $this->createMock(LegacyWorkerRunner::class);
            $worker->expects($this->never())->method('run');
            $this->configuration($contents, function (string $file) use ($worker): void {
                $command = $this->command($worker);
                self::assertSame(2, $command->execute(['--validate' => true, '--configuration' => $file, '--output' => 'json']));
                $error = json_decode($command->getDisplay(), true, 8, JSON_THROW_ON_ERROR);
                self::assertSame(['ok', 'error'], array_keys($error));
                self::assertFalse($error['ok']);
                self::assertSame(['code', 'message'], array_keys($error['error']));
                self::assertSame('invalid_configuration', $error['error']['code']);
                self::assertStringNotContainsString('private-json-canary', $command->getDisplay());
            });
        }
    }

    public function testJsonListsAreRejectedEvenWhenFlagsSupplyTheRequiredLicense(): void
    {
        foreach (['[]', '[{}]', 'null', '"private-root-canary"'] as $contents) {
            $worker = $this->createMock(LegacyWorkerRunner::class);
            $worker->expects($this->never())->method('run');
            $this->configuration($contents, function (string $file) use ($worker): void {
                $command = $this->command($worker);
                self::assertSame(2, $command->execute(['--validate' => true, '--accept-eula' => true, '--configuration' => $file, '--output' => 'json']));
                $error = json_decode($command->getDisplay(), true, 8, JSON_THROW_ON_ERROR);
                self::assertSame('Configuration must be a JSON object.', $error['error']['message']);
                self::assertStringNotContainsString('private-root-canary', $command->getDisplay());
            });
        }
        $worker = $this->createMock(LegacyWorkerRunner::class);
        $worker->expects($this->never())->method('run');
        $this->configuration('{}', function (string $file) use ($worker): void {
            self::assertSame(0, $this->command($worker)->execute(['--validate' => true, '--accept-eula' => true, '--configuration' => $file]));
        });
    }

    public function testValidationRejectsExecutionStatusAndRecoveryBeforeAnyWorker(): void
    {
        foreach ([['--install' => true], ['--action' => 'start'], ['--action' => 'status'], ['--action' => 'recover']] as $flags) {
            $worker = $this->createMock(LegacyWorkerRunner::class);
            $worker->expects($this->never())->method('run');
            $command = $this->command($worker);
            self::assertSame(2, $command->execute($flags + ['--validate' => true, '--accept-eula' => true, '--output' => 'json']));
            self::assertSame('invalid_arguments', json_decode($command->getDisplay(), true, 8, JSON_THROW_ON_ERROR)['error']['code']);
        }
    }
}
