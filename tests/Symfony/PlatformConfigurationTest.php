<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Kernel;
use Kadupul\Navigation\Application\Port\LinkStore;
use Kadupul\Platform\Contract\PlatformConfiguration;
use Kadupul\Platform\Infrastructure\Legacy\InstallationConfiguration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * include/global.php still fills $config, and the array stays the contract.
 * These cases run a byte-identical copy of it and hold the typed object to
 * the values it produced.
 */
final class PlatformConfigurationTest extends TestCase
{
    private const array KEYS = [
        'base_path' => 'basePath',
        'url_path' => 'urlPath',
        'library_path' => 'libraryPath',
        'include_path' => 'includePath',
        'poller_id' => 'pollerId',
        'cacti_server_os' => 'serverOs',
    ];

    private const string DATABASE = '$database_default = "cacti"; $database_hostname = "localhost"; $database_username = "cactiuser"; $database_password = "secret";';
    private const string REMOTE = '$rdatabase_default = "cacti"; $rdatabase_hostname = "main.invalid"; $rdatabase_username = "cactiuser"; $rdatabase_password = "secret";';

    private string $scratch = '';

    protected function tearDown(): void
    {
        if ($this->scratch !== '') {
            exec('rm -rf ' . escapeshellarg($this->scratch));
        }
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function bootstraps(): iterable
    {
        yield 'web page on the main collector' => [self::DATABASE . ' $poller_id = 1; $url_path = "/kadupul/";', false];
        yield 'command-line script on the main collector' => [self::DATABASE . ' $poller_id = 1; $url_path = "/kadupul/";', true];
        yield 'remote collector' => [self::DATABASE . self::REMOTE . ' $poller_id = 2; $url_path = "/";', true];
        yield 'config.php without $url_path or $poller_id' => [self::DATABASE, false];
        yield 'empty $url_path' => [self::DATABASE . ' $url_path = "";', false];
        yield 'null $url_path' => [self::DATABASE . ' $url_path = null; $poller_id = null;', true];
    }

    #[DataProvider('bootstraps')]
    public function testObjectHoldsTheValuesAndTypesGlobalPhpSets(string $config, bool $cli): void
    {
        $legacy = $this->bootstrap($config, $cli);
        $platform = (new InstallationConfiguration($this->scratch))->platform();

        foreach (self::KEYS as $key => $property) {
            self::assertArrayHasKey($key, $legacy);
            self::assertSame($legacy[$key], $platform->{$property}, $key);
        }
    }

    public function testArrayIsStillPopulatedForLegacyCallers(): void
    {
        $legacy = $this->bootstrap(self::DATABASE . ' $poller_id = 1; $url_path = "/kadupul/";', false);
        $root = (string) realpath($this->scratch);

        self::assertSame([
            'poller_id' => 1,
            'cacti_server_os' => str_contains(PHP_OS, 'WIN') ? 'win32' : 'unix',
            'url_path' => '/kadupul/',
            'base_path' => $root,
            'library_path' => $root . '/lib',
            'include_path' => $root . '/include',
            'rra_path' => $root . '/rra',
        ], array_intersect_key($legacy, array_flip(['poller_id', 'cacti_server_os', 'url_path', 'base_path', 'library_path', 'include_path', 'rra_path'])));
    }

    public function testQuotedCollectorIdStaysAStringInTheArrayOnly(): void
    {
        $legacy = $this->bootstrap(self::DATABASE . self::REMOTE . ' $poller_id = "2";', true);

        self::assertSame('2', $legacy['poller_id']);
        self::assertSame(2, (new InstallationConfiguration($this->scratch))->platform()->pollerId);
    }

    public function testWindowsPathsFollowGlobalPhp(): void
    {
        $platform = PlatformConfiguration::fromBootstrap('C:\\kadupul\\include', 3, '/graphs/', 'WINNT');

        self::assertSame('C:/kadupul', $platform->basePath);
        self::assertSame('C:/kadupul/lib', $platform->libraryPath);
        self::assertSame('C:\\kadupul\\include', $platform->includePath);
        self::assertSame('win32', $platform->serverOs);
        self::assertSame(3, $platform->pollerId);
        self::assertSame('/graphs/', $platform->urlPath);
    }

    public function testValuesTheObjectCannotHoldAreRefused(): void
    {
        foreach ([[0, '/'], [1, ['/']]] as [$pollerId, $urlPath]) {
            try {
                PlatformConfiguration::fromBootstrap('/srv/kadupul/include', $pollerId, $urlPath, 'Linux');
                self::fail('Accepted poller ' . var_export($pollerId, true) . ' and url path ' . var_export($urlPath, true));
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testObjectIsImmutable(): void
    {
        $class = new \ReflectionClass(PlatformConfiguration::class);

        self::assertTrue($class->isFinal());
        self::assertTrue($class->isReadOnly());
        foreach ($class->getProperties() as $property) {
            self::assertTrue($property->isReadOnly(), $property->getName());
        }
        $methods = array_map(static fn(\ReflectionMethod $method): string => $method->getName(), $class->getMethods(\ReflectionMethod::IS_PUBLIC));
        sort($methods);
        self::assertSame(['__construct', 'fromBootstrap'], $methods);

        $platform = PlatformConfiguration::fromBootstrap('/srv/kadupul/include', 1, '/', 'Linux');
        $this->expectException(\Error::class);
        (static function (object $platform): void {
            $platform->basePath = '/tmp';
        })($platform);
    }

    public function testContainerDefersConfigurationUntilAValueIsRead(): void
    {
        $kernel = new Kernel('test', true);
        try {
            $kernel->boot();
            $container = $kernel->getContainer()->get('test.service_container');
            $platform = $container->get(PlatformConfiguration::class);
            $container->get(LinkStore::class);

            self::assertInstanceOf(PlatformConfiguration::class, $platform);
            self::assertTrue((new \ReflectionClass($platform))->isUninitializedLazyObject($platform));
        } finally {
            $kernel->shutdown();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function bootstrap(string $config, bool $cli): array
    {
        $this->scratch = sys_get_temp_dir() . '/platform-configuration-' . bin2hex(random_bytes(6));
        mkdir($this->scratch, 0700);
        $scenario = json_encode(['config' => $config, 'cli' => $cli], JSON_THROW_ON_ERROR);
        $command = escapeshellarg(PHP_BINARY) . ' -d auto_prepend_file= '
            . escapeshellarg(dirname(__DIR__) . '/Fixtures/platform-configuration-bootstrap.php') . ' '
            . escapeshellarg($scenario) . ' ' . escapeshellarg($this->scratch) . ' 2>&1';
        exec($command, $lines, $status);
        $output = implode("\n", $lines);
        self::assertSame(0, $status, $output);

        return json_decode($output, true, 512, JSON_THROW_ON_ERROR);
    }
}
