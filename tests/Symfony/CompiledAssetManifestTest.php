<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Platform\Infrastructure\Asset\CompiledAssetManifest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CompiledAssetManifestTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/kadupul-manifest-' . bin2hex(random_bytes(6));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    public function testMappedPathResolvesBelowTheWebRoot(): void
    {
        $manifest = $this->manifest([
            'include/js/jquery.js' => '/assets/include/js/jquery-3Xa9fQ1.js',
            'include/themes/modern/main.css' => '/assets/include/themes/modern/main-p8d__QV.css',
        ]);

        self::assertSame('public/assets/include/js/jquery-3Xa9fQ1.js', $manifest->publicPath('include/js/jquery.js'));
        self::assertSame('public/assets/include/themes/modern/main-p8d__QV.css', $manifest->publicPath('include/themes/modern/main.css'));
    }

    public function testUnmappedPathHasNoCompiledCopy(): void
    {
        $manifest = $this->manifest(['include/js/jquery.js' => '/assets/include/js/jquery-3Xa9fQ1.js']);

        self::assertNull($manifest->publicPath('include/themes/custom.css'));
        self::assertNull($manifest->publicPath(''));
        self::assertNull($manifest->publicPath('/include/js/jquery.js'));
    }

    public function testMissingManifestHasNoCompiledCopies(): void
    {
        $manifest = new CompiledAssetManifest($this->directory . '/manifest.json', 'public');

        self::assertNull($manifest->publicPath('include/js/jquery.js'));
    }

    public static function unreadableManifests(): iterable
    {
        yield 'empty file' => [''];
        yield 'truncated JSON' => ['{"include/js/jquery.js": "/assets/include/js/jq'];
        yield 'list instead of map' => ['["/assets/include/js/jquery-3Xa9fQ1.js"]'];
        yield 'scalar' => ['"/assets/include/js/jquery-3Xa9fQ1.js"'];
        yield 'nested value' => ['{"include/js/jquery.js": {"path": "/assets/include/js/jquery-3Xa9fQ1.js"}}'];
    }

    #[DataProvider('unreadableManifests')]
    public function testUnreadableManifestHasNoCompiledCopies(string $contents): void
    {
        file_put_contents($this->directory . '/manifest.json', $contents);
        $manifest = new CompiledAssetManifest($this->directory . '/manifest.json', 'public');

        self::assertNull($manifest->publicPath('include/js/jquery.js'));
        self::assertNull($manifest->publicPath('0'));
    }

    public static function unsafeTargets(): iterable
    {
        yield 'protocol-relative' => ['//cdn.example.com/jquery.js'];
        yield 'absolute URL' => ['https://cdn.example.com/jquery.js'];
        yield 'relative' => ['assets/include/js/jquery.js'];
        yield 'parent segment' => ['/assets/../include/config.php'];
        yield 'trailing parent segment' => ['/assets/include/..'];
        yield 'current segment' => ['/assets/./jquery.js'];
        yield 'attribute quote' => ["/assets/jquery.js' onerror='alert(1)"];
        yield 'query' => ['/assets/jquery.js?v=1'];
        yield 'backslash' => ['/assets\\jquery.js'];
        yield 'empty' => [''];
        yield 'number' => [42];
        yield 'trailing newline' => ["/assets/include/js/jquery.js\n"];
    }

    #[DataProvider('unsafeTargets')]
    public function testUnsafeTargetsAreIgnoredWithoutDroppingSafeOnes(mixed $target): void
    {
        $manifest = $this->manifest([
            'include/js/jquery.js' => $target,
            'include/js/d3.js' => '/assets/include/js/d3-Ab_c9.js',
        ]);

        self::assertNull($manifest->publicPath('include/js/jquery.js'));
        self::assertSame('public/assets/include/js/d3-Ab_c9.js', $manifest->publicPath('include/js/d3.js'));
    }

    public function testManifestIsReadOncePerInstance(): void
    {
        $manifest = $this->manifest(['include/js/jquery.js' => '/assets/include/js/jquery-3Xa9fQ1.js']);
        self::assertSame('public/assets/include/js/jquery-3Xa9fQ1.js', $manifest->publicPath('include/js/jquery.js'));

        unlink($this->directory . '/manifest.json');

        self::assertSame('public/assets/include/js/jquery-3Xa9fQ1.js', $manifest->publicPath('include/js/jquery.js'));
        self::assertNull((new CompiledAssetManifest($this->directory . '/manifest.json', 'public'))->publicPath('include/js/jquery.js'));
    }

    /** @param array<string, mixed> $entries */
    private function manifest(array $entries): CompiledAssetManifest
    {
        file_put_contents($this->directory . '/manifest.json', json_encode($entries, JSON_THROW_ON_ERROR));

        return new CompiledAssetManifest($this->directory . '/manifest.json', 'public');
    }
}
