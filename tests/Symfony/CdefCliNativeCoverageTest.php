<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Kadupul\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

final class CdefCliNativeCoverageTest extends TestCase
{
    public static function cases(): iterable
    {
        require_once dirname(__DIR__) . '/Helpers/CdefCliCoverageRegistration.php';
        foreach (\CdefCliCoverageRegistration::cases() as $case => $registration) yield $case => [$case, $registration];
    }

    public function testEarlyParentCompletionIsRefused(): void
    {
        $directory = sys_get_temp_dir() . '/cdef-cli-early-' . bin2hex(random_bytes(8));
        $filesystem = new Filesystem();
        $filesystem->mkdir($directory, 0700);
        try {
            $body = '<?php echo "PASS earlier CLI assertion\\n"; exit(0);';
            self::assertSame(strlen($body), file_put_contents($directory . '/early.php', $body));
            $process = new Process([PHP_BINARY, '-d', 'auto_prepend_file=', $directory . '/early.php'], timeout: 30);
            self::assertSame(0, $process->run());
            try {
                self::requireCompletion($process->getExitCode(), $process->getOutput(), $process->getErrorOutput(), 'PASS native CLI confirmation and cleanup complete');
                self::fail('Actual early exit zero was accepted as completed SQL assertions and cleanup.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('original CLI assertions and cleanup', $error->getMessage());
            }
        } finally {
            $filesystem->remove($directory);
        }
    }

    private static function requireCompletion(int $exit, string $output, string $errors, string $terminal): void
    {
        if ($exit !== 0 || $errors !== '' || count(array_keys(explode("\n", $output), $terminal, true)) !== 1) {
            throw new \RuntimeException('The original CLI assertions and cleanup did not complete.');
        }
    }

    private function assertAdmissionRefused(string $stem, array $capture, array $arguments, int $exit, string $message): void
    {
        try {
            \CdefCliCoverageRegistration::admit($stem, $capture, $arguments, $exit);
            self::fail('Incomplete original CLI process evidence was accepted.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString($message, $error->getMessage());
        }
    }

    #[DataProvider('cases')]
    public function testOriginalCliChildCoverage(string $case, array $registration): void
    {
        $dsn = getenv('KADUPUL_REFERENCE_TEST_DSN') ?: getenv('KADUPUL_TEST_MYSQL_DSN');
        if (!is_string($dsn) || !str_starts_with($dsn, 'mysql:')) self::markTestSkipped('An explicit owned native fixture DSN is required.');
        if (!extension_loaded('pcov')) self::markTestSkipped('Actual PCOV is required to measure the original CLI.');
        $root = dirname(__DIR__, 2);
        require_once dirname(__DIR__) . '/Helpers/NativeChildCoverageEvidence.php';
        $directory = sys_get_temp_dir() . '/cdef-cli-measured-' . bin2hex(random_bytes(8));
        $candidate = $directory . '/source';
        $reports = $directory . '/reports';
        $filesystem = new Filesystem();
        $filesystem->mkdir([$candidate, $reports], 0700);
        try {
            $tracked = new Process(['git', 'ls-files', '-z'], $root);
            self::assertSame(0, $tracked->run(), $tracked->getErrorOutput());
            $paths = array_unique(array_merge(explode("\0", trim($tracked->getOutput(), "\0")), \CdefCliCoverageRegistration::sources()));
            foreach ($paths as $path) {
                if (!is_file($root . '/' . $path)) continue;
                $filesystem->copy($root . '/' . $path, $candidate . '/' . $path, true);
            }
            foreach (['include/vendor', 'include/fa', 'public/assets'] as $path) {
                self::assertDirectoryExists($root . '/' . $path);
                $filesystem->symlink($root . '/' . $path, $candidate . '/' . $path);
            }
            $filesystem->mkdir([$candidate . '/var', $candidate . '/log', $candidate . '/cache', $candidate . '/rra'], 0755);
            $filesystem->copy($candidate . '/tests/Fixtures/cdef-reference-runtime-config.php', $candidate . '/include/config.php', true);
            $filesystem->touch($candidate . '/.cdef-reference-task-owned-candidate');
            $oldSchema = new Process(['git', 'show', '5a1c81c2dc89508052c7b54bf9db491f32f9beb7:cacti.sql'], $root);
            self::assertSame(0, $oldSchema->run(), $oldSchema->getErrorOutput());
            self::assertSame('6f17f3462837d028c5e67a7b9ae963caa11f716f65036152431c316482284184', hash('sha256', $oldSchema->getOutput()));
            $previous = $directory . '/previous.sql';
            self::assertSame(strlen($oldSchema->getOutput()), file_put_contents($previous, $oldSchema->getOutput()));
            $environment = getenv();
            $environment['KADUPUL_REFERENCE_TEST_DSN'] = $dsn;
            $environment['KADUPUL_REFERENCE_TEST_USER'] = getenv('KADUPUL_REFERENCE_TEST_USER') ?: (getenv('KADUPUL_TEST_MYSQL_USER') ?: 'root');
            $environment['KADUPUL_REFERENCE_TEST_PASSWORD'] = getenv('KADUPUL_REFERENCE_TEST_PASSWORD') ?: (getenv('KADUPUL_TEST_MYSQL_PASSWORD') ?: '');
            $environment['KADUPUL_REFERENCE_PREVIOUS_SCHEMA_FILE'] = $previous;
            $environment['KADUPUL_CLI_COVERAGE_DIRECTORY'] = $reports;
            $process = new Process([PHP_BINARY, '-d', 'auto_prepend_file=', '-d', 'zend.exception_ignore_args=1',
                '-d', 'pcov.enabled=1', '-d', 'pcov.directory=' . $candidate,
                '-d', 'pcov.exclude=~/(include/vendor|tests)/~',
                $candidate . '/tests/security/' . $registration[0], ...$registration[1]], $candidate, $environment, timeout: 600);
            $exit = $process->run();
            self::assertSame(0, $exit, $process->getErrorOutput() . $process->getOutput());
            self::requireCompletion($exit, $process->getOutput(), $process->getErrorOutput(), $registration[3]);
            $invocations = glob($reports . '/*.invocation.json') ?: [];
            self::assertCount($registration[2], $invocations);
            self::assertCount($registration[2], glob($reports . '/*.exit.json') ?: []);
            self::assertCount($registration[2], glob($reports . '/*.coverage') ?: []);
            $active = \PHPUnit\Runner\CodeCoverage::instance()->isActive() ? \PHPUnit\Runner\CodeCoverage::instance()->codeCoverage() : null;
            $expectedExits = $case === 'current' ? [0, 0, 1, 1, 0]
                : (in_array($case, ['marker-refusal', 'marker-coercion'], true) ? [1, 0] : [$case === 'collector-online' ? 1 : 0]);
            $expectedArguments = $case === 'current'
                ? [['--install-cdef-reference-contract'], ['--install-cdef-reference-contract'], ['--install-cdef-reference-contract', '--local'],
                    ['--install-cdef-reference-contract'], ['--install-cdef-reference-contract']]
                : array_fill(0, $registration[2], $case === 'collector-local' ? ['--local'] : []);
            $ordinals = [];
            foreach ($invocations as $invocationFile) {
                $invocation = json_decode(file_get_contents($invocationFile), true, 512, JSON_THROW_ON_ERROR);
                $stem = $reports . '/' . $invocation['id'];
                $ordinals[] = $invocation['ordinal'];
                self::assertSame($stem . '.invocation.json', $invocationFile);
                self::assertSame($expectedArguments[$invocation['ordinal'] - 1], $invocation['arguments']);
                $digest = hash('sha256', json_encode(['cli/upgrade_database.php', ...$invocation['arguments']], JSON_THROW_ON_ERROR));
                self::assertSame($digest, $invocation['argv_sha256']);
                $capture = \CdefCliCoverageRegistration::capture($stem);
                [$invocation, $receipt] = \CdefCliCoverageRegistration::admit(
                    $stem,
                    $capture,
                    $expectedArguments[$invocation['ordinal'] - 1],
                    $expectedExits[$invocation['ordinal'] - 1]
                );
                $originalReceipt = file_get_contents($stem . '.exit.json');
                try {
                    self::assertTrue(unlink($stem . '.exit.json'));
                    $this->assertAdmissionRefused($stem, $capture, $expectedArguments[$invocation['ordinal'] - 1], $expectedExits[$invocation['ordinal'] - 1], 'missing');
                    self::assertSame(1, file_put_contents($stem . '.exit.json', '{'));
                    $this->assertAdmissionRefused($stem, $capture, $expectedArguments[$invocation['ordinal'] - 1], $expectedExits[$invocation['ordinal'] - 1], 'changed');
                } finally {
                    self::assertSame(strlen($originalReceipt), file_put_contents($stem . '.exit.json', $originalReceipt));
                }
                $this->assertAdmissionRefused($stem, $capture, ['--local', '--local'], $expectedExits[$invocation['ordinal'] - 1], 'scenario');
                $this->assertAdmissionRefused($stem, $capture, $expectedArguments[$invocation['ordinal'] - 1], 99, 'scenario');
                self::assertSame($expectedExits[$invocation['ordinal'] - 1], $receipt['exit']);
                self::assertSame(hash_file('sha256', $stem . '.coverage'), $receipt['report_sha256']);
                foreach (['stdout_sha256', 'stderr_sha256'] as $digestKey) {
                    self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $receipt[$digestKey]);
                }
                $markers = ['actual-cli-collector-finished', 'argv:' . $digest, \CdefNativeCoverageRegistration::runtimeMarker()];
                $arguments = [$stem . '.coverage', $candidate, 'cli/upgrade_database.php', $invocation['id'] . ':' . $digest,
                    \CdefCliCoverageRegistration::sources(), $markers, ['cli/upgrade_database.php']];
                $coverage = \NativeChildCoverageEvidence::load(...$arguments);
                // The collector cannot know process status or SQL outcomes.
                // Admit those only after the original parent assertions and
                // post-cleanup terminal, and bind its exact process receipt.
                $snapshot = json_decode(file_get_contents($stem . '.coverage.json'), true, 512, JSON_THROW_ON_ERROR);
                $markers[] = $registration[3];
                $markers[] = 'parent-confirmed-exit:' . $receipt['exit'] . ':receipt:' . hash_file('sha256', $stem . '.exit.json');
                \NativeChildCoverageEvidence::write($stem . '.coverage', $candidate, $snapshot, $markers);
                $arguments[5] = $markers;
                $coverage = \NativeChildCoverageEvidence::load(...$arguments);
                $requiredStatement = $case === 'current' ? '$install_cdef_reference_contract = true;'
                    : ($case === 'collector-online'
                        ? 'fwrite(STDERR, "Run schema upgrades from the primary collector; use --local only for this collector\'s own database.\\n");'
                        : 'if (!Installer::recordInstalledVersion()) {');
                $sourceLines = file($candidate . '/cli/upgrade_database.php');
                $matches = array_keys(array_map('trim', $sourceLines), $requiredStatement, true);
                self::assertCount(1, $matches, 'Required production CLI statement is unique.');
                $requiredLine = $matches[0] + 1;
                $hits = $coverage->getData()->lineCoverage()[realpath($candidate . '/cli/upgrade_database.php')][$requiredLine] ?? null;
                self::assertIsArray($hits, 'The original CLI workflow statement was not measured.');
                self::assertNotEmpty($hits);
                self::assertSame(
                    count(\CdefCliCoverageRegistration::sources()) + count($markers) + 10,
                    \NativeChildCoverageEvidence::verifyRejections(...[...$arguments, 'lib/boost.php'])
                );
                $originalEvidence = file_get_contents($stem . '.coverage.json');
                try {
                    $stale = json_decode($originalEvidence, true, 512, JSON_THROW_ON_ERROR);
                    $stale['sources']['cli/upgrade_database.php'] = str_repeat('0', 64);
                    file_put_contents($stem . '.coverage.json', json_encode($stale, JSON_THROW_ON_ERROR));
                    try {
                        \NativeChildCoverageEvidence::load(...$arguments);
                        self::fail('Stale production CLI source evidence was accepted.');
                    } catch (\RuntimeException $error) {
                        self::assertStringContainsString('source is missing or stale', $error->getMessage());
                    }
                } finally {
                    self::assertSame(strlen($originalEvidence), file_put_contents($stem . '.coverage.json', $originalEvidence));
                }
                if ($active !== null) {
                    // Map only actual measured CLI lines back to the same bytes
                    // at the canonical PHPUnit root; candidate provenance was
                    // validated before this path-only normalization.
                    self::assertSame(hash_file('sha256', $root . '/cli/upgrade_database.php'), hash_file('sha256', $candidate . '/cli/upgrade_database.php'));
                    $data = $coverage->getData();
                    $lines = $data->lineCoverage();
                    $lines[realpath($root . '/cli/upgrade_database.php')] = $lines[realpath($candidate . '/cli/upgrade_database.php')];
                    unset($lines[realpath($candidate . '/cli/upgrade_database.php')]);
                    $data->setLineCoverage($lines);
                    $coverage->setData($data);
                    $coverage->filter()->excludeFile($candidate . '/cli/upgrade_database.php');
                    $coverage->filter()->includeFile($root . '/cli/upgrade_database.php');
                    self::assertSame([realpath($root . '/cli/upgrade_database.php')], $coverage->filter()->files());
                    $active->merge($coverage);
                }
            }
            sort($ordinals);
            self::assertSame(range(1, $registration[2]), $ordinals);
        } finally {
            $filesystem->remove($directory);
        }
    }
}
