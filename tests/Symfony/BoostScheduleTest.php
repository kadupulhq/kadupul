<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Graphing\Infrastructure\Symfony\BoostRun;
use Kadupul\Graphing\Infrastructure\Symfony\BoostRunHandler;
use Kadupul\Graphing\Infrastructure\Symfony\BoostSchedule;
use Kadupul\Graphing\Infrastructure\Symfony\BoostState;
use Kadupul\Kernel;
use Kadupul\Platform\Contract\LegacyConfiguration;
use Kadupul\Platform\Contract\LegacyWorkerRunner;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Scheduler\Generator\MessageGenerator;

final class BoostScheduleTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/kadupul-boost-schedule-' . bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
    }

    private function provider(?string $enabled = '1'): BoostSchedule
    {
        $config = $this->createMock(LegacyConfiguration::class);
        $config->expects($enabled === '1' ? self::once() : self::never())->method('values')->willReturn([]);

        return new BoostSchedule($config, new BoostState($this->directory), $enabled);
    }

    public function testDisabledScheduleDoesNotReadInstallationOrCreateState(): void
    {
        foreach ([null, '', '0', 'true'] as $disabled) {
            self::assertSame([], $this->provider($disabled)->getSchedule()->getRecurringMessages());
        }
        self::assertDirectoryDoesNotExist($this->directory);
    }

    public function testRemoteInstallationCannotSchedule(): void
    {
        $config = $this->createMock(LegacyConfiguration::class);
        $config->method('values')->willThrowException(new \RuntimeException('Primary installation required.'));
        $this->expectException(\RuntimeException::class);
        (new BoostSchedule($config, new BoostState($this->directory), '1'))->getSchedule();
    }

    public function testLockExcludesSecondWorker(): void
    {
        $clock = new MockClock('2026-10-27 00:00:00 UTC');
        $first = $this->provider();
        $generator = new MessageGenerator($first, 'boost', $clock);
        self::assertSame([], iterator_to_array($generator->getMessages(), false));
        $clock->sleep(61);
        $messages = iterator_to_array($generator->getMessages(), false);
        self::assertCount(1, $messages);
        self::assertInstanceOf(BoostRun::class, $messages[0]);

        $second = new MessageGenerator($this->provider(), 'boost', $clock);
        $clock->sleep(3601);
        self::assertSame([], iterator_to_array($second->getMessages(), false));
        $first->getSchedule()->getLock()->release();
    }

    public function testMissedRunsCollapseToOne(): void
    {
        $clock = new MockClock('2026-10-27 00:00:00 UTC');
        $provider = $this->provider();
        $generator = new MessageGenerator($provider, 'boost', $clock);
        iterator_to_array($generator->getMessages(), false);
        $clock->sleep(3601);
        self::assertCount(1, iterator_to_array($generator->getMessages(), false));
        $provider->getSchedule()->getLock()->release();
    }

    public function testDisabledHandlerDoesNotStartTheMaster(): void
    {
        $worker = $this->createMock(LegacyWorkerRunner::class);
        $worker->expects(self::never())->method('run');
        $this->expectException(\RuntimeException::class);
        (new BoostRunHandler($worker, null))(new BoostRun());
    }

    public function testFailedMasterIsNotRetried(): void
    {
        $worker = $this->createMock(LegacyWorkerRunner::class);
        $worker->expects(self::once())->method('run')
            ->with('legacy-boost-master.php', 'BOOST_MASTER', ['force' => false, 'debug' => false], null)
            ->willReturn(['output' => '', 'errors' => '', 'ok' => false, 'result' => ['status' => 'failed']]);
        $this->expectException(UnrecoverableMessageHandlingException::class);
        (new BoostRunHandler($worker, '1'))(new BoostRun());
    }

    public function testFrameworkRegistersSchedulerTransport(): void
    {
        $kernel = new Kernel('test', true);
        try {
            $kernel->boot();
            self::assertInstanceOf(\Symfony\Component\Scheduler\Messenger\SchedulerTransport::class, $kernel->getContainer()->get('test.service_container')->get('messenger.transport.scheduler_boost'));
        } finally {
            $kernel->shutdown();
        }
    }
}
