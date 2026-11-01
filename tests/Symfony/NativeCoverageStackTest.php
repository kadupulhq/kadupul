<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;

require_once __DIR__ . '/NativeCoverageStack.php';
require_once dirname(__DIR__) . '/Helpers/NativeChildCoverageEvidence.php';

final class NativeCoverageStackTest extends TestCase
{
    private const string PRODUCER = 'tests/Fixtures/native-coverage-stack-prepend.php';
    private const string TARGET = 'tests/Fixtures/native-coverage-stack-target.php';
    private const string UNEXECUTED = 'src/Alerting/Domain/AdministratorRecipient.php';
    private const array SOURCES = ['composer.lock', 'tests/composer.lock', 'tests/Symfony/NativeCoverageStack.php', 'tests/Symfony/NativeCoverageStackTest.php',
        'tests/Helpers/NativeChildCoverageEvidence.php', 'tests/Fixtures/rrd-process-coverage.php', self::TARGET, self::UNEXECUTED];
    private ?string $directory = null;

    protected function tearDown(): void
    {
        if ($this->directory !== null) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) {
                if ($file->isDir() && !$file->isLink()) rmdir($file->getPathname());
                else unlink($file->getPathname());
            }
            rmdir($this->directory);
        }
    }

    public function testTheActiveParentIsMatchedToACanonicalInstalledLibraryAndVersion(): void
    {
        $root = dirname(__DIR__, 2);
        $stack = NativeCoverageStack::capture($root);
        self::assertContains($stack['stack'], ['application', 'tests']);
        self::assertNotSame('', $stack['version']);
        self::assertSame(1, preg_match('/\A[a-f0-9]{64}\z/D', $stack['class_sha256']));
        $expected = getenv('KADUPUL_EXPECT_COVERAGE_STACK');
        if ($expected !== false) self::assertSame($expected, $stack['stack']);
        if (getenv('KADUPUL_EXPECT_NODEV_APPLICATION') === '1') self::assertFileDoesNotExist($root . '/include/vendor/phpunit/php-code-coverage/src/CodeCoverage.php');
        self::assertSame($root . ($stack['stack'] === 'application' ? '/include/vendor' : '/tests/vendor'), NativeCoverageStack::bootstrap($root, $stack));
    }

    public function testUnknownStacksArbitraryPathsAndStaleIdentitiesAreRefusedBeforeAnyAutoload(): void
    {
        $root = dirname(__DIR__, 2);
        $stack = NativeCoverageStack::capture($root);
        $directory = $this->directory();
        $arbitrary = $directory . '/arbitrary.php';
        self::assertNotFalse(file_put_contents($arbitrary, '<?php file_put_contents(__DIR__ . "/executed", "unexpected");'));
        foreach ([[], array_replace($stack, ['stack' => $arbitrary]), array_replace($stack, ['stack' => 'unknown']),
            array_replace($stack, ['version' => 'stale']), array_replace($stack, ['class_sha256' => str_repeat('0', 64)]),
            $stack + ['autoload' => $arbitrary]] as $description) {
            try {
                NativeCoverageStack::bootstrap($root, $description);
                self::fail('An untrusted or stale coverage handoff was accepted.');
            } catch (\RuntimeException) {
                self::assertFileDoesNotExist($directory . '/executed');
            }
        }
    }

    public function testAParentLibraryFromAnotherProjectRootCannotBeRebound(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('outside the installed test stacks');
        NativeCoverageStack::capture($this->directory());
    }

    public function testAReboundRootCannotRunItsAutoloaderBeforeTheLoadedClassIsVerified(): void
    {
        $root = dirname(__DIR__, 2);
        $stack = NativeCoverageStack::capture($root);
        $directory = $this->directory();
        $vendor = $directory . ($stack['stack'] === 'application' ? '/include/vendor' : '/tests/vendor');
        self::assertTrue(mkdir($vendor . '/phpunit/php-code-coverage/src', 0700, true));
        self::assertTrue(mkdir($vendor . '/composer', 0700));
        $class = (new \ReflectionClass(\SebastianBergmann\CodeCoverage\CodeCoverage::class))->getFileName();
        self::assertIsString($class);
        self::assertTrue(copy($class, $vendor . '/phpunit/php-code-coverage/src/CodeCoverage.php'));
        self::assertNotFalse(file_put_contents($vendor . '/composer/installed.json', json_encode(['packages' => [
            ['name' => 'phpunit/php-code-coverage', 'version_normalized' => $stack['version']],
        ]], JSON_THROW_ON_ERROR)));
        self::assertNotFalse(file_put_contents($vendor . '/autoload.php', '<?php file_put_contents(' . var_export($directory . '/executed', true) . ', "unexpected");'));
        try {
            NativeCoverageStack::bootstrap($directory, $stack);
            self::fail('A different project rebound the active parent.');
        } catch (\RuntimeException) {
            self::assertFileDoesNotExist($directory . '/executed');
            self::assertSame($stack, NativeCoverageStack::capture($root));
        }
    }

    public static function copyModes(): iterable
    {
        yield 'executed primary copy' => [false];
        yield 'executed primary and unexecuted additional alias' => [true];
    }

    #[DataProvider('copyModes')]
    public function testActualCollectorSerializesAndMergesOnlyTheVersionBoundParentStack(bool $additionalCopy): void
    {
        if (!extension_loaded('pcov')) $this->markTestSkipped('The collector round-trip requires the coverage job\'s PCOV extension.');
        $root = dirname(__DIR__, 2);
        $stack = NativeCoverageStack::capture($root);
        $directory = $this->directory();
        $copy = $directory . '/target.php';
        self::assertTrue(copy($root . '/' . self::TARGET, $copy));
        $unused = $directory . '/never-included.php';
        $unexecuted = $directory . '/never-recipient.php';
        if ($additionalCopy) {
            self::assertTrue(copy($root . '/' . self::TARGET, $unused));
            self::assertTrue(copy($root . '/' . self::UNEXECUTED, $unexecuted));
        }
        $scenario = hash('sha256', json_encode([$stack, $additionalCopy], JSON_THROW_ON_ERROR));
        $probe = new Process([PHP_BINARY, '-r', 'exit(extension_loaded("pcov") ? 0 : 1);']);
        $probe->run();
        self::assertContains($probe->getExitCode(), [0, 1]);
        $extension = $probe->isSuccessful() ? [] : ['-d', 'extension=pcov.so'];
        $child = new Process([PHP_BINARY, ...$extension, '-d', 'pcov.enabled=1', '-d', 'pcov.directory=/',
            '-d', 'pcov.exclude=~/(include/vendor|tests)/~', '-d', 'auto_prepend_file=' . $root . '/' . self::PRODUCER, $copy], null, [
                'KADUPUL_NATIVE_COVERAGE_STACK' => json_encode($stack, JSON_THROW_ON_ERROR),
                'KADUPUL_NATIVE_COVERAGE_DIRECTORY' => $directory, 'KADUPUL_NATIVE_COVERAGE_COPY' => $copy, 'KADUPUL_NATIVE_COVERAGE_SCENARIO' => $scenario,
                'KADUPUL_NATIVE_UNUSED_COVERAGE_COPY' => $additionalCopy ? $unused : false,
                'KADUPUL_NATIVE_UNUSED_PRODUCTION_COPY' => $additionalCopy ? $unexecuted : false,
            ], timeout: 20);
        $child->mustRun();
        self::assertSame('42', $child->getOutput());
        self::assertSame('', $child->getErrorOutput());
        $reports = glob($directory . '/*.coverage');
        self::assertIsArray($reports);
        self::assertCount(1, $reports);
        $markers = ['native-parent-stack-probe-terminated'];
        $coverage = \NativeChildCoverageEvidence::load($reports[0], $root, self::PRODUCER, $scenario, self::SOURCES, $markers, [self::TARGET], $stack);
        self::assertInstanceOf(
            \SebastianBergmann\CodeCoverage\CodeCoverage::class,
            \NativeChildCoverageEvidence::load($reports[0], $root, self::PRODUCER, $scenario, self::SOURCES, $markers, [self::TARGET])
        );
        self::assertSame(
            count(self::SOURCES) + count($markers) + 15,
            \NativeChildCoverageEvidence::verifyRejections($reports[0], $root, self::PRODUCER, $scenario, self::SOURCES, $markers, [self::TARGET], self::TARGET, $stack)
        );
        self::assertNotEmpty($coverage->getData(true)->lineCoverage()[realpath($root . '/' . self::TARGET)]);
        self::assertCount($additionalCopy ? 2 : 1, $coverage->filter()->files());
        self::assertContains(realpath($root . '/' . self::TARGET), $coverage->filter()->files());
        if ($additionalCopy) {
            self::assertArrayNotHasKey(realpath($unused), $coverage->getData(true)->lineCoverage());
            self::assertArrayNotHasKey(realpath($unexecuted), $coverage->getData(true)->lineCoverage());
            $canonicalUnexecuted = realpath($root . '/' . self::UNEXECUTED);
            self::assertContains($canonicalUnexecuted, $coverage->filter()->files());
            self::assertArrayNotHasKey($canonicalUnexecuted, $coverage->getData(true)->lineCoverage());
            $final = $coverage->getData()->lineCoverage();
            self::assertArrayHasKey($canonicalUnexecuted, $final);
            self::assertNotEmpty($final[$canonicalUnexecuted]);
            self::assertSame([], array_filter($final[$canonicalUnexecuted], static fn($hits): bool => is_array($hits) && $hits !== []));
        }
        if (\PHPUnit\Runner\CodeCoverage::instance()->isActive()) \PHPUnit\Runner\CodeCoverage::instance()->codeCoverage()->merge($coverage);
        $original = file_get_contents($reports[0] . '.json');
        self::assertIsString($original);
        $evidence = json_decode($original, true, 512, JSON_THROW_ON_ERROR);
        $evidence['coverage_stack']['version'] = 'foreign';
        self::assertNotFalse(file_put_contents($reports[0], 'invalid serialized data'));
        $evidence['report'] = hash_file('sha256', $reports[0]);
        self::assertNotFalse(file_put_contents($reports[0] . '.json', json_encode($evidence, JSON_THROW_ON_ERROR)));
        foreach ([$stack, null] as $expectedStack) {
            try {
                \NativeChildCoverageEvidence::load($reports[0], $root, self::PRODUCER, $scenario, self::SOURCES, $markers, [self::TARGET], $expectedStack);
                self::fail('A foreign stack reached deserialization.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('coverage stack', $error->getMessage());
            }
        }
    }

    private function directory(): string
    {
        if ($this->directory === null) {
            $directory = (string) realpath(sys_get_temp_dir()) . '/native-coverage-stack-' . bin2hex(random_bytes(8));
            self::assertTrue(mkdir($directory, 0700));
            $this->directory = $directory;
        }
        return $this->directory;
    }
}
