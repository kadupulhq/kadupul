<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Doctrine\DBAL\DriverManager;
use Kadupul\Kernel;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Platform\Infrastructure\Profiling\RequestProfileLog;
use Kadupul\Platform\Infrastructure\Symfony\ServerTiming;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bridge\Doctrine\Middleware\Debug\Middleware;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

final class ServerTimingTest extends TestCase
{
    private string $logPath = '';

    protected function setUp(): void
    {
        $this->logPath = sys_get_temp_dir() . '/kadupul-profile-' . bin2hex(random_bytes(6)) . '/requests.jsonl';
        putenv('KADUPUL_PROFILE_LOG=' . $this->logPath);
    }

    protected function tearDown(): void
    {
        putenv('KADUPUL_PROFILE_LOG');
        if (is_file($this->logPath)) {
            unlink($this->logPath);
        }
        if (is_dir(dirname($this->logPath))) {
            rmdir(dirname($this->logPath));
        }
    }

    public function testAddsServerTimingInTestEnv(): void
    {
        $kernel = new Kernel('test', true);
        try {
            $response = $kernel->handle(Request::create('/healthz'));

            self::assertMatchesRegularExpression(
                '/\Aapp;dur=\d+(\.\d+)?, db;desc="\d+ queries", mem;desc="\d+"\z/',
                (string) $response->headers->get('Server-Timing'),
            );
        } finally {
            $kernel->shutdown();
        }
    }

    public function testProfilerCollectsWorkflowsWithAConsoleOnlyRenderingFixture(): void
    {
        $kernel = new Kernel('test', true);
        try {
            $kernel->boot();
            $container = $kernel->getContainer()->get('test.service_container');
            $container->set(ConsoleAccess::class, $this->createStub(ConsoleAccess::class));

            $request = Request::create('/healthz');
            $response = $kernel->handle($request);
            $kernel->terminate($request, $response);

            self::assertSame(200, $response->getStatusCode());
            self::assertTrue($response->headers->has('Server-Timing'));
        } finally {
            $kernel->shutdown();
        }
    }

    public function testReportsTheDoctrineQueryCount(): void
    {
        $kernel = new Kernel('test', true);
        try {
            $kernel->boot();
            $container = $kernel->getContainer();
            $holder = $container->get('test.service_container')->get('doctrine.debug_data_holder');
            self::assertInstanceOf(DebugDataHolder::class, $holder);

            $connection = DriverManager::getConnection(
                ['driver' => 'pdo_sqlite', 'memory' => true],
                new \Doctrine\DBAL\Configuration()->setMiddlewares([new Middleware($holder, null, 'local')]),
            );
            $container->get('event_dispatcher')->addListener(
                KernelEvents::REQUEST,
                static function (RequestEvent $event) use ($connection): void {
                    $connection->executeQuery('SELECT 1');
                    $connection->executeQuery('SELECT 2');
                },
            );

            $response = $kernel->handle(Request::create('/healthz'));

            self::assertStringContainsString('db;desc="2 queries"', (string) $response->headers->get('Server-Timing'));
        } finally {
            $kernel->shutdown();
        }
    }

    public function testProdContainerHasNoProfilerOrServerTimingService(): void
    {
        $kernel = new Kernel('prod', false);
        try {
            $kernel->boot();
            $container = $kernel->getContainer();

            self::assertFalse($container->has(ServerTiming::class));
            self::assertFalse($container->has('profiler'));
            self::assertFalse($container->has('web_profiler.controller.profiler'));
            self::assertArrayNotHasKey('WebProfilerBundle', $kernel->getBundles());
            $response = $kernel->handle(Request::create('/healthz'));
            self::assertFalse($response->headers->has('Server-Timing'));
        } finally {
            $kernel->shutdown();
        }
    }

    public function testWritesOneJsonLinePerRequestCarryingTheProfileId(): void
    {
        $kernel = new Kernel('test', true);
        try {
            foreach (['run-1', 'run-2'] as $id) {
                $request = Request::create('/healthz');
                $request->headers->set('X-Kadupul-Profile-Id', $id);
                $response = $kernel->handle($request);
                $kernel->terminate($request, $response);
            }
        } finally {
            $kernel->shutdown();
        }

        $lines = file($this->logPath, FILE_IGNORE_NEW_LINES);
        self::assertIsArray($lines);
        self::assertCount(2, $lines);
        $record = json_decode($lines[0], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['id', 'path', 'ms', 'queries', 'peak_bytes'], array_keys($record));
        self::assertSame('run-1', $record['id']);
        self::assertSame('/healthz', $record['path']);
        self::assertIsInt($record['queries']);
        self::assertGreaterThan(0, $record['peak_bytes']);
        self::assertSame('run-2', json_decode($lines[1], true, flags: JSON_THROW_ON_ERROR)['id']);
    }

