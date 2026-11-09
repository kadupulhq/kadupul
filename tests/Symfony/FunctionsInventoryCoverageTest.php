<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

require_once __DIR__ . '/FunctionsInventoryCoverage.php';
require_once __DIR__ . '/NativeCoverageStack.php';
require_once dirname(__DIR__) . '/Helpers/NativeChildCoverageEvidence.php';

final class FunctionsInventoryCoverageTest extends TestCase
{
    public function testActualReadOnlyCommandHasBoundInputsAndMeasuredDispatch(): void
    {
        if (!extension_loaded('pcov')) self::markTestSkipped('The native inventory command requires the coverage job PCOV extension.');
        $root = dirname(__DIR__, 2);
        $directory = realpath(sys_get_temp_dir()) . '/inventory-coverage-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($directory, 0700));
        try {
            $stack = NativeCoverageStack::capture($root);
            $inputs = FunctionsInventoryCoverage::inputs($root);
            self::assertGreaterThan(200, $inputs['count']);
            self::assertArrayHasKey('lib/functions.php', $inputs['files']);
            self::assertArrayHasKey('docs/migration/functions-inventory/index.json', $inputs['files']);
            $scenario = FunctionsInventoryCoverage::scenario($inputs);
            $process = new Process([PHP_BINARY, '-d', 'pcov.enabled=1', '-d', 'pcov.directory=' . $root,
                '-d', 'pcov.exclude=~/(include/vendor|tests/vendor)/~', '-d', 'auto_prepend_file=' . $root . '/' . FunctionsInventoryCoverage::PRODUCER,
                $root . '/' . FunctionsInventoryCoverage::TARGET, '--check'], $root, [
                    'KADUPUL_INVENTORY_COVERAGE_STACK' => json_encode($stack, JSON_THROW_ON_ERROR),
                    'KADUPUL_INVENTORY_COVERAGE_DIRECTORY' => $directory, 'KADUPUL_INVENTORY_COVERAGE_SCENARIO' => $scenario,
                ], timeout: 30);
            $process->mustRun();
            self::assertSame('', $process->getErrorOutput());
            self::assertStringContainsString('is current', $process->getOutput());
            FunctionsInventoryCoverage::verifyInputs($root, $scenario);
            // The admitted real command must precede each invocation refusal.
            foreach (['wrong argument', 'wrong script'] as $case) {
                $refusalDirectory = $directory . '-' . str_replace(' ', '-', $case);
                self::assertTrue(mkdir($refusalDirectory, 0700));
                try {
                    $target = $root . '/' . FunctionsInventoryCoverage::TARGET;
                    $argument = '--help';
                    if ($case === 'wrong script') {
                        $target = $refusalDirectory . '/other.php';
                        self::assertNotFalse(file_put_contents($target, '<?php file_put_contents(__DIR__ . "/executed", "unexpected");'));
                        $argument = '--check';
                    }
                    $refusal = new Process([PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'log_errors=0',
                        '-d', 'auto_prepend_file=' . $root . '/' . FunctionsInventoryCoverage::PRODUCER, $target, $argument], $root, [
                            'KADUPUL_INVENTORY_COVERAGE_STACK' => json_encode($stack, JSON_THROW_ON_ERROR),
                            'KADUPUL_INVENTORY_COVERAGE_DIRECTORY' => $refusalDirectory,
                            'KADUPUL_INVENTORY_COVERAGE_SCENARIO' => $scenario,
                        ], timeout: 30);
                    $refusal->run();
                    self::assertFalse($refusal->isSuccessful());
                    self::assertStringContainsString('Inventory coverage invocation does not match --check.', $refusal->getErrorOutput());
                    self::assertSame([], glob($refusalDirectory . '/*.coverage*'));
                    self::assertFileDoesNotExist($refusalDirectory . '/executed');
                } finally {
                    foreach (glob($refusalDirectory . '/*') as $file) unlink($file);
                    rmdir($refusalDirectory);
                }
            }
            $reports = glob($directory . '/*.coverage');
            self::assertIsArray($reports);
            self::assertCount(1, $reports);
            $coverage = \NativeChildCoverageEvidence::load(
                $reports[0],
                $root,
                FunctionsInventoryCoverage::PRODUCER,
                $scenario,
                FunctionsInventoryCoverage::SOURCES,
                [FunctionsInventoryCoverage::MARKER],
                [FunctionsInventoryCoverage::TARGET],
                $stack
            );
            $toolSource = file_get_contents($root . '/' . FunctionsInventoryCoverage::TARGET);
            self::assertIsString($toolSource);
            $dispatch = strpos($toolSource, '    exit(main($argv));');
            self::assertNotFalse($dispatch);
            $dispatchLine = substr_count(substr($toolSource, 0, $dispatch), "\n") + 1;
            self::assertNotEmpty($coverage->getData(true)->lineCoverage()[realpath($root . '/' . FunctionsInventoryCoverage::TARGET)][$dispatchLine]);
            self::assertGreaterThan(100, count(array_filter($coverage->getData(true)->lineCoverage()[realpath($root . '/' . FunctionsInventoryCoverage::TARGET)], static fn($hits): bool => is_array($hits) && $hits !== [])));
            self::assertSame(
                count(FunctionsInventoryCoverage::SOURCES) + 16,
                \NativeChildCoverageEvidence::verifyRejections(
                    $reports[0],
                    $root,
                    FunctionsInventoryCoverage::PRODUCER,
                    $scenario,
                    FunctionsInventoryCoverage::SOURCES,
                    [FunctionsInventoryCoverage::MARKER],
                    [FunctionsInventoryCoverage::TARGET],
                    FunctionsInventoryCoverage::TARGET,
                    $stack
                )
            );
            if (\PHPUnit\Runner\CodeCoverage::instance()->isActive()) \PHPUnit\Runner\CodeCoverage::instance()->codeCoverage()->merge($coverage);
        } finally {
            foreach (glob($directory . '/*') as $file) unlink($file);
            rmdir($directory);
        }
    }

