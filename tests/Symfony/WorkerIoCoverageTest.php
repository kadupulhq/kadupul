<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

require_once __DIR__ . '/WorkerIoCoverage.php';

final class WorkerIoCoverageTest extends TestCase
{
    private const string WORKER = 'bin/legacy-poller-output-empty.php';
    private ?string $directory = null;

    protected function setUp(): void
    {
        if (!extension_loaded('pcov')) $this->markTestSkipped('The actual copied-worker collector requires PCOV.');
        $root = dirname(__DIR__, 2);
        $this->directory = realpath(sys_get_temp_dir()) . '/worker-io-evidence-' . bin2hex(random_bytes(8));
        foreach (['', '/bin', '/include', '/lib'] as $suffix) self::assertTrue(mkdir($this->directory . $suffix, 0700));
        foreach ([self::WORKER, 'bin/worker-io.php'] as $file) self::assertTrue(copy($root . '/' . $file, $this->directory . '/' . $file));
        foreach (['poller', 'data_query', 'dsstats', 'dsdebug', 'boost', 'rrd'] as $library) {
            self::assertNotFalse(file_put_contents($this->directory . '/lib/' . $library . '.php', '<?php'));
        }
        self::assertNotFalse(file_put_contents($this->directory . '/include/cli_check.php', '<?php require '
            . var_export($root . '/tests/Fixtures/drain-cli-bootstrap.php', true) . ';'));
    }

    protected function tearDown(): void
    {
        if ($this->directory === null) return;
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            if ($file->isDir() && !$file->isLink()) rmdir($file->getPathname());
            else unlink($file->getPathname());
        }
        rmdir($this->directory);
    }

    public function testActualHelperHitsAndBothSourceBindingsAreRequired(): void
    {
        $root = dirname(__DIR__, 2);
        $report = $this->admitted();
        $coverage = WorkerIoCoverage::load($report, $root, self::WORKER, 'success');
        foreach ([self::WORKER, 'bin/worker-io.php'] as $source) {
            self::assertNotEmpty(array_filter(
                $coverage->getData(true)->lineCoverage()[realpath($root . '/' . $source)],
                static fn(?array $hits): bool => $hits !== null && $hits !== []
            ));
        }
        $original = file_get_contents($report . '.json');
        self::assertIsString($original);
        foreach (['missing', 'stale'] as $fault) {
            $evidence = json_decode($original, true, 512, JSON_THROW_ON_ERROR);
            if ($fault === 'missing') unset($evidence['sources']['bin/worker-io.php']);
            else $evidence['sources']['bin/worker-io.php'] = str_repeat('0', 64);
            self::assertNotFalse(file_put_contents($report . '.json', json_encode($evidence, JSON_THROW_ON_ERROR)));
            try {
                WorkerIoCoverage::load($report, $root, self::WORKER, 'success');
                self::fail('An unbound helper report was accepted.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('source is missing or stale: bin/worker-io.php', $error->getMessage());
            }
        }
        self::assertNotFalse(file_put_contents($report . '.json', $original));
        self::assertSame(
            count(WorkerIoCoverage::sources(self::WORKER)) + 16,
            \NativeChildCoverageEvidence::verifyRejections(
                $report,
                $root,
                WorkerIoCoverage::PRODUCER,
                'success',
                WorkerIoCoverage::sources(self::WORKER),
                [WorkerIoCoverage::MARKER],
                [self::WORKER, 'bin/worker-io.php'],
                'bin/worker-io.php',
                NativeCoverageStack::capture($root)
            )
        );
    }

    #[DataProvider('copyFaults')]
    public function testMissingStaleAndChangedHelperCopiesCannotPublishCoverage(string $fault): void
    {
        // First admit the same real worker, collector and library before injecting one fault.
        $report = $this->admitted();
        unlink($report . '.json');
        unlink($report);
        $helper = $this->directory . '/bin/worker-io.php';
        if ($fault === 'missing') unlink($helper);
        elseif ($fault === 'stale') self::assertNotFalse(file_put_contents($helper, "\n// Different copied source.\n", FILE_APPEND));
        else self::assertNotFalse(file_put_contents(
            $this->directory . '/include/cli_check.php',
            'file_put_contents(' . var_export($helper, true) . ', "\\n// Changed during the worker.\\n", FILE_APPEND);',
            FILE_APPEND
        ));
        $child = $this->child();
        self::assertNotSame(0, $child->getExitCode());
        self::assertStringContainsString('Copied CLI changed while measuring coverage', $child->getErrorOutput(), $child->getOutput());
        self::assertSame([], glob($this->directory . '/*.coverage'));
        self::assertSame([], glob($this->directory . '/*.coverage.json'));
    }

    public static function copyFaults(): iterable
    {
        yield 'missing helper' => ['missing'];
        yield 'stale helper before startup' => ['stale'];
        yield 'helper changed after startup' => ['changed'];
    }

    private function admitted(): string
    {
        $child = $this->child();
        self::assertSame(0, $child->getExitCode(), $child->getErrorOutput());
        self::assertSame('', $child->getErrorOutput());
        self::assertSame('KADUPUL_POLLER_OUTPUT_EMPTY_RESULT={"status":"ok","updates":1}' . PHP_EOL, $child->getOutput());
        $reports = glob($this->directory . '/*.coverage');
        self::assertIsArray($reports);
        self::assertCount(1, $reports);
        WorkerIoCoverage::load($reports[0], dirname(__DIR__, 2), self::WORKER, 'success');
        return $reports[0];
    }

    private function child(): Process
    {
        $root = dirname(__DIR__, 2);
        [$options, $environment] = WorkerIoCoverage::configuration($root, $this->directory, self::WORKER, 'success');
        $probe = new Process([PHP_BINARY, '-r', 'exit(extension_loaded("pcov") ? 0 : 1);']);
        $probe->run();
        $extension = $probe->isSuccessful() ? [] : ['-d', 'extension=pcov.so'];
        $child = new Process(
            [PHP_BINARY, ...$extension, '-d', 'display_errors=stderr', '-d', 'log_errors=0', '-d', 'pcov.enabled=1', '-d', 'pcov.directory=/',
                '-d', 'pcov.exclude=~/(include/vendor|tests)/~', ...$options, $this->directory . '/' . self::WORKER],
            null,
            $environment + ['DRAIN_FIXTURE' => $this->directory, 'DRAIN_MODE' => 'success'],
            timeout: 20
        );
        $child->setInput('[]');
        $child->run();
        return $child;
    }
}