    public function testRecordsNullQueriesWhenTheCounterIsAbsent(): void
    {
        $listener = new ServerTiming(new RequestProfileLog($this->logPath), true, null);
        $request = Request::create('/healthz');
        $request->headers->set('X-Kadupul-Profile-Id', 'no-holder');
        $response = new \Symfony\Component\HttpFoundation\Response();
        $kernel = $this->createStub(HttpKernelInterface::class);

        $listener->onResponse(new \Symfony\Component\HttpKernel\Event\ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response));
        $listener->onTerminate(new TerminateEvent($kernel, $request, $response));

        self::assertStringNotContainsString('db;', (string) $response->headers->get('Server-Timing'));
        $record = json_decode((string) file_get_contents($this->logPath), true, flags: JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('queries', $record);
        self::assertNull($record['queries']);
    }

    public function testReportsNoQueryCountForAnUnusableHolderValue(): void
    {
        $holder = new class extends DebugDataHolder {
            public function getData(): array
            {
                return ['unavailable' => null];
            }
        };
        $listener = new ServerTiming(new RequestProfileLog($this->logPath), true, $holder);
        $request = Request::create('/healthz');
        $request->headers->set('X-Kadupul-Profile-Id', 'unusable-holder');
        $response = new \Symfony\Component\HttpFoundation\Response();
        $kernel = $this->createStub(HttpKernelInterface::class);

        $listener->onResponse(new \Symfony\Component\HttpKernel\Event\ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response));
        $listener->onTerminate(new TerminateEvent($kernel, $request, $response));

        self::assertStringNotContainsString('db;', (string) $response->headers->get('Server-Timing'));
        $record = json_decode((string) file_get_contents($this->logPath), true, flags: JSON_THROW_ON_ERROR);
        self::assertNull($record['queries']);
    }

    public function testWritesNothingWithoutTheIdHeader(): void
    {
        $kernel = new Kernel('test', true);
        try {
            $request = Request::create('/healthz');
            $kernel->terminate($request, $kernel->handle($request));
        } finally {
            $kernel->shutdown();
        }

        self::assertFileDoesNotExist($this->logPath);
    }

    public function testRejectsAMalformedId(): void
    {
        $log = new RequestProfileLog($this->logPath);
        foreach (['', 'a b', "x\n{\"id\":\"forged\"}", str_repeat('a', 65), "ok\n"] as $id) {
            $log->append($id, '/healthz', 1.5, 0, 1024);
        }
        self::assertFileDoesNotExist($this->logPath);

        $log->append(str_repeat('a', 64), '/healthz', 1.5, 3, 1024);
        $lines = file($this->logPath, FILE_IGNORE_NEW_LINES);
        self::assertCount(1, $lines);
        self::assertSame(3, json_decode($lines[0], true, flags: JSON_THROW_ON_ERROR)['queries']);
    }

    public function testDebugOffInTheTestEnvKeepsTheHeaderAndReportsNoQueryCount(): void
    {
        // Without kernel.debug DoctrineBundle registers no DebugDataHolder, so
        // the count must be absent rather than a misleading zero.
        $kernel = new Kernel('test', false);
        try {
            $request = Request::create('/healthz');
            $request->headers->set('X-Kadupul-Profile-Id', 'debug-off');
            $response = $kernel->handle($request);
            $kernel->terminate($request, $response);

            self::assertMatchesRegularExpression('/\Aapp;dur=\d+(\.\d+)?, mem;desc="\d+"\z/', (string) $response->headers->get('Server-Timing'));
        } finally {
            $kernel->shutdown();
        }

        $record = json_decode((string) file_get_contents($this->logPath), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('debug-off', $record['id']);
        self::assertNull($record['queries']);
    }

    public function testLeavesSubRequestsWithoutAHeader(): void
    {
        $listener = new ServerTiming(new RequestProfileLog($this->logPath), true, null);
        $response = new \Symfony\Component\HttpFoundation\Response();

        $listener->onResponse(new \Symfony\Component\HttpKernel\Event\ResponseEvent($this->createStub(HttpKernelInterface::class), Request::create('/healthz'), HttpKernelInterface::SUB_REQUEST, $response));

        self::assertFalse($response->headers->has('Server-Timing'));
    }

    public function testSkipsARecordItCannotEncode(): void
    {
        new RequestProfileLog($this->logPath)->append('not-finite', '/healthz', INF, 1, 1024);

        self::assertFileDoesNotExist($this->logPath);
    }

    public function testSkipsTheRecordWhenTheLogDirectoryCannotBeCreated(): void
    {
        mkdir(dirname($this->logPath));
        $blocker = dirname($this->logPath) . '/blocker';
        touch($blocker);
        $warnings = [];
        set_error_handler(static function (int $level, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        }, E_WARNING);
        try {
            new RequestProfileLog($blocker . '/requests.jsonl')->append('blocked', '/healthz', 1.0, 1, 1024);
        } finally {
            restore_error_handler();
            unlink($blocker);
        }

        self::assertCount(1, $warnings);
        self::assertStringContainsString('mkdir', $warnings[0]);
        self::assertFileDoesNotExist($blocker . '/requests.jsonl');
    }
}
