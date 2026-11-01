<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-2.0-or-later

namespace Kadupul\Tests;

use PHPUnit\Framework\Assert;
use Symfony\Component\Process\Process;

require_once __DIR__ . '/NativeCoverageStack.php';
require_once __DIR__ . '/NativeSessionCoverageRegistration.php';
require_once dirname(__DIR__) . '/Helpers/NativeChildCoverageEvidence.php';

final class NativeSessionChildCoverage
{
    private static bool $negativeControlsChecked = false;
    private static ?bool $childHasPcov = null;

    /** @param array{stack:string,version:string,class_sha256:string} $stack */
    private function __construct(private string $directory, private string $scenario, private string $fixture, private array $stack) {}

    public static function start(string $scenario, string $fixture): ?self
    {
        if (!\PHPUnit\Runner\CodeCoverage::instance()->isActive()) return null;
        $parent = realpath(sys_get_temp_dir());
        if ($parent === false) throw new \RuntimeException('Native coverage temporary root is unavailable.');
        $directory = $parent . '/native-session-child-' . bin2hex(random_bytes(12));
        if (!mkdir($directory, 0700)) throw new \RuntimeException('Unable to preserve native session coverage.');
        return new self($directory, $scenario, $fixture, NativeCoverageStack::capture(dirname(__DIR__, 2)));
    }

    private static function needsPcov(): bool
    {
        if (self::$childHasPcov === null) {
            $probe = new Process([PHP_BINARY, '-r', 'exit(extension_loaded("pcov") ? 0 : 1);'], timeout: 5);
            $probe->run();
            Assert::assertContains($probe->getExitCode(), [0, 1]);
            self::$childHasPcov = $probe->isSuccessful();
        }
        return !self::$childHasPcov;
    }

    /** @return list<string> */
    public function arguments(): array
    {
        return [...(self::needsPcov() ? ['-d', 'extension=pcov.so'] : []), '-d', 'pcov.enabled=1', '-d', 'pcov.directory=/',
            '-d', 'pcov.exclude=~/(include/vendor|tests/vendor)/~', '-d', 'auto_prepend_file=' . dirname(__DIR__, 2) . '/' . NativeSessionCoverageRegistration::PRODUCER];
    }

    /** @return array<string,string> */
    public function environment(): array
    {
        return ['NATIVE_SESSION_COVERAGE_DIRECTORY' => $this->directory, 'NATIVE_SESSION_COVERAGE_SCENARIO' => $this->scenario,
            'NATIVE_SESSION_COVERAGE_STACK' => json_encode($this->stack, JSON_THROW_ON_ERROR), 'NATIVE_SESSION_COVERAGE_ADD_PCOV' => self::needsPcov() ? '1' : '0'];
    }

    public function finish(): void
    {
        $root = dirname(__DIR__, 2);
        $engine = $this->fixture === 'session-engine-native.php';
        $source = 'tests/Fixtures/' . ($engine ? 'session-engine-endpoint.php' : $this->fixture);
        $reports = glob($this->directory . ($engine ? '/*' : '') . '/*.coverage');
        Assert::assertIsArray($reports);
        Assert::assertCount($engine ? 18 : 1, $reports);
        $expected = [];
        if ($engine) {
            foreach (['php', 'php_binary', 'php_serialize'] as $mode) {
                foreach (['login', 'older', 'read', 'logout', 'destroy'] as $action) {
                    $scenario = $this->scenario . ':' . $mode . ':' . $action;
                    $expected[hash('sha256', $scenario)] = ['scenario' => $scenario, 'remaining' => $action === 'read' ? 2 : 1];
                }
            }
        } else {
            $expected[hash('sha256', $this->scenario)] = ['scenario' => $this->scenario, 'remaining' => 1];
        }
        foreach ($reports as $report) {
            $json = file_get_contents($report . '.json');
            Assert::assertIsString($json);
            $evidence = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
            Assert::assertIsArray($evidence);
            $key = $evidence['scenario'] ?? null;
            Assert::assertIsString($key);
            Assert::assertArrayHasKey($key, $expected);
            Assert::assertGreaterThan(0, $expected[$key]['remaining']);
            --$expected[$key]['remaining'];
            $hits = [$source, $this->fixture === 'session-data-native.php' ? 'lib/session_data.php' : 'lib/session_store.php'];
            $child = \NativeChildCoverageEvidence::load(
                $report,
                $root,
                NativeSessionCoverageRegistration::PRODUCER,
                $expected[$key]['scenario'],
                NativeSessionCoverageRegistration::SOURCES,
                NativeSessionCoverageRegistration::MARKERS,
                $hits,
                $this->stack
            );
            if (!self::$negativeControlsChecked) {
                Assert::assertSame(
                    count(NativeSessionCoverageRegistration::SOURCES) + count(NativeSessionCoverageRegistration::MARKERS) + 15,
                    \NativeChildCoverageEvidence::verifyRejections(
                        $report,
                        $root,
                        NativeSessionCoverageRegistration::PRODUCER,
                        $expected[$key]['scenario'],
                        NativeSessionCoverageRegistration::SOURCES,
                        NativeSessionCoverageRegistration::MARKERS,
                        $hits,
                        $hits[1],
                        $this->stack
                    )
                );
                self::$negativeControlsChecked = true;
            }
            \PHPUnit\Runner\CodeCoverage::instance()->codeCoverage()->merge($child);
        }
        foreach ($expected as $scenario) Assert::assertSame(0, $scenario['remaining']);
    }

    public function cleanup(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            if ($file->isDir() && !$file->isLink()) rmdir($file->getPathname());
            else unlink($file->getPathname());
        }
        rmdir($this->directory);
    }
}
