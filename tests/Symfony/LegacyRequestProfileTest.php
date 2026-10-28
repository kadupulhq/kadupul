<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use PHPUnit\Framework\TestCase;
use PHPUnit\Runner\CodeCoverage;
use Symfony\Component\Process\Process;

require_once dirname(__DIR__) . '/Helpers/PhpSource.php';
require_once dirname(__DIR__) . '/Helpers/NativeChildCoverageEvidence.php';

/**
 * include/global.php needs a database, so the shutdown writer and the counter
 * run as the shipped files in tests/Fixtures/legacy-request-profile-native.php.
 * When PHPUnit collects coverage the child records its own and this test
 * merges it, so both files are measured rather than only exercised.
 */
final class LegacyRequestProfileTest extends TestCase
{
    private const string PRODUCER = 'tests/Fixtures/legacy-request-profile-native.php';
    private const array SOURCES = ['tests/Helpers/NativeChildCoverageEvidence.php', 'lib/database.php', 'include/request_profile.php', 'src/Platform/Infrastructure/Profiling/RequestProfileLog.php'];
    private const array MARKERS = ['legacy-profile-scenario-completed'];

    private string $directory = '';

    private bool $rejectionsChecked = false;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/kadupul-legacy-profile-' . bin2hex(random_bytes(6));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    /**
     * @param array<string, string|false> $env false removes the variable from the child
     * @param list<string> $hits sources the scenario must execute
     */
    private function runLegacyScript(string $scenario, array $env, array $hits): void
    {
        $root = dirname(__DIR__, 2);
        $coverage = CodeCoverage::instance()->isActive() ? CodeCoverage::instance()->codeCoverage() : null;
        $process = new Process(
            [PHP_BINARY, '-d', 'auto_prepend_file=', '-d', 'pcov.directory=' . $root, '-d', 'pcov.exclude=~/(include/vendor|tests)/~',
                $root . '/' . self::PRODUCER, $this->directory, $scenario, $coverage !== null ? 'coverage' : ''],
            null,
            $env + ['KADUPUL_PROFILE_LOG' => $this->directory . '/requests.jsonl', 'HTTP_X_KADUPUL_PROFILE_ID' => 'legacy-1'],
        );
        $process->mustRun();
        self::assertSame('', $process->getErrorOutput());

        if ($coverage === null) {
            return;
        }
        $report = $this->directory . '/' . $scenario . '.coverage';
        $child = \NativeChildCoverageEvidence::load($report, $root, self::PRODUCER, $scenario, self::SOURCES, self::MARKERS, $hits);
        if (!$this->rejectionsChecked && $scenario === 'counted') {
            self::assertSame(count(self::SOURCES) + count(self::MARKERS) + 10, \NativeChildCoverageEvidence::verifyRejections($report, $root, self::PRODUCER, $scenario, self::SOURCES, self::MARKERS, $hits, 'src/Platform/Infrastructure/Profiling/RequestProfileLog.php'));
            $this->rejectionsChecked = true;
        }
        $coverage->merge($child);
        unlink($report . '.json');
        unlink($report);
    }

    /** @return list<array<string, mixed>> */
    private function records(): array
    {
        $lines = file($this->directory . '/requests.jsonl', FILE_IGNORE_NEW_LINES);
        self::assertIsArray($lines);

        return array_map(static fn(string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR), $lines);
    }

    public function testLegacyShutdownWriterRecordsTheLegacyQueryCountWhenProfiling(): void
    {
        $this->runLegacyScript('counted', ['KADUPUL_PROFILE' => '1'], ['lib/database.php', 'include/request_profile.php', 'src/Platform/Infrastructure/Profiling/RequestProfileLog.php']);

        $records = $this->records();
        self::assertCount(1, $records);
        self::assertSame('legacy-1', $records[0]['id']);
        self::assertSame(2, $records[0]['queries']);
        self::assertIsFloat($records[0]['ms']);
        self::assertGreaterThan(0, $records[0]['peak_bytes']);
    }

    public function testLegacyWriterRecordsNullWhenTheCounterIsUnavailable(): void
    {
        $this->runLegacyScript('uncounted', ['KADUPUL_PROFILE' => '1'], ['include/request_profile.php', 'src/Platform/Infrastructure/Profiling/RequestProfileLog.php']);

        $records = $this->records();
        self::assertCount(1, $records);
        self::assertArrayHasKey('queries', $records[0]);
        self::assertNull($records[0]['queries']);
    }

    public function testLegacyWriterIsInertWhenProfilingIsZero(): void
    {
        $this->runLegacyScript('counted', ['KADUPUL_PROFILE' => '0'], ['include/request_profile.php']);

        self::assertFileDoesNotExist($this->directory . '/requests.jsonl');
    }

    public function testLegacyWriterIsInertWhenProfilingIsAbsentFromTheEnvironment(): void
    {
        $this->runLegacyScript('counted', ['KADUPUL_PROFILE' => false], ['include/request_profile.php']);

        self::assertFileDoesNotExist($this->directory . '/requests.jsonl');
    }

    public function testLegacyWriterWritesNothingWithoutAnId(): void
    {
        $this->runLegacyScript('counted', ['KADUPUL_PROFILE' => '1', 'HTTP_X_KADUPUL_PROFILE_ID' => ''], ['include/request_profile.php']);

        self::assertFileDoesNotExist($this->directory . '/requests.jsonl');
    }

    public function testEveryStatementExecutionCountsOnceAtTheSingleChokePoint(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/lib/database.php');
        $body = test_php_function_source($source, 'db_execute_prepared');

        self::assertSame(2, substr_count($body, '$query->execute('));
        self::assertSame(1, substr_count($body, 'db_statement_count(true);'));
        self::assertLessThan(strpos($body, '$query->execute();'), strpos($body, 'db_statement_count(true);'));
    }
}
