<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Kadupul\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CdefNativeProbeCoverageTest extends TestCase
{
    public static function cases(): iterable
    {
        require_once dirname(__DIR__) . '/Helpers/CdefNativeCoverageRegistration.php';
        foreach (\CdefNativeCoverageRegistration::cases() as $case => $registration) yield $case => [$case,$registration];
    }

    public function testEarlyZeroExitCannotPublishNativeCompletionEvidence(): void
    {
        if (!extension_loaded('pcov')) self::markTestSkipped('Actual PCOV is required for the producer refusal test.');
        $root = dirname(__DIR__, 2);
        require_once dirname(__DIR__) . '/Helpers/CdefNativeCoverageRegistration.php';
        foreach (['regeneration' => 'PASS title_cache preserves actual legacy query substitution and truncation',
            'outer' => 'PASS remove_member_fanout success leaves caller transaction ownership unchanged',
            'contract' => 'PASS native contract capability and preflight probe complete',
            'version' => 'PASS actual nontransactional version marker is refused before mutation',
            'delete' => 'PASS native atomic legacy deletion probe complete',
            'copy' => 'PASS native actual production callers probe complete',
            'import' => 'PASS native actual production callers probe complete',
            'branches' => 'PASS only-similar preserves previous independently observed mixed graph on failure'] as $case => $earlyMarker) {
            $directory = sys_get_temp_dir() . '/cdef-native-early-' . bin2hex(random_bytes(8));
            $candidate = $directory . '/source';
            $reports = $directory . '/reports';
            self::assertTrue(mkdir($reports, 0700, true));
            try {
                foreach (\CdefNativeCoverageRegistration::sources() as $relative) {
                    $target = $candidate . '/' . $relative;
                    if (!is_dir(dirname($target))) self::assertTrue(mkdir(dirname($target), 0700, true));
                    self::assertTrue(copy($root . '/' . $relative, $target));
                }
                self::assertTrue(symlink($root . '/include/vendor', $candidate . '/include/vendor'));
                $probe = $candidate . '/tests/security/' . \CdefNativeCoverageRegistration::cases()[$case][0];
                if (!is_dir(dirname($probe))) self::assertTrue(mkdir(dirname($probe), 0700, true));
                $body = '<?php declare(strict_types=1); echo ' . var_export($earlyMarker . "\n", true) . '; exit(0);';
                self::assertSame(strlen($body), file_put_contents($probe, $body));
                $process = new \Symfony\Component\Process\Process(
                    [PHP_BINARY, '-d', 'pcov.enabled=1', '-d', 'pcov.directory=' . $candidate,
                        '-d', 'auto_prepend_file=' . $candidate . '/tests/Fixtures/cdef-native-coverage.php', $probe],
                    $candidate,
                    ['KADUPUL_NATIVE_COVERAGE_CASE' => $case, 'KADUPUL_NATIVE_COVERAGE_DIRECTORY' => $reports],
                    timeout: 30
                );
                self::assertNotSame(0, $process->run());
                self::assertStringContainsString('did not confirm completion', $process->getErrorOutput());
                self::assertFileDoesNotExist($reports . '/native.coverage');
                self::assertSame([], glob($reports . '/*'));
            } finally {
                (new \Symfony\Component\Filesystem\Filesystem())->remove($directory);
            }
        }
    }

    #[DataProvider('cases')]
    public function testOriginalNativeProbeAndMeasuredCompletion(string $case, array $registration): void
    {
        $dsn = getenv('KADUPUL_REFERENCE_TEST_DSN') ?: getenv('KADUPUL_TEST_MYSQL_DSN');
        if (!is_string($dsn) || !str_starts_with($dsn, 'mysql:')) self::markTestSkipped('An explicit native reference test DSN is required.');
        $root = dirname(__DIR__, 2);
        $directory = sys_get_temp_dir() . '/cdef-native-coverage-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($directory, 0700));
        $active = \PHPUnit\Runner\CodeCoverage::instance()->isActive() ? \PHPUnit\Runner\CodeCoverage::instance()->codeCoverage() : null;
        $environment = getenv();
        $environment['KADUPUL_REFERENCE_TEST_DSN'] = $dsn;
        $environment['KADUPUL_REFERENCE_TEST_USER'] = getenv('KADUPUL_REFERENCE_TEST_USER') ?: (getenv('KADUPUL_TEST_MYSQL_USER') ?: 'root');
        $environment['KADUPUL_REFERENCE_TEST_PASSWORD'] = getenv('KADUPUL_REFERENCE_TEST_PASSWORD') ?: (getenv('KADUPUL_TEST_MYSQL_PASSWORD') ?: '');
        $environment['KADUPUL_NATIVE_COVERAGE_CASE'] = $case;
        $environment['KADUPUL_NATIVE_COVERAGE_DIRECTORY'] = $directory;
        $command = [PHP_BINARY,'-d','pcov.enabled=' . ($active !== null ? '1' : '0'),'-d','zend.exception_ignore_args=1','-d','auto_prepend_file=' . ($active !== null ? $root . '/tests/Fixtures/cdef-native-coverage.php' : ''),
            '-d','pcov.directory=' . $root,'-d','pcov.exclude=~/(include/vendor|tests)/|^' . preg_quote($root, '~') . '/var/~',$root . '/tests/security/' . $registration[0]];
        try {
            $process = new \Symfony\Component\Process\Process($command, $root, $environment, timeout: 600);
            $status = $process->run();
            $output = $process->getOutput();
            $errors = $process->getErrorOutput();
            self::assertSame(0, $status, $errors . $output);
            self::assertSame('', $errors);
            self::assertContains($registration[1], explode("\n", $output));
            if ($active !== null) {
                require_once dirname(__DIR__) . '/Helpers/NativeChildCoverageEvidence.php';
                $probe = 'tests/security/' . $registration[0];
                $markers = [$registration[1],'probe-completed-and-cleanup-returned',\CdefNativeCoverageRegistration::runtimeMarker()];
                $arguments = [$directory . '/native.coverage',$root,$probe,$case,\CdefNativeCoverageRegistration::sources(),$markers,$registration[2]];
                $coverage = \NativeChildCoverageEvidence::load(...$arguments);
                self::assertSame(
                    count(\CdefNativeCoverageRegistration::sources()) + count($markers) + 10,
                    \NativeChildCoverageEvidence::verifyRejections(...[...$arguments,'lib/boost.php'])
                );
                if (in_array($case, ['branches', 'regeneration'], true)) {
                    $requiredSources = $case === 'branches' ? ['lib/api_graph.php'] : ['src/Platform/Infrastructure/Legacy/HostDataSubstitution.php', 'lib/variables.php'];
                    $report = $directory . '/native.coverage';
                    $originalReport = file_get_contents($report);
                    $originalEvidence = file_get_contents($report . '.json');
                    try {
                        foreach ($requiredSources as $requiredSource) {
                            foreach (['removed', 'zero-hits'] as $mode) {
                                $missing = unserialize($originalReport);
                                $data = $missing->getData();
                                $lines = $data->lineCoverage();
                                $requiredPath = realpath($root . '/' . $requiredSource);
                                if ($mode === 'removed') {
                                    unset($lines[$requiredPath]);
                                } else {
                                    $lines[$requiredPath] = array_map(static fn($hits) => [], $lines[$requiredPath]);
                                }
                                $data->setLineCoverage($lines);
                                $missing->setData($data);
                                $serialized = serialize($missing);
                                self::assertSame(strlen($serialized), file_put_contents($report, $serialized));
                                $evidence = json_decode($originalEvidence, true, 512, JSON_THROW_ON_ERROR);
                                $evidence['report'] = hash_file('sha256', $report);
                                file_put_contents($report . '.json', json_encode($evidence, JSON_THROW_ON_ERROR));
                                try {
                                    \NativeChildCoverageEvidence::load(...$arguments);
                                    self::fail('Native completion was accepted without required execution: ' . $requiredSource);
                                } catch (\RuntimeException $error) {
                                    self::assertSame('Required native source was not executed: ' . $requiredSource, $error->getMessage());
                                }
                            }
                        }
                    } finally {
                        file_put_contents($report, $originalReport);
                        file_put_contents($report . '.json', $originalEvidence);
                    }
                }
                $active->merge($coverage);
            }
        } finally {
            foreach (glob($directory . '/*') ?: [] as $file) unlink($file);
            rmdir($directory);
        }
    }
}
