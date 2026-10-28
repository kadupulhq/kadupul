<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Graphing\Application\Command\PurgeBoostImageCache;
use Kadupul\Graphing\Application\Port\BoostImageCacheUnavailable;
use Kadupul\Graphing\Domain\BoostGraphCacheKey;
use Kadupul\Graphing\Infrastructure\Legacy\InstallationBoostImageCache;
use Kadupul\Graphing\Infrastructure\Symfony\BoostImageCacheCommand;
use Kadupul\Graphing\Infrastructure\Symfony\PurgeBoostImageCacheHandler;
use Kadupul\Platform\Contract\DatabaseConnection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

final class BoostImageCacheCommandTest extends TestCase
{
    private const string HASH = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private string $base;
    private string $cache;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/kadupul-boost-cache-' . bin2hex(random_bytes(8));
        $this->cache = $this->base . '/cache';
        mkdir($this->cache, 0700, true);
        $this->base = (string) realpath($this->base);
        $this->cache = $this->base . '/cache';
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->base);
    }

    /** @param array<string, string> $settings */
    private function adapter(array $settings): InstallationBoostImageCache
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE settings (name TEXT PRIMARY KEY, value TEXT)');
        foreach ($settings as $name => $value) {
            $pdo->prepare('INSERT INTO settings (name, value) VALUES (?, ?)')->execute([$name, $value]);
        }
        $connection = new class ($pdo) implements DatabaseConnection {
            public function __construct(private \PDO $pdo) {}

            public function get(): \PDO
            {
                return $this->pdo;
            }
        };

        return new InstallationBoostImageCache($connection, $this->base);
    }

    private function configured(string $enabled = 'on'): InstallationBoostImageCache
    {
        return $this->adapter(['boost_png_cache_enable' => $enabled, 'boost_png_cache_directory' => $this->cache]);
    }

    private function file(string $name, int $age): string
    {
        $path = $this->cache . '/' . $name;
        file_put_contents($path, 'image');
        touch($path, time() - $age);

        return $path;
    }

    public function testDeletesOnlyExpiredCacheFiles(): void
    {
        $old = $this->file('modern_lgi_7_rrai_1_rk_' . self::HASH . '.png', 7200);
        $oldThumb = $this->file('modern_lgi_7_rrai_0_tsi_86400_height_120_width_200_rk_' . self::HASH . '_thumb.png', 7200);
        $fresh = $this->file('modern_lgi_8_rrai_1_rk_' . self::HASH . '.png', 60);

        self::assertSame(2, $this->configured()->purgeOlderThan(3600));
        self::assertFileDoesNotExist($old);
        self::assertFileDoesNotExist($oldThumb);
        self::assertFileExists($fresh);
    }

    public function testIgnoresSymlinkAndForeignFiles(): void
    {
        $outside = $this->base . '/outside.png';
        file_put_contents($outside, 'image');
        touch($outside, time() - 7200);
        $link = $this->cache . '/modern_lgi_7_rrai_1_rk_' . self::HASH . '.png';
        symlink($outside, $link);
        $notes = $this->file('notes.txt', 7200);
        $foreignPng = $this->file('other.png', 7200);
        mkdir($this->cache . '/modern_lgi_9_rrai_1_rk_' . self::HASH . '.png');

        self::assertSame(0, $this->configured()->purgeOlderThan(3600));
        self::assertTrue(is_link($link));
        self::assertFileExists($outside);
        foreach ([$notes, $foreignPng] as $kept) {
            self::assertFileExists($kept);
        }
        self::assertDirectoryExists($this->cache . '/modern_lgi_9_rrai_1_rk_' . self::HASH . '.png');
    }

    public function testDeletesExpiredTempFilesLeftByInterruptedWrites(): void
    {
        self::assertSame(1, preg_match("/define\\('BOOST_PNG_TEMP_PREFIX', '([^']+)'\\);/", (string) file_get_contents(dirname(__DIR__, 2) . '/lib/boost.php'), $prefix));
        $old = $this->file($prefix[1] . '0123456789abcdef', 7200);
        $fresh = $this->file($prefix[1] . 'fedcba9876543210', 60);
        $outside = $this->base . '/outside';
        file_put_contents($outside, 'image');
        touch($outside, time() - 7200);
        $link = $this->cache . '/' . $prefix[1] . 'aaaaaaaaaaaaaaaa';
        symlink($outside, $link);
        mkdir($this->cache . '/' . $prefix[1] . 'bbbbbbbbbbbbbbbb');
        touch($this->cache . '/' . $prefix[1] . 'bbbbbbbbbbbbbbbb', time() - 7200);

        self::assertSame(1, $this->configured()->purgeOlderThan(3600));
        self::assertFileDoesNotExist($old);
        self::assertFileExists($fresh);
        self::assertTrue(is_link($link));
        self::assertFileExists($outside);
        self::assertDirectoryExists($this->cache . '/' . $prefix[1] . 'bbbbbbbbbbbbbbbb');
    }

    public function testSymlinkedCacheDirectoryOutsideTheInstallationIsRefused(): void
    {
        $elsewhere = sys_get_temp_dir() . '/kadupul-boost-elsewhere-' . bin2hex(random_bytes(8));
        mkdir($elsewhere);
        try {
            $victim = $elsewhere . '/modern_lgi_7_rrai_1_rk_' . self::HASH . '.png';
            file_put_contents($victim, 'image');
            touch($victim, time() - 7200);
            symlink($elsewhere, $this->base . '/linked');
            $cache = $this->adapter(['boost_png_cache_enable' => 'on', 'boost_png_cache_directory' => $this->base . '/linked']);

            try {
                $cache->purgeOlderThan(3600);
                self::fail('A cache directory resolving outside the installation was purged.');
            } catch (BoostImageCacheUnavailable) {
                self::assertFileExists($victim);
            }
        } finally {
            (new Filesystem())->remove($elsewhere);
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unconfigured')]
    public function testUnconfiguredDirectoryIsAFailureNotANoop(array $settings): void
    {
        $this->expectException(BoostImageCacheUnavailable::class);
        $this->adapter($settings)->purgeOlderThan(3600);
    }

    /** @return iterable<string, array{array<string, string>}> */
    public static function unconfigured(): iterable
    {
        yield 'no setting' => [[]];
        yield 'empty' => [['boost_png_cache_directory' => '']];
        yield 'missing directory' => [['boost_png_cache_directory' => '/nonexistent/kadupul-boost-cache']];
        yield 'filesystem root' => [['boost_png_cache_directory' => '/']];
    }

    public function testInstallationRootIsNotACacheDirectory(): void
    {
        $this->expectException(BoostImageCacheUnavailable::class);
        $this->adapter(['boost_png_cache_directory' => $this->base])->purgeOlderThan(3600);
    }

    public function testCommandReportsCountAsJson(): void
    {
        $this->file('modern_lgi_7_rrai_1_rk_' . self::HASH . '.png', 7200);
        $tester = new CommandTester(new BoostImageCacheCommand($this->configured('off')));

        self::assertSame(0, $tester->execute(['--json' => true]));
        self::assertSame(['status' => 'ok', 'removed' => 1], json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR));
    }

    public function testCommandFailsWhenUnconfigured(): void
    {
        $tester = new CommandTester(new BoostImageCacheCommand($this->adapter([])));

        self::assertSame(1, $tester->execute(['--json' => true]));
        self::assertSame('failed', json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR)['status']);
        self::assertSame(1, $tester->execute([]));
        self::assertStringContainsString('boost_png_cache_directory', $tester->getDisplay());
    }

    public function testHandlerSkipsWhenCacheIsDisabledAndPurgesWhenEnabled(): void
    {
        $path = $this->file('modern_lgi_7_rrai_1_rk_' . self::HASH . '.png', 7200);

        (new PurgeBoostImageCacheHandler($this->configured('off')))(new PurgeBoostImageCache());
        self::assertFileExists($path);

        (new PurgeBoostImageCacheHandler($this->configured('on')))(new PurgeBoostImageCache());
        self::assertFileDoesNotExist($path);
    }

    public function testFilePatternMatchesEveryNameTheCacheKeyProduces(): void
    {
        $viewer = ['theme' => 'modern', 'selected_theme' => 'my-theme_2', 'color_mode' => '', 'fonts' => '', 'date_format' => '1',
            'datechar' => '1', 'php_tz' => 'UTC', 'tz' => '', 'lang' => '', 'locale' => '', 'country' => ''];
        foreach ([[1, []], [0, ['graph_height' => 120, 'graph_width' => '200']], [0, ['graph_nolegend' => 'on']], [0, ['graph_height' => -5]]] as [$rra, $graph]) {
            $name = basename(BoostGraphCacheKey::fromGraphRequest(7, $rra, 86400, $graph, $viewer)->fileName('/c'));
            self::assertSame(1, preg_match(BoostGraphCacheKey::FILE_PATTERN, $name), $name);
        }
        self::assertSame(0, preg_match(BoostGraphCacheKey::FILE_PATTERN, 'x_lgi_1_rrai_1_rk_abc.png'));
        self::assertSame(0, preg_match(BoostGraphCacheKey::FILE_PATTERN, "modern_lgi_1_rrai_1_rk_" . self::HASH . ".png\n"));
    }
}
