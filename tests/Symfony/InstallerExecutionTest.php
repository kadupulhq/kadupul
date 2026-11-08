<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Platform\Application\Installer\InstallationExecution;
use Kadupul\Platform\Domain\Installer\InstallationMode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/** Exercise the installation phase contract through both application and native worker entry points. */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class InstallerExecutionTest extends TestCase
{
    /** @return iterable<string, array{InstallationMode, bool, list<string>}> */
    public static function modes(): iterable
    {
        yield 'fresh-primary' => [InstallationMode::Install, true, ['installSelectedTemplates', 'installPrimaryServer', 'disableIncompatiblePlugins']];
        yield 'fresh-remote' => [InstallationMode::Install, false, ['installSelectedTemplates', 'installPrimaryServer', 'disableIncompatiblePlugins']];
        yield 'collector' => [InstallationMode::Collector, false, ['installRemoteCollector']];
        yield 'collector-primary-id' => [InstallationMode::Collector, true, ['installRemoteCollector']];
        yield 'upgrade' => [InstallationMode::Upgrade, true, ['upgradeInstallationDatabase', 'installSelectedTemplates', 'disableIncompatiblePlugins']];
        yield 'downgrade' => [InstallationMode::Downgrade, true, ['disableIncompatiblePlugins']];
        yield 'none' => [InstallationMode::None, true, ['disableIncompatiblePlugins']];
    }

    /** The same phase order reaches native progress/outcome adapters for each persisted mode.
     * @param list<string> $modeCalls
     */
    #[DataProvider('modes')]
    public function testApplicationAndNativeEntryPreservePhaseOrder(InstallationMode $mode, bool $primary, array $modeCalls): void
    {
        $expected = ['checkInstallationStorage', 'refreshInstallationCache', 'beginInstallation', 'initializeInstallationSecret', 'convertInstallationTables', ...$modeCalls, 'migrateInstallationDefaults'];
        if ($primary && $mode !== InstallationMode::Collector) {
            $expected[] = 'isPrimaryInstallation';
            $expected[] = 'installPrimaryReferenceContract';
        }
        if (!$primary && $mode !== InstallationMode::Collector) {
            $expected[] = 'isPrimaryInstallation';
        }
        $expected = [...$expected, 'confirmInstallationVersion', 'finishInstallation'];
        if ($mode !== InstallationMode::Collector) {
            $expected[] = 'synchronizeInstallationCollectors';
        }
        $expected[] = 'completeInstallation';
        foreach ([false, true] as $native) {
            $calls = [];
            $actions = $this->actions($calls);
            $this->executeSequence($actions, $mode, $primary, $native);
            $this->assertSame($expected, array_column($calls, 0));
            $this->assertSame([$mode->processName()], $calls[2][1]);
            $this->assertSame([$mode->processName(), ''], $calls[array_search('finishInstallation', $expected, true)][1]);
            $this->assertSame([''], $calls[array_key_last($calls)][1]);
        }
    }

    /** @return iterable<string, array{InstallationMode, string, list<string>}> */
    public static function returnedFailures(): iterable
    {
        yield 'template' => [InstallationMode::Install, 'installSelectedTemplates', ['installPrimaryServer', 'migrateInstallationDefaults', 'installPrimaryReferenceContract', 'confirmInstallationVersion', 'synchronizeInstallationCollectors']];
        yield 'server' => [InstallationMode::Install, 'installPrimaryServer', ['migrateInstallationDefaults', 'installPrimaryReferenceContract', 'confirmInstallationVersion', 'synchronizeInstallationCollectors']];
        yield 'upgrade' => [InstallationMode::Upgrade, 'upgradeInstallationDatabase', ['installSelectedTemplates', 'migrateInstallationDefaults', 'installPrimaryReferenceContract', 'confirmInstallationVersion', 'synchronizeInstallationCollectors']];
        yield 'collector' => [InstallationMode::Collector, 'installRemoteCollector', ['disableIncompatiblePlugins', 'migrateInstallationDefaults', 'installPrimaryReferenceContract', 'confirmInstallationVersion', 'synchronizeInstallationCollectors']];
        yield 'defaults' => [InstallationMode::Install, 'migrateInstallationDefaults', ['installPrimaryReferenceContract', 'confirmInstallationVersion', 'synchronizeInstallationCollectors']];
        yield 'reference' => [InstallationMode::Install, 'installPrimaryReferenceContract', ['confirmInstallationVersion', 'synchronizeInstallationCollectors']];
        yield 'version' => [InstallationMode::Install, 'confirmInstallationVersion', ['synchronizeInstallationCollectors']];
    }

    /** Returned failures retain diagnostics and never publish a successful completion.
     * @param list<string> $forbidden
     */
    #[DataProvider('returnedFailures')]
    public function testReturnedFailureStopsLaterMutationPhases(InstallationMode $mode, string $phase, array $forbidden): void
    {
        foreach ([false, true] as $native) {
            $calls = [];
            $actions = $this->actions($calls, [$phase => 'fixture failure']);
            $this->executeSequence($actions, $mode, true, $native);
            $names = array_column($calls, 0);
            $this->assertContains($phase, $names);
            foreach ($forbidden as $name) {
                $this->assertNotContains($name, $names);
            }
            $this->assertSame(['finishInstallation', [$mode->processName(), 'fixture failure']], $calls[count($calls) - 2]);
            $this->assertSame(['completeInstallation', ['fixture failure']], $calls[count($calls) - 1]);
            if ($mode !== InstallationMode::Collector) {
                $this->assertContains('disableIncompatiblePlugins', $names);
            }
        }
    }

    /** @return iterable<string, array{string}> */
    public static function thrownFailures(): iterable
    {
        foreach (['checkInstallationStorage', 'refreshInstallationCache', 'beginInstallation', 'initializeInstallationSecret', 'convertInstallationTables', 'installSelectedTemplates', 'installPrimaryServer', 'disableIncompatiblePlugins', 'migrateInstallationDefaults', 'installPrimaryReferenceContract', 'confirmInstallationVersion', 'finishInstallation', 'synchronizeInstallationCollectors', 'completeInstallation'] as $phase) {
            yield $phase => [$phase];
        }
    }

    /** An exception at any phase propagates unchanged and prevents all later callbacks. */
    #[DataProvider('thrownFailures')]
    public function testThrownFailureRetainsTheNativeStopBoundary(string $phase): void
    {
        foreach ([false, true] as $native) {
            $calls = [];
            $failure = new \RuntimeException('fixture interrupted');
            $actions = $this->actions($calls, [], $phase, $failure);
            try {
                $this->executeSequence($actions, InstallationMode::Install, true, $native);
                $this->fail('Interrupted installation returned normally.');
            } catch (\RuntimeException $caught) {
                $this->assertSame($failure, $caught);
            }
            $this->assertSame($phase, $calls[array_key_last($calls)][0]);
            if ($phase !== 'completeInstallation') {
                $this->assertNotContains('completeInstallation', array_column($calls, 0));
            }
        }
    }

    /** Collector identity is observed after defaults, preserving the native mutation boundary. */
    public function testCollectorIdentityIsReadAfterPrecedingOperations(): void
    {
        foreach ([false, true] as $native) {
            $calls = [];
            $actions = null;
            $afterPhase = static function (string $phase) use (&$actions): void {
                if ($phase === 'migrateInstallationDefaults') {
                    $actions->primary = false;
                }
            };
            $actions = $this->actions($calls, afterPhase: $afterPhase);
            $this->executeSequence($actions, InstallationMode::Upgrade, true, $native);
            $this->assertContains('isPrimaryInstallation', array_column($calls, 0));
            $this->assertNotContains('installPrimaryReferenceContract', array_column($calls, 0));
            $this->assertSame(['completeInstallation', ['']], $calls[array_key_last($calls)]);
        }
    }

    /** The compatibility adapter reads current configuration rather than retaining an earlier role. */
    public function testNativeCollectorIdentityRemainsLive(): void
    {
        require_once dirname(__DIR__, 2) . '/lib/installer.php';
        $instance = (new \ReflectionClass(\Installer::class))->newInstanceWithoutConstructor();
        $present = array_key_exists('config', $GLOBALS);
        $previous = $GLOBALS['config'] ?? null;
        try {
            foreach ([0 => true, 1 => true, 2 => false, 7 => false] as $id => $expected) {
                $GLOBALS['config'] = ['poller_id' => $id];
                $this->assertSame($expected, $instance->isPrimaryInstallation());
            }
        } finally {
            if ($present) {
                $GLOBALS['config'] = $previous;
            } else {
                unset($GLOBALS['config']);
            }
        }
    }

    /** Native persisted constants and PHP enum values cannot drift independently. */
    public function testModeNumbersMatchTheExistingNativeContract(): void
    {
        $calls = [];
        $this->actions($calls);
        $this->assertSame([\Installer::MODE_NONE, \Installer::MODE_INSTALL, \Installer::MODE_POLLER, \Installer::MODE_UPGRADE, \Installer::MODE_DOWNGRADE], array_column(InstallationMode::cases(), 'value'));
    }

    /** @param list<array{string, list<mixed>}> $calls
     * @param array<string, string> $failures
     * @param (\Closure(string): void)|null $afterPhase
     * @return \Installer&object{primary: bool}
     */
    private function actions(array &$calls, array $failures = [], string $throwAt = '', ?\Throwable $error = null, ?\Closure $afterPhase = null): \Installer
    {
        require_once dirname(__DIR__, 2) . '/lib/installer.php';
        $record = static function (string $name, array $arguments = []) use (&$calls, $failures, $throwAt, $error, $afterPhase): string {
            $calls[] = [$name, $arguments];
            if ($name === $throwAt && $error !== null) {
                throw $error;
            }
            if ($afterPhase !== null) {
                $afterPhase($name);
            }
            return $failures[$name] ?? '';
        };
        return new class ($record) extends \Installer {
            /** Current native collector identity, read only when the sequence needs it. */
            public bool $primary = true;

            /** @param \Closure(string, list<mixed>): string $record */
            public function __construct(private readonly \Closure $record) {}
            /** Observe collector identity at the reference-contract boundary. */
            public function isPrimaryInstallation(): bool
            {
                ($this->record)(__FUNCTION__, []);
                return $this->primary;
            }

            /** Record this external native capability without database or process I/O. */
            public function checkInstallationStorage(): void
            {
                ($this->record)(__FUNCTION__, []);
            }
            /** Record this external native capability without database or process I/O. */
            public function refreshInstallationCache(): void
            {
                ($this->record)(__FUNCTION__, []);
            }
            /** Record this external native capability without database or process I/O. */
            public function beginInstallation(string $process): void
            {
                ($this->record)(__FUNCTION__, [$process]);
            }
            /** Record this external native capability without database or process I/O. */
            public function initializeInstallationSecret(): void
            {
                ($this->record)(__FUNCTION__, []);
            }
            /** Record this external native capability without database or process I/O. */
            public function convertInstallationTables(): void
            {
                ($this->record)(__FUNCTION__, []);
            }
            /** Record this external native capability without database or process I/O. */
            public function installRemoteCollector(): string
            {
                return ($this->record)(__FUNCTION__, []);
            }
            /** Record this external native capability without database or process I/O. */
            public function installSelectedTemplates(): string
            {
                return ($this->record)(__FUNCTION__, []);
            }
            /** Record this external native capability without database or process I/O. */
            public function installPrimaryServer(): string
            {
                return ($this->record)(__FUNCTION__, []);
            }
            /** Record this external native capability without database or process I/O. */
            public function upgradeInstallationDatabase(): string
            {
                return ($this->record)(__FUNCTION__, []);
            }
            /** Record this external native capability without database or process I/O. */
            public function disableIncompatiblePlugins(): void
            {
                ($this->record)(__FUNCTION__, []);
            }
            /** Record this external native capability without database or process I/O. */
            public function migrateInstallationDefaults(): string
            {
                return ($this->record)(__FUNCTION__, []);
            }
            /** Record this external native capability without database or process I/O. */
            public function installPrimaryReferenceContract(): string
            {
                return ($this->record)(__FUNCTION__, []);
            }
            /** Record this external native capability without database or process I/O. */
            public function confirmInstallationVersion(): string
            {
                return ($this->record)(__FUNCTION__, []);
            }
            /** Record this external native capability without database or process I/O. */
            public function finishInstallation(string $process, string $failure): void
            {
                ($this->record)(__FUNCTION__, [$process, $failure]);
            }
            /** Record this external native capability without database or process I/O. */
            public function synchronizeInstallationCollectors(): void
            {
                ($this->record)(__FUNCTION__, []);
            }
            /** Record this external native capability without database or process I/O. */
            public function completeInstallation(string $failure): void
            {
                ($this->record)(__FUNCTION__, [$failure]);
            }
        };
    }

    /** Exercise the real private native handoff without running its constructor or external capabilities. */
    private function executeSequence(\Installer $actions, InstallationMode $mode, bool $primary, bool $native): void
    {
        $actions->primary = $primary;
        if (!$native) {
            (new InstallationExecution())->run($actions, $mode);
            return;
        }
        (new \ReflectionProperty(\Installer::class, 'mode'))->setValue($actions, $mode->value);
        (new \ReflectionMethod(\Installer::class, 'install'))->invoke($actions);
    }
}
