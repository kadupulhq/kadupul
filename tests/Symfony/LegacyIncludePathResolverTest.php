<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Platform\Infrastructure\Legacy\LegacyIncludePathResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

require_once dirname(__DIR__) . '/Helpers/PhpSource.php';

final class LegacyIncludePathResolverTest extends TestCase
{
    public function testResolverPreservesAbsoluteThenApplicationRelativeLookupOrder(): void
    {
        $root = sys_get_temp_dir() . '/kadupul-include-path-' . bin2hex(random_bytes(6));
        $filesystem = new Filesystem();

        try {
            $filesystem->mkdir($root);
            $filesystem->dumpFile($root . '/assets/app.js', 'asset');
            $resolver = new LegacyIncludePathResolver($filesystem);

            self::assertSame('assets/app.js', $resolver->existingRelativePath($root . '/assets/app.js', $root));
            self::assertSame('assets/app.js', $resolver->existingRelativePath('assets/app.js', $root));
            self::assertFalse($resolver->existingRelativePath('missing.js', $root));
        } finally {
            $filesystem->remove($root);
        }
    }

    public function testLegacyWrapperKeepsSearchOrderAndNotificationBehavior(): void
    {
        $root = sys_get_temp_dir() . '/kadupul-include-wrapper-' . bin2hex(random_bytes(6));
        $filesystem = new Filesystem();

        try {
            $filesystem->mkdir($root);
            $filesystem->dumpFile($root . '/app.js', 'asset');

            $source = file_get_contents(dirname(__DIR__, 2) . '/lib/functions.php');
            self::assertIsString($source);
            $script = 'require ' . var_export(dirname(__DIR__, 2) . '/include/vendor/autoload.php', true) . ';'
                . 'function debounce_run_notification($key) { return $GLOBALS["notify"] ?? false; }'
                . 'function cacti_log(...$arguments) { $GLOBALS["log"] = $arguments; }'
                . 'function admin_email(...$arguments) { $GLOBALS["email"] = $arguments; }'
                . 'function __($message, ...$arguments) { return $arguments ? vsprintf($message, $arguments) : $message; }'
                . 'eval(' . var_export(test_php_function_source($source, 'get_include_relpath'), true) . ');'
                . '$config = ["base_path" => ' . var_export($root, true) . '];'
                . '$absolute = get_include_relpath(' . var_export($root . '/app.js', true) . ');'
                . '$relative = get_include_relpath("app.js");'
                . '$GLOBALS["notify"] = false; $quietMissing = get_include_relpath("missing.js");'
                . '$numericMissing = get_include_relpath(123);'
                . '$GLOBALS["notify"] = true; $reportedMissing = get_include_relpath("missing.js");'
                . 'echo json_encode([$absolute, $relative, $quietMissing, $numericMissing, $reportedMissing, $GLOBALS["log"], $GLOBALS["email"]], JSON_THROW_ON_ERROR);';
            $process = new Process([PHP_BINARY, '-r', $script]);
            $process->run();

            self::assertTrue($process->isSuccessful(), $process->getErrorOutput());
            self::assertSame(
                [
                    'app.js',
                    'app.js',
                    '',
                    '',
                    'missing.js',
                    [sprintf('WARNING: Key Kadupul Include File %s/missing.js missing.  Please locate and replace this file', $root), false, 'WEBUI'],
                    [
                        'Kadupul System Warning',
                        sprintf('WARNING:  Key Kadupul Include File %s/missing.js missing.  Please locate and replace this file', $root),
                    ],
                ],
                json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR)
            );
        } finally {
            $filesystem->remove($root);
        }
    }

    public function testLegacyWrapperKeepsWorkingBeforeComposerAutoloadIsRegistered(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/lib/functions.php');
        self::assertIsString($source);
        $script = 'function debounce_run_notification($key) { return false; }'
            . 'eval(' . var_export(test_php_function_source($source, 'get_include_relpath'), true) . ');'
            . '$config = ["base_path" => sys_get_temp_dir()];'
            . 'echo json_encode(get_include_relpath("missing.js"), JSON_THROW_ON_ERROR);';
        $process = new Process([PHP_BINARY, '-r', $script]);
        $process->run();

        self::assertTrue($process->isSuccessful(), $process->getErrorOutput());
        self::assertSame('""', $process->getOutput());
    }
}
