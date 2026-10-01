<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * get_md5_include_css()/get_md5_include_js() against an installation tree with
 * and without a compiled manifest. Each test runs alone because the manifest
 * reader is cached in a function-level static for the rest of the request.
 */
final class LegacyAssetIncludeTest extends TestCase
{
    private const string JS = "window.fixture = 'script';\n";
    private const string CSS = "body { color: red; }\n";

    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/kadupul-includes-' . bin2hex(random_bytes(6));
        foreach (['include/js', 'include/themes/modern', 'public/assets'] as $directory) {
            mkdir($this->base . '/' . $directory, 0o777, true);
        }
        file_put_contents($this->base . '/include/js/fixture.js', self::JS);
        file_put_contents($this->base . '/include/themes/modern/main.css', self::CSS);
        file_put_contents($this->base . '/include/themes/custom.css', self::CSS);

        $GLOBALS['config'] = ['base_path' => $this->base, 'url_path' => '/kadupul/', 'is_web' => false];
        // get_include_relpath() and get_md5_hash() resolve relative paths from
        // the working directory first, as a web request does from the web root.
        chdir($this->base);

        eval(<<<'PHP'
            class CactiSecureHeaders
            {
                public static function getNonceAttribute()
                {
                    return 'nonce="fixture"';
                }
            }

            function db_table_exists($table)
            {
                return false;
            }
            PHP);
        // Execute the production file so coverage records its actual lines.
        require dirname(__DIR__, 2) . '/lib/functions.php';
    }

    protected function tearDown(): void
    {
        chdir(dirname(__DIR__, 2));
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->base, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->base);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCompiledIncludesUseTheDigestedPathWithoutAQuery(): void
    {
        $this->writeManifest([
            'include/js/fixture.js' => '/assets/include/js/fixture-Ab3dE9x.js',
            'include/themes/modern/main.css' => '/assets/include/themes/modern/main-Qz_9-1a.css',
        ]);

        self::assertSame(
            "<link href='/kadupul/public/assets/include/themes/modern/main-Qz_9-1a.css' type='text/css' rel='stylesheet'>" . PHP_EOL,
            get_md5_include_css('include/themes/modern/main.css'),
        );
        self::assertSame(
            "<script type='text/javascript' nonce=\"fixture\" src='/kadupul/public/assets/include/js/fixture-Ab3dE9x.js'></script>" . PHP_EOL,
            get_md5_include_js('include/js/fixture.js'),
        );
        self::assertSame(
            "<script type='text/javascript' nonce=\"fixture\" src='/kadupul/public/assets/include/js/fixture-Ab3dE9x.js' async></script>" . PHP_EOL,
            get_md5_include_js('include/js/fixture.js', true),
        );
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testWithoutAManifestIncludesKeepTheMd5Query(): void
    {
        self::assertSame(
            "<link href='/kadupul/include/themes/modern/main.css?" . md5(self::CSS) . "' type='text/css' rel='stylesheet'>" . PHP_EOL,
            get_md5_include_css('include/themes/modern/main.css'),
        );
        self::assertSame(
            "<script type='text/javascript' nonce=\"fixture\" src='/kadupul/include/js/fixture.js?" . md5(self::JS) . "' async></script>" . PHP_EOL,
            get_md5_include_js('include/js/fixture.js', true),
        );
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testUnmappedIncludeKeepsTheMd5QueryBesideCompiledOnes(): void
    {
        $this->writeManifest(['include/themes/modern/main.css' => '/assets/include/themes/modern/main-Qz_9-1a.css']);

        self::assertSame(
            "<link href='/kadupul/include/themes/custom.css?" . md5(self::CSS) . "' type='text/css' rel='stylesheet'>" . PHP_EOL,
            get_md5_include_css('include/themes/custom.css'),
        );
        self::assertSame(
            "<script type='text/javascript' nonce=\"fixture\" src='/kadupul/include/js/fixture.js?" . md5(self::JS) . "'></script>" . PHP_EOL,
            get_md5_include_js('include/js/fixture.js'),
        );
        self::assertStringContainsString('public/assets/include/themes/modern/main-Qz_9-1a.css', get_md5_include_css('include/themes/modern/main.css'));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testMalformedManifestFallsBackToTheMd5Query(): void
    {
        file_put_contents($this->base . '/public/assets/manifest.json', '{"include/js/fixture.js": ');

        self::assertSame(
            "<script type='text/javascript' nonce=\"fixture\" src='/kadupul/include/js/fixture.js?" . md5(self::JS) . "'></script>" . PHP_EOL,
            get_md5_include_js('include/js/fixture.js'),
        );
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testMissingIncludeEmitsNothingEvenWhenTheManifestMapsIt(): void
    {
        // Keep the real notification debounce within its configured interval.
        $GLOBALS['config']['config_options_array'] = [
            'debounce_missing:include/js/removed.js' => time(),
            'debounce_missing:include/themes/modern/removed.css' => time(),
        ];
        $this->writeManifest(['include/js/removed.js' => '/assets/include/js/removed-Ab3dE9x.js']);

        self::assertSame('', get_md5_include_js('include/js/removed.js'));
        self::assertSame('', get_md5_include_css('include/themes/modern/removed.css'));
    }

    /** @param array<string, string> $entries */
    private function writeManifest(array $entries): void
    {
        file_put_contents($this->base . '/public/assets/manifest.json', json_encode($entries, JSON_THROW_ON_ERROR));
    }
}
