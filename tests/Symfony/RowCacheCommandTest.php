<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\IdentityAccess\Application\Command\CleanInvalidatedRowCache;
use Kadupul\IdentityAccess\Application\Port\InvalidatedRowCache;
use Kadupul\IdentityAccess\Application\Query\InspectInvalidatedRowCache;
use Kadupul\IdentityAccess\Domain\RowCacheInvalidation;
use Kadupul\IdentityAccess\Infrastructure\Symfony\RowCacheCommand;
use Kadupul\IdentityAccess\Infrastructure\Symfony\RowCacheState;
use Kadupul\Kernel;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

final class RowCacheCommandTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/kadupul-cache-command-' . bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
    }

    private function command(InvalidatedRowCache $cache): CommandTester
    {
        return new CommandTester(new RowCacheCommand(new InspectInvalidatedRowCache($cache), new CleanInvalidatedRowCache($cache), new RowCacheState($this->directory), null));
    }

    public function testDefaultInspectionNeverDeletesOrCreatesWorkerState(): void
    {
        $cache = $this->createMock(InvalidatedRowCache::class);
        $cutoff = new RowCacheInvalidation('graph', 100);
        $cache->method('invalidations')->willReturn([$cutoff]);
        $cache->expects(self::once())->method('count')->with($cutoff)->willReturn(1001);
        $cache->expects(self::never())->method('remove');
        $tester = $this->command($cache);
        self::assertSame(0, $tester->execute(['--json' => true]));
        self::assertSame(['status' => 'ok', 'mode' => 'inspect', 'scheduler_configured' => false, 'deleted' => 0, 'remaining' => 1001, 'classes' => [['class' => 'graph', 'before' => 100, 'rows' => 1001]]], json_decode($tester->getDisplay(), true));
        self::assertDirectoryDoesNotExist($this->directory);
    }

    public function testExplicitCleanupRunsOneBatchAndReportsRemainingRows(): void
    {
        $cache = $this->createMock(InvalidatedRowCache::class);
        $cutoff = new RowCacheInvalidation('graph', 100);
        $cache->expects(self::exactly(2))->method('invalidations')->willReturn([$cutoff]);
        $cache->expects(self::once())->method('remove')->with($cutoff)->willReturn(1000);
        $cache->expects(self::once())->method('count')->with($cutoff)->willReturn(1);
        $tester = $this->command($cache);
        self::assertSame(0, $tester->execute(['--execute' => true, '--json' => true]));
        $result = json_decode($tester->getDisplay(), true);
        self::assertSame('cleanup', $result['mode']);
        self::assertSame(1000, $result['deleted']);
        self::assertSame(1, $result['remaining']);
        $lock = (new RowCacheState($this->directory))->lock();
        self::assertTrue($lock->acquire());
        $lock->release();
    }

    public function testWorkerOwnershipBlocksManualCleanupBeforeDatabaseAccess(): void
    {
        $lock = (new RowCacheState($this->directory))->lock();
        self::assertTrue($lock->acquire());
        try {
            $cache = $this->createMock(InvalidatedRowCache::class);
            $cache->expects(self::never())->method('invalidations');
            $cache->expects(self::never())->method('remove');
            $tester = $this->command($cache);
            self::assertSame(1, $tester->execute(['--execute' => true, '--json' => true]));
            self::assertSame('busy', json_decode($tester->getDisplay(), true)['status']);
        } finally {
            $lock->release();
        }
    }

    public function testPartialFailureIsSanitizedAndReleasesOwnership(): void
    {
        $cache = $this->createMock(InvalidatedRowCache::class);
        $cache->method('invalidations')->willReturn([new RowCacheInvalidation('graph', 100), new RowCacheInvalidation('device', 100)]);
        $cache->expects(self::exactly(2))->method('remove')->willReturnCallback(static function (RowCacheInvalidation $cutoff): int {
            if ($cutoff->class === 'device') {
                throw new \RuntimeException('private-database-password');
            }

            return 4;
        });
        $tester = $this->command($cache);
        self::assertSame(1, $tester->execute(['--execute' => true, '--json' => true]));
        self::assertStringNotContainsString('private-', $tester->getDisplay());
        $result = json_decode($tester->getDisplay(), true);
        self::assertSame('failed', $result['status']);
        self::assertStringContainsString('Some deletions may have committed', $result['error']);
        $lock = (new RowCacheState($this->directory))->lock();
        self::assertTrue($lock->acquire());
        $lock->release();
    }

    public function testTextInspectionEscapesConsoleMarkup(): void
    {
        $cache = $this->createMock(InvalidatedRowCache::class);
        $cache->method('invalidations')->willReturn([new RowCacheInvalidation('<error>graph</error>', 100)]);
        $cache->method('count')->willReturn(2);
        $tester = $this->command($cache);
        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('<error>graph</error>: 2 stale rows', $tester->getDisplay());
    }

    public function testJsonPreservesConsoleMarkupWithAndWithoutDecoration(): void
    {
        foreach ([false, true] as $decorated) {
            $cache = $this->createMock(InvalidatedRowCache::class);
            $name = '<error>graph</error>';
            $cache->method('invalidations')->willReturn([new RowCacheInvalidation($name, 100)]);
            $cache->method('count')->willReturn(2);
            $tester = $this->command($cache);
            self::assertSame(0, $tester->execute(['--json' => true], ['decorated' => $decorated]));
            self::assertSame($name, json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR)['classes'][0]['class']);
        }
    }

    public function testInspectionFailureHasNoRawDatabaseDetails(): void
    {
        $cache = $this->createMock(InvalidatedRowCache::class);
        $cache->method('invalidations')->willThrowException(new \RuntimeException('private-host-and-user'));
        $tester = $this->command($cache);
        self::assertSame(1, $tester->execute([]));
        self::assertStringContainsString('Cache inspection failed', $tester->getDisplay());
        self::assertStringNotContainsString('private-', $tester->getDisplay());
    }

    public function testCommandIsRegisteredBySymfony(): void
    {
        $kernel = new Kernel('test', true);
        try {
            $kernel->boot();
            $cache = $this->createMock(InvalidatedRowCache::class);
            $cache->method('invalidations')->willReturn([]);
            $cache->expects(self::never())->method('remove');
            $kernel->getContainer()->get('test.service_container')->set(InvalidatedRowCache::class, $cache);
            $application = new Application($kernel);
            $application->setAutoExit(false);
            $tester = new ApplicationTester($application);
            self::assertSame(0, $tester->run(['command' => 'kadupul:maintenance:row-cache', '--json' => true]));
            self::assertSame(0, json_decode($tester->getDisplay(), true)['remaining']);
        } finally {
            $kernel->shutdown();
        }
    }
}