    public function testActualInputGuardRefusesChangedMissingAndAddedRepositoryInputs(): void
    {
        $directory = realpath(sys_get_temp_dir()) . '/inventory-inputs-' . bin2hex(random_bytes(8));
        foreach (['', '/docs', '/docs/migration', '/docs/migration/functions-inventory', '/tests', '/tests/Golden', '/tests/Golden/functions'] as $suffix) self::assertTrue(mkdir($directory . $suffix, 0700));
        try {
            self::assertNotFalse(file_put_contents($directory . '/sample.php', '<?php function sample() {}'));
            self::assertNotFalse(file_put_contents($directory . '/docs/migration/functions-inventory/index.json', '{}'));
            self::assertNotFalse(file_put_contents($directory . '/tests/Golden/functions/sample.json', '{}'));
            (new Process(['git', '-C', $directory, 'init', '-q']))->mustRun();
            (new Process(['git', '-C', $directory, 'add', 'sample.php']))->mustRun();
            $scenario = FunctionsInventoryCoverage::scenario(FunctionsInventoryCoverage::inputs($directory));
            FunctionsInventoryCoverage::verifyInputs($directory, $scenario);
            foreach (['sample.php', 'docs/migration/functions-inventory/index.json', 'tests/Golden/functions/sample.json'] as $path) {
                $original = file_get_contents($directory . '/' . $path);
                foreach (['changed', 'missing'] as $mutation) {
                    if ($mutation === 'changed') file_put_contents($directory . '/' . $path, $original . ' ');
                    else unlink($directory . '/' . $path);
                    try {
                        FunctionsInventoryCoverage::verifyInputs($directory, $scenario);
                        self::fail('Changed inventory input was accepted.');
                    } catch (\RuntimeException) {
                        self::assertTrue(true);
                    } finally {
                        file_put_contents($directory . '/' . $path, $original);
                    }
                }
            }
            file_put_contents($directory . '/docs/migration/functions-inventory/extra.json', '{}');
            $this->expectException(\RuntimeException::class);
            FunctionsInventoryCoverage::verifyInputs($directory, $scenario);
        } finally {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) {
                if ($file->isDir() && !$file->isLink()) rmdir($file->getPathname());
                else unlink($file->getPathname());
            }
            rmdir($directory);
        }
    }
}
