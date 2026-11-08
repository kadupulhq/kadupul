<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Platform\Infrastructure\Symfony\CacheDirectoryLock;
use Kadupul\Platform\Infrastructure\Symfony\CacheRefreshInProgress;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/**
 * cache:clear swaps the production build directory while booted web kernels
 * still load container service files from it by path. The installer worker
 * runs that refresh while the web installer polls its status.
 */
final class CacheClearConcurrencyTest extends TestCase
{
    private string $directory;
    private string $cacheRoot;
    private ?Process $server = null;

    #[\Override]
    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/kadupul-cache-swap-' . bin2hex(random_bytes(8));
        $this->cacheRoot = $this->directory . '/cache';
        self::assertTrue(mkdir($this->directory, 0o700));
        $warmup = $this->console('cache:warmup');
        $warmup->run();
        self::assertSame(0, $warmup->getExitCode(), $warmup->getErrorOutput());
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->server?->stop();
        (new Filesystem())->remove($this->directory);
    }

    public function testCacheClearWaitsForBootedWebRequestBeforeSwappingItsContainer(): void
    {
        $address = $this->startServer();
        $request = $this->send($address, '/?pause=1');
        $this->waitFor(fn(): bool => is_file($this->directory . '/booted'), 'web kernel boots');

        $clear = $this->console('cache:clear');
        $clear->start();
        $warmupDir = $this->cacheRoot . '/pro_';
        $deadline = microtime(true) + 4;
        $swapStarted = false;
        while (microtime(true) < $deadline && $clear->isRunning() && !$swapStarted) {
            $swapStarted = is_dir($warmupDir) || !is_dir($this->cacheRoot . '/prod');
            usleep(10000);
        }
        $waiting = $clear->isRunning();

        touch($this->directory . '/release');
        $response = $this->receive($request, 20);
        $clear->wait();

        self::assertFalse($swapStarted, 'cache:clear began replacing the build directory under a booted web request');
        self::assertTrue($waiting, 'cache:clear finished while a booted web request still used the old container');
        self::assertStringContainsString(' 200 ', strtok($response, "\r\n"), $response);
        self::assertStringContainsString('{"status":"ok"}', $response);
        self::assertSame(0, $clear->getExitCode(), $clear->getErrorOutput());
        self::assertFileExists($this->cacheRoot . '/prod/Kadupul_KernelProdContainer.php');
    }

    public function testWebRequestWaitsWhileCacheIsBeingReplaced(): void
    {
        $address = $this->startServer();
        $lock = CacheDirectoryLock::exclusive($this->cacheRoot . '/prod', 1);
        try {
            $request = $this->send($address, '/');
            $read = [$request];
            $write = $except = null;
            self::assertSame(0, stream_select($read, $write, $except, 1), 'web request ran while the cache was locked for replacement');
        } finally {
            $lock->release();
        }
        $response = $this->receive($request, 20);
        self::assertStringContainsString(' 200 ', strtok($response, "\r\n"), $response);
    }

    public function testBackgroundChildDoesNotInheritSharedLock(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('exec_background() uses start on Windows.');
        }
        $buildDir = $this->directory . '/inherit/prod';
        $shared = CacheDirectoryLock::shared($buildDir, 1);
        self::assertInstanceOf(CacheDirectoryLock::class, $shared);
        // The same shell form exec_background() uses to start the installer worker.
        exec('sleep 5 > /dev/null 2>&1 &');
        $shared->release();

        $exclusive = CacheDirectoryLock::exclusive($buildDir, 1);
        $exclusive->release();
        self::assertFileExists($this->directory . '/inherit/prod.lock');
    }

    public function testExclusiveLockTimesOutWhileReaderIsActive(): void
    {
        $buildDir = $this->directory . '/reader/prod';
        $shared = CacheDirectoryLock::shared($buildDir, 1);
        self::assertInstanceOf(CacheDirectoryLock::class, $shared);
        $second = CacheDirectoryLock::shared($buildDir, 1);
        self::assertInstanceOf(CacheDirectoryLock::class, $second, 'readers share the lock');
        try {
            $started = microtime(true);
            try {
                CacheDirectoryLock::exclusive($buildDir, 0.2);
                self::fail('the exclusive lock was granted while readers held it');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('Cannot lock the Symfony cache', $error->getMessage());
            }
            self::assertGreaterThanOrEqual(0.2, microtime(true) - $started);
        } finally {
            $shared->release();
            $second->release();
        }
        CacheDirectoryLock::exclusive($buildDir, 1)->release();
    }

    public function testWebRequestAnswers503WhenRefreshOutlastsReaderBound(): void
    {
        $address = $this->startServer();
        $lock = CacheDirectoryLock::exclusive($this->cacheRoot . '/prod', 1);
        try {
            $started = microtime(true);
            $response = $this->receive($this->send($address, '/'), 40);
            $waited = microtime(true) - $started;
        } finally {
            $lock->release();
        }
        self::assertStringContainsString(' 503 ', strtok($response, "\r\n"), $response);
        self::assertMatchesRegularExpression('/^Retry-After: 5\r$/mi', $response);
        self::assertGreaterThanOrEqual(CacheDirectoryLock::SHARED_TIMEOUT_SECONDS - 0.5, $waited);
        self::assertLessThan(CacheDirectoryLock::SHARED_TIMEOUT_SECONDS + 10, $waited);
        self::assertStringContainsString(' 200 ', strtok($this->receive($this->send($address, '/'), 20), "\r\n"));
    }

    public function testRefreshIsNotStarvedByOverlappingReaders(): void
    {
        $buildDir = $this->directory . '/busy/prod';
        $autoload = dirname(__DIR__, 2) . '/include/vendor/autoload.php';
        $readers = [];
        // Two readers whose shared locks always overlap: before writer
        // priority, flock() never granted the exclusive lock between them.
        foreach ([0, 1] as $reader) {
            $script = 'require ' . var_export($autoload, true) . ';'
                . 'usleep(' . (75000 * $reader) . '); $marker = ' . var_export($this->directory . '/reader' . $reader, true) . ';'
                . 'while (!is_file(' . var_export($this->directory . '/stop', true) . ')) {'
                . ' try { $lock = \\Kadupul\\Platform\\Infrastructure\\Symfony\\CacheDirectoryLock::shared(' . var_export($buildDir, true) . ', 0.2); } catch (\RuntimeException) { continue; }'
                . ' touch($marker); usleep(150000); $lock->release(); }';
            $process = new Process([PHP_BINARY, '-r', $script]);
            $process->start();
            $readers[] = $process;
        }
        try {
            $this->waitFor(fn(): bool => is_file($this->directory . '/reader0') && is_file($this->directory . '/reader1'), 'readers hold the lock');
            $started = microtime(true);
            $lock = CacheDirectoryLock::exclusive($buildDir, 5);
            $waited = microtime(true) - $started;
            try {
                try {
                    CacheDirectoryLock::shared($buildDir, 0.3);
                    self::fail('a reader passed the gate during the refresh');
                } catch (CacheRefreshInProgress) {
                }
            } finally {
                $lock->release();
            }
            self::assertLessThan(2, $waited, 'the refresh waited for more than the running readers');
            $shared = CacheDirectoryLock::shared($buildDir, 2);
            self::assertInstanceOf(CacheDirectoryLock::class, $shared);
            $shared->release();
        } finally {
            touch($this->directory . '/stop');
            foreach ($readers as $process) {
                $process->wait();
                self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            }
        }
    }

    public function testLockFilesArePrivateToTheApplication(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('POSIX modes do not apply on Windows.');
        }
        $parent = $this->directory . '/fresh/cache';
        CacheDirectoryLock::shared($parent . '/prod', 1)?->release();
        $mask = umask();
        self::assertSame(0o750 & ~$mask, fileperms($this->directory . '/fresh/cache') & 0o777);
        self::assertSame(0o750 & ~$mask, fileperms($this->directory . '/fresh') & 0o777);
        foreach ([CacheDirectoryLock::path($parent . '/prod'), CacheDirectoryLock::gatePath($parent . '/prod')] as $file) {
            self::assertSame(0o640 & ~$mask, fileperms($file) & 0o777, $file);
        }

        // Files another local user could open are refused by both sides.
        chmod(CacheDirectoryLock::path($parent . '/prod'), 0o644);
        self::assertNull(CacheDirectoryLock::shared($parent . '/prod', 1));
        try {
            CacheDirectoryLock::exclusive($parent . '/prod', 0.2);
            self::fail('a refresh locked a lock file readable by other users');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('not private to the application', $error->getMessage());
        }

        $target = $this->directory . '/elsewhere';
        self::assertTrue(touch($target) && chmod($target, 0o600));
        self::assertTrue(symlink($target, CacheDirectoryLock::gatePath($parent . '/other')));
        self::assertNull(CacheDirectoryLock::shared($parent . '/other', 1));
    }

    private function console(string $command): Process
    {
        $root = dirname(__DIR__, 2);

        return new Process(
            [PHP_BINARY, $root . '/bin/console', $command, '--env=prod', '--no-debug', '--no-interaction'],
            $root,
            ['APP_ENV' => 'prod', 'APP_DEBUG' => '0', 'APP_CACHE_DIR' => $this->cacheRoot],
            null,
            120,
        );
    }

    private function startServer(): string
    {
        $root = dirname(__DIR__, 2);
        $router = $this->directory . '/front.php';
        // A minimal production front controller like install.php: handle a
        // route through lazily loaded container services, optionally booting
        // first and pausing while the test refreshes the cache.
        $source = '<?php $_SERVER["APP_CACHE_DIR"] = ' . var_export($this->cacheRoot, true) . ';'
            . 'require ' . var_export($root . '/include/vendor/autoload.php', true) . ';'
            . '$kernel = new \Kadupul\Kernel("prod", false);'
            . 'if (isset($_GET["pause"])) { $kernel->boot(); touch(' . var_export($this->directory . '/booted', true) . ');'
            . '$deadline = microtime(true) + 30; while (!is_file(' . var_export($this->directory . '/release', true) . ') && microtime(true) < $deadline) { usleep(10000); } }'
            . '$request = \Symfony\Component\HttpFoundation\Request::create("/healthz");'
            . '$response = $kernel->handle($request); $response->send(); $kernel->terminate($request, $response);';
        self::assertNotFalse(file_put_contents($router, $source));
        $socket = stream_socket_server('tcp://127.0.0.1:0', $error, $message);
        self::assertIsResource($socket, $message);
        $address = stream_socket_get_name($socket, false);
        self::assertIsString($address);
        fclose($socket);
        $this->server = new Process([PHP_BINARY, '-S', $address, $router], $root);
        $this->server->start();
        $this->waitFor(static function () use ($address): bool {
            $connection = @stream_socket_client('tcp://' . $address, $error, $message, 0.1);
            if (!is_resource($connection)) {
                return false;
            }
            fclose($connection);

            return true;
        }, 'fixture server listens');

        return $address;
    }

    /** @return resource */
    private function send(string $address, string $path)
    {
        $connection = stream_socket_client('tcp://' . $address, $error, $message, 5);
        self::assertIsResource($connection, $message);
        fwrite($connection, "GET {$path} HTTP/1.0\r\nHost: localhost\r\n\r\n");

        return $connection;
    }

    /** @param resource $connection */
    private function receive($connection, int $timeout): string
    {
        stream_set_timeout($connection, $timeout);
        $response = stream_get_contents($connection);
        fclose($connection);
        self::assertIsString($response);
        self::assertNotSame('', $response, (string) $this->server?->getErrorOutput());

        return $response;
    }

    private function waitFor(\Closure $condition, string $description): void
    {
        $deadline = microtime(true) + 15;
        while (!$condition()) {
            self::assertTrue(microtime(true) < $deadline, $description . ': ' . $this->server?->getErrorOutput());
            usleep(10000);
        }
    }
}
