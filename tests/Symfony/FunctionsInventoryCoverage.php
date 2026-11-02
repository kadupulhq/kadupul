<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use RuntimeException;
use Symfony\Component\Process\Process;

/** Bind the real --check command's repository inputs without exposing their contents. */
final class FunctionsInventoryCoverage
{
    public const string TARGET = 'tools/functions-inventory.php';
    public const string PRODUCER = 'tests/Fixtures/functions-inventory-coverage.php';
    public const string MARKER = 'functions-inventory-check-terminated';
    public const array SOURCES = ['composer.lock', 'tests/composer.lock', self::TARGET,
        'tests/Symfony/FunctionsInventoryCoverage.php', 'tests/Symfony/FunctionsInventoryCoverageTest.php',
        'tests/Symfony/FunctionsInventoryTest.php', 'tests/Symfony/FunctionsFreezeTest.php',
        'tests/Symfony/NativeCoverageStack.php', 'tests/Helpers/NativeChildCoverageEvidence.php',
        'tests/Fixtures/rrd-process-coverage.php'];

    public static function verifyInvocation(string $root, mixed $script, mixed $arguments): void
    {
        $target = realpath($root . '/' . self::TARGET);
        if ($target === false || !is_string($script) || realpath($script) !== $target
            || !is_array($arguments) || !array_is_list($arguments) || count($arguments) !== 2
            || !is_string($arguments[0]) || realpath($arguments[0]) !== $target || $arguments[1] !== '--check') {
            throw new RuntimeException('Inventory coverage invocation does not match --check.');
        }
    }

    /** @return array{command:string,count:int,files:array<string,string>} */
    public static function inputs(string $root): array
    {
        $git = new Process(['git', '-C', $root, 'ls-files', '-z', '--', '*.php'], timeout: 20);
        $git->mustRun();
        $paths = array_filter(explode("\0", $git->getOutput()), static fn(string $path): bool => $path !== '');
        foreach (['docs/migration/functions-inventory', 'tests/Golden/functions'] as $directory) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $directory, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file->isFile()) $paths[] = substr($file->getPathname(), strlen($root) + 1);
            }
        }
        $paths = array_values(array_unique($paths));
        sort($paths, SORT_STRING);
        $files = [];
        foreach ($paths as $path) {
            $digest = is_file($root . '/' . $path) ? hash_file('sha256', $root . '/' . $path) : false;
            if ($digest === false) throw new RuntimeException('Inventory coverage input is unavailable.');
            $files[$path] = $digest;
        }
        return ['command' => '--check', 'count' => count($files), 'files' => $files];
    }

    /** @param array{command:string,count:int,files:array<string,string>} $inputs */
    public static function scenario(array $inputs): string
    {
        return hash('sha256', json_encode($inputs, JSON_THROW_ON_ERROR));
    }

    public static function verifyInputs(string $root, string $expected): void
    {
        if (!hash_equals($expected, self::scenario(self::inputs($root)))) {
            throw new RuntimeException('Inventory coverage inputs changed.');
        }
    }
}
