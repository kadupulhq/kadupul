<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Kernel;
use Kadupul\Platform\Infrastructure\Asset\CompiledAssetManifest;
use PHPUnit\Framework\TestCase;
use Symfony\Component\AssetMapper\Command\AssetMapperCompileCommand;
use Symfony\Component\AssetMapper\CompiledAssetMapperConfigReader;
use Symfony\Component\AssetMapper\Path\LocalPublicAssetsFilesystem;
use Symfony\Component\Console\Tester\CommandTester;

require_once dirname(__DIR__) . '/Helpers/PhpSource.php';

/**
 * Runs asset-map:compile with the application's configuration into a scratch
 * public directory and checks what html_common_header() would then emit.
 * Needs the browser build (npm run build), as CI runs it before this suite.
 */
final class CompiledLegacyAssetsTest extends TestCase
{
    private static string $root;
    private static string $public;
    /** @var array<string, string> */
    private static array $entries;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(__DIR__, 2);
        foreach (['include/fa/css/all.css', 'include/js/jquery.js'] as $built) {
            if (!is_file(self::$root . '/' . $built)) {
                self::fail($built . ' is missing; run npm ci && npm run build first.');
            }
        }

        self::$public = sys_get_temp_dir() . '/kadupul-compiled-' . bin2hex(random_bytes(6));
        mkdir(self::$public);

        // An operator's custom.css must never be compiled, so give the
        // compiler one to skip when this checkout has none.
        $custom = self::$root . '/include/themes/custom.css';
        $createdCustom = !file_exists($custom) && file_put_contents($custom, "body { color: red; }\n") !== false;

        $kernel = new Kernel('test', true);
        try {
            $kernel->boot();
            $services = $kernel->getContainer()->get('test.service_container');
            $command = new AssetMapperCompileCommand(
                new CompiledAssetMapperConfigReader(self::$public . '/assets'),
                $services->get('asset_mapper'),
                $services->get('asset_mapper.importmap.generator'),
                new LocalPublicAssetsFilesystem(self::$public),
                self::$root,
                false,
            );
            $tester = new CommandTester($command);
            if ($tester->execute([]) !== 0) {
                self::fail('asset-map:compile failed: ' . $tester->getDisplay());
            }
        } finally {
            $kernel->shutdown();
            if ($createdCustom) {
                unlink($custom);
            }
        }

        self::$entries = json_decode((string) file_get_contents(self::$public . '/assets/manifest.json'), true, 2, JSON_THROW_ON_ERROR);
    }

    public static function tearDownAfterClass(): void
    {
        if (!isset(self::$public) || !is_dir(self::$public)) {
            return;
        }
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(self::$public, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir(self::$public);
    }

    public function testEveryHeaderIncludeResolvesThroughTheManifest(): void
    {
        $manifest = new CompiledAssetManifest(self::$public . '/assets/manifest.json', 'public');
        $checked = 0;
        foreach (self::themes() as $theme) {
            foreach (self::headerIncludes($theme) as $include) {
                // AssetMapper exclusions are global, so the one vendor stylesheet
                // (flag-icons) cannot be mapped without mapping all of include/vendor.
                if (str_starts_with($include, 'include/vendor/')) {
                    self::assertNull($manifest->publicPath($include), $include);
                    continue;
                }
                $compiled = $manifest->publicPath($include);
                self::assertNotNull($compiled, $theme . ': ' . $include . ' has no compiled copy');
                self::assertFileExists(self::compiledFile($compiled));
                ++$checked;
            }
        }
        // 12 theme stylesheets and 27 scripts per theme, minus flag-icons.
        self::assertGreaterThanOrEqual(38 * count(self::themes()), $checked);
    }

    public function testCompiledStylesheetsReferenceOnlyCompiledFiles(): void
    {
        $pending = [];
        foreach (self::themes() as $theme) {
            foreach (self::headerIncludes($theme) as $include) {
                if (str_ends_with($include, '.css') && isset(self::$entries[$include])) {
                    $pending[] = self::$public . self::$entries[$include];
                }
            }
        }

        $seen = [];
        $fonts = [];
        while ($pending !== []) {
            $file = array_pop($pending);
            if (isset($seen[$file])) {
                continue;
            }
            $seen[$file] = true;
            preg_match_all('~(?:url\(\s*|@import\s+(?!url\())["\']?(?!data:|#|%23)([^"\')\s]+)["\']?~', (string) file_get_contents($file), $matches);
            foreach ($matches[1] as $reference) {
                self::assertStringNotContainsString('?', $reference, $file . ' keeps a query on ' . $reference);
                $target = self::normalize(dirname($file) . '/' . $reference);
                self::assertFileExists($target, $file . ' references ' . $reference);
                self::assertMatchesRegularExpression('~-[\w-]{7}\.\w+$~', basename($target), $reference . ' is not digested');
                if (str_ends_with($target, '.css')) {
                    $pending[] = $target;
                }
                if (str_ends_with($target, '.woff2')) {
                    $fonts[basename($target)] = true;
                }
            }
        }

        self::assertCount(4, $fonts, 'Font Awesome fonts reachable from the compiled stylesheet');
        self::assertArrayHasKey(self::$public . self::$entries['include/themes/midwinter/css/media/core.css'], $seen, 'midwinter imports resolve to compiled files');
    }

    public function testCompiledScriptsAreByteIdenticalToTheirSources(): void
    {
        $scripts = 0;
        foreach (self::$entries as $logical => $public) {
            if (str_ends_with($logical, '.js')) {
                self::assertFileEquals(self::$root . '/' . $logical, self::$public . $public, $logical);
                ++$scripts;
            }
        }
        self::assertGreaterThan(30, $scripts);
    }

    public function testPrivateAndOperatorFilesStayOutOfTheManifest(): void
    {
        foreach (array_keys(self::$entries) as $logical) {
            self::assertStringEndsNotWith('.php', $logical);
            self::assertDoesNotMatchRegularExpression('~^include/(vendor|content|fonts)/|^include/(config|global|plugins)|^include/cacti_version$~', $logical);
        }
        self::assertArrayNotHasKey('include/themes/custom.css', self::$entries);
        self::assertArrayHasKey('include/layout.js', self::$entries);
    }

    /** @return list<string> */
    private static function themes(): array
    {
        $themes = array_map(static fn(string $css): string => basename(dirname($css)), glob(self::$root . '/include/themes/*/main.css') ?: []);
        sort($themes);

        return $themes;
    }

    /** @return list<string> */
    private static function headerIncludes(string $theme): array
    {
        $source = test_php_function_source((string) file_get_contents(self::$root . '/lib/html.php'), 'html_common_header');
        preg_match_all("~get_md5_include_(?:css|js)\\('([^']*)'(?:\\s*\\.\\s*\\\$selectedTheme\\s*\\.\\s*'([^']*)')?~", $source, $matches, PREG_SET_ORDER);
        $includes = [];
        foreach ($matches as $match) {
            $path = $match[1] . (isset($match[2]) ? $theme . $match[2] : '');
            // custom.css is emitted only when an operator adds one.
            if ($path !== 'include/themes/custom.css') {
                $includes[] = $path;
            }
        }

        return $includes;
    }

    private static function compiledFile(string $webPath): string
    {
        self::assertStringStartsWith('public/assets/', $webPath);

        return self::$public . substr($webPath, strlen('public'));
    }

    private static function normalize(string $path): string
    {
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '..') {
                array_pop($parts);
            } elseif ($part !== '.') {
                $parts[] = $part;
            }
        }

        return implode('/', $parts);
    }
}
