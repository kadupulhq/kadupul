<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/Helpers/PhpSource.php';

final class InstallerFailedPollTest extends TestCase
{
    private function transition(array $state): array
    {
        $result = test_php_run([PHP_BINARY, dirname(__DIR__) . '/Fixtures/installer-failed-poll.php', json_encode($state, JSON_THROW_ON_ERROR)]);
        self::assertSame(0, $result['status'], $result['err']);
        return json_decode($result['out'], true, flags: JSON_THROW_ON_ERROR);
    }

    public function testStaleBrowserPollPreservesActualFailedBackgroundState(): void
    {
        foreach ([97, '97'] as $step) {
            $state = $this->transition(['version' => '1.2.33', 'install_step' => 99,
                'install_error' => 'contract refused', 'parameters' => ['Step' => $step]]);
            self::assertSame(99, $state['install_step']);
            self::assertSame('contract refused', $state['install_error']);
            self::assertArrayNotHasKey('cleared', $state);
        }
    }

    public function testNormalReloadStartsRetryWizardAndClearsPreviousFailure(): void
    {
        $state = $this->transition(['version' => '1.2.33', 'install_step' => 99, 'install_error' => 'contract refused']);
        self::assertSame(1, $state['install_step']);
        self::assertTrue($state['cleared']);
        self::assertArrayNotHasKey('install_error', $state);
    }

    public function testHealthyPollAndExplicitNewWizardStillProgress(): void
    {
        foreach ([['install_step' => 97, 'parameters' => ['Step' => 97]],
            ['install_step' => 1, 'parameters' => ['Step' => 97]],
            ['install_step' => 99, 'install_error' => 'contract refused', 'parameters' => ['Step' => 1]]] as $initial) {
            $state = $this->transition(['version' => '1.2.33'] + $initial);
            self::assertSame($initial['parameters']['Step'], $state['install_step']);
        }
    }
}
