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

require_once dirname(__DIR__) . '/Helpers/PhpSource.php';

final class DeviceWorkerProbeBarrierTest extends TestCase
{
    #[DataProvider('decisions')]
    public function testOnlyCompleteDecisionsReleaseTheWorker(?string $initial, string $published, bool $expected, int $waits): void
    {
        $result = $this->probe($initial, $published);

        self::assertSame(['result' => $expected, 'waits' => $waits, 'queries' => [[11, 9, 'probe']], 'ready' => 'ready'], $result);
    }

    /** @return iterable<string, array{?string, string, bool, int}> */
    public static function decisions(): iterable
    {
        yield 'missing decision' => [null, 'fail', false, 1];
        yield 'empty visible file' => ['', 'fail', false, 1];
        foreach (['f', 'fa', 'fai'] as $partial) {
            yield 'partial ' . $partial => [$partial, 'fail', false, 1];
        }
        foreach (['r', 'releas'] as $partial) {
            yield 'partial ' . $partial => [$partial, 'release', true, 1];
        }
        yield 'trailing newline is not a decision' => ["fail\n", 'fail', false, 1];
        yield 'unknown decision' => ['unexpected', 'release', true, 1];
        yield 'complete failure' => ['fail', 'fail', false, 0];
        yield 'complete success' => ['release', 'release', true, 0];
    }

    public function testAnIncompleteDecisionTimesOutInsteadOfReportingSuccess(): void
    {
        self::assertSame(['error' => 'Owned probe barrier timed out', 'waits' => 1, 'queries' => [[11, 9, 'probe']], 'ready' => 'ready'], $this->probe('', null));
    }

    /** @return array<string, mixed> */
    private function probe(?string $initial, ?string $published): array
    {
        $root = dirname(__DIR__, 2);
        $source = file_get_contents($root . '/tests/Fixtures/device-worker-probe-bootstrap.php');
        self::assertNotFalse($source);
        $function = \test_php_function_source($source, 'run_data_query');
        self::assertNotSame('', $function);
        $directory = sys_get_temp_dir() . '/worker-probe-barrier-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($directory, 0700));

        try {
            if ($initial !== null) {
                self::assertSame(strlen($initial), file_put_contents($directory . '/release', $initial));
            }
            $program = <<<'PHP'
namespace WorkerProbeBarrier;
use RuntimeException;
$clock = 0.0;
$waits = 0;
$queries = [];
function microtime(bool $asFloat): float { return $GLOBALS['clock']; }
function db_execute_prepared(string $sql, array $parameters): bool {
    $GLOBALS['queries'][] = $parameters;
    return true;
}
// Publish only after the reader has rejected the incomplete decision. Advancing
// the clock instead lets the real timeout guard run without a wall-clock race.
function usleep(int $microseconds): void {
    ++$GLOBALS['waits'];
    $GLOBALS['clock'] += $GLOBALS['published'] === null ? 9.0 : 0.01;
    if ($GLOBALS['published'] !== null) {
        file_put_contents(getenv('KADUPUL_TEST_PROBE_RELEASE'), $GLOBALS['published']);
    }
}
PHP;
            $program .= "\n" . '$published = ' . var_export($published, true) . ';' . "\n" . $function . <<<'PHP'

try { $result = ['result' => run_data_query(11, 9)]; }
catch (RuntimeException $error) { $result = ['error' => $error->getMessage()]; }
echo json_encode($result + ['waits' => $waits, 'queries' => $queries, 'ready' => file_get_contents(getenv('KADUPUL_TEST_PROBE_READY'))], JSON_THROW_ON_ERROR);
PHP;
            $process = new Process([PHP_BINARY, '-r', $program], $root, [
                'KADUPUL_TEST_PROBE_READY' => $directory . '/ready',
                'KADUPUL_TEST_PROBE_RELEASE' => $directory . '/release',
            ]);
            $process->setTimeout(5);
            $process->run();
            self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            self::assertSame('', $process->getErrorOutput());

            return json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        } finally {
            foreach (['ready', 'release'] as $file) {
                if (is_file($directory . '/' . $file)) {
                    unlink($directory . '/' . $file);
                }
            }
            rmdir($directory);
        }
    }
}
