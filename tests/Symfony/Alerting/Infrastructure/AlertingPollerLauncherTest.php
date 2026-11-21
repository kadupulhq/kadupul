<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests\Alerting\Infrastructure;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * lib/alerting.php's launcher, which poller.php calls after each main
 * poller cycle (ADR 0011 decision 2). Each test runs in a process of its own
 * with recording stand-ins for the legacy helpers the launcher calls, so
 * they never meet the real ones another test loads.
 * tests/Unit/Core/Poller/AlertingPollerBottomTest.php pins where poller.php
 * calls it.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class AlertingPollerLauncherTest extends TestCase
{
    protected function setUp(): void
    {
        $root = dirname(__DIR__, 4);
        require_once $root . '/tests/Fixtures/alerting-poller-launcher-stubs.php';
        require_once $root . '/lib/alerting.php';
    }

    /**
     * @param array<string, string> $settings
     * @return list<array<mixed>> the exec_background() and cacti_log() calls, in order
     */
    private static function launch(int $pollerId, array $settings): array
    {
        $GLOBALS['alerting_launcher_settings'] = $settings;
        $GLOBALS['alerting_launcher_calls'] = [];
        $launched = \alerting_poller_bottom($pollerId, '/srv/kadupul');
        $calls = $GLOBALS['alerting_launcher_calls'];
        self::assertSame(array_filter($calls, static fn(array $call): bool => $call[0] === 'exec') !== [], $launched);

        return $calls;
    }

    public function testARemotePollerNeverLaunchesTheEvaluator(): void
    {
        self::assertSame([], self::launch(2, ['alerting_enable' => 'on', 'path_php_binary' => PHP_BINARY]));
    }

    /** @return iterable<string, array{array<string, string>}> */
    public static function off(): iterable
    {
        yield 'missing' => [[]];
        yield 'empty' => [['alerting_enable' => '']];
        yield 'upper case' => [['alerting_enable' => 'ON']];
    }

    /** @param array<string, string> $settings */
    #[DataProvider('off')]
    public function testTheMainPollerLaunchesNothingWhileAlertingIsOff(array $settings): void
    {
        self::assertSame([], self::launch(1, $settings + ['path_php_binary' => PHP_BINARY]));
    }

    public function testTheMainPollerLaunchesTheConsoleCommandOnceInTheBackground(): void
    {
        self::assertSame([
            ['exec', PHP_BINARY, ['/srv/kadupul/bin/console', 'kadupul:alerting:evaluate', '--no-interaction'], ''],
        ], self::launch(1, ['alerting_enable' => 'on', 'path_php_binary' => PHP_BINARY]));
    }

    /** @return iterable<string, array{string}> */
    public static function unusableBinaries(): iterable
    {
        yield 'relative' => ['php'];
        yield 'empty' => [''];
        yield 'missing file' => ['/nonexistent/kadupul/php'];
        yield 'directory' => [sys_get_temp_dir()];
    }

    #[DataProvider('unusableBinaries')]
    public function testAPhpBinaryThatIsNotAnAbsolutePathToAFileLaunchesNothingAndSaysWhy(string $php): void
    {
        self::assertSame([
            ['log', 'WARNING: Alert evaluation was not started: the PHP Binary Path setting is not an absolute path to a file.', 'ALERTING'],
        ], self::launch(1, ['alerting_enable' => 'on', 'path_php_binary' => $php]));
    }
}
