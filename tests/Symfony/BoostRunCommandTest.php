<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Graphing\Infrastructure\Symfony\BoostRunCommand;
use Kadupul\Graphing\Infrastructure\Symfony\BoostRunHandler;
use Kadupul\Graphing\Infrastructure\Symfony\BoostState;
use Kadupul\Kernel;
use Kadupul\Platform\Contract\LegacyWorkerRunner;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

final class BoostRunCommandTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/kadupul-boost-command-' . bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
    }

    private function command(LegacyWorkerRunner $worker): CommandTester
    {
        return new CommandTester(new BoostRunCommand(new BoostRunHandler($worker, null), new BoostState($this->directory)));
    }

    /** @param array<string, mixed> $result */
    private function worker(array $result, bool $ok, string $output = ''): LegacyWorkerRunner
    {
        $worker = $this->createMock(LegacyWorkerRunner::class);
        $worker->method('run')->willReturnCallback(static function (string $script, string $marker, array $command, ?float $timeout, ?\Closure $progress) use ($result, $ok, $output): array {
            if ($output !== '' && $progress !== null) {
                $progress('out', $output);
            }

            return ['output' => '', 'errors' => '', 'ok' => $ok, 'result' => $result];
        });

        return $worker;
    }

    public function testBusyLockExitsTwoWithoutStartingTheMaster(): void
    {
        $lock = (new BoostState($this->directory))->lock();
        self::assertTrue($lock->acquire());
        try {
            $worker = $this->createMock(LegacyWorkerRunner::class);
            $worker->expects(self::never())->method('run');
            $tester = $this->command($worker);
            self::assertSame(2, $tester->execute(['--json' => true]));
            self::assertSame('busy', json_decode($tester->getDisplay(), true)['status']);
        } finally {
            $lock->release();
        }
    }

    public function testWorkerFailureExitsOneAndReleasesTheLock(): void
    {
        $tester = $this->command($this->worker(['status' => 'failed'], false));
        self::assertSame(1, $tester->execute(['--json' => true]));
        self::assertSame('failed', json_decode($tester->getDisplay(), true)['status']);
        $lock = (new BoostState($this->directory))->lock();
        self::assertTrue($lock->acquire());
        $lock->release();
    }

    public function testJsonOutputShape(): void
    {
        $tester = $this->command($this->worker(['status' => 'ok'], true, "progress line\n"));
        self::assertSame(0, $tester->execute(['--json' => true]));
        // Master progress stays out of machine-readable output.
        self::assertSame('{"status":"ok"}', trim($tester->getDisplay()));
    }

    public function testForceAndDebugReachTheWorker(): void
    {
        $worker = $this->createMock(LegacyWorkerRunner::class);
        $worker->expects(self::once())->method('run')
            ->with('legacy-boost-master.php', 'BOOST_MASTER', ['force' => true, 'debug' => true], null)
            ->willReturn(['output' => '', 'errors' => '', 'ok' => true, 'result' => ['status' => 'ok']]);
        self::assertSame(0, $this->command($worker)->execute(['--force' => true, '--debug' => true]));
    }

    public function testTextModeStreamsMasterOutput(): void
    {
        $tester = $this->command($this->worker(['status' => 'ok'], true, "INFO: Boost has no items\n"));
        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('INFO: Boost has no items', $tester->getDisplay());
    }

    public function testWorkerExceptionIsSanitized(): void
    {
        $worker = $this->createMock(LegacyWorkerRunner::class);
        $worker->method('run')->willThrowException(new \RuntimeException('private-host'));
        $tester = $this->command($worker);
        self::assertSame(1, $tester->execute(['--json' => true]));
        self::assertStringNotContainsString('private-', $tester->getDisplay());
    }

    public function testCommandIsRegisteredBySymfony(): void
    {
        $kernel = new Kernel('test', true);
        try {
            $kernel->boot();
            $worker = $this->createMock(LegacyWorkerRunner::class);
            $worker->expects(self::once())->method('run')->willReturn(['output' => '', 'errors' => '', 'ok' => true, 'result' => ['status' => 'ok']]);
            $kernel->getContainer()->get('test.service_container')->set(LegacyWorkerRunner::class, $worker);
            $application = new Application($kernel);
            $application->setAutoExit(false);
            $tester = new ApplicationTester($application);
            self::assertSame(0, $tester->run(['command' => 'kadupul:boost:run', '--json' => true]));
            self::assertSame('ok', json_decode($tester->getDisplay(), true)['status']);
        } finally {
            $kernel->shutdown();
        }
    }
}
