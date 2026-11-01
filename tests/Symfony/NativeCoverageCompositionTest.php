<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

require_once __DIR__ . '/NativeCoverageComposition.php';

final class NativeCoverageCompositionTest extends TestCase
{
    /** @var array<string,array<string,mixed>> */
    private static array $parts = [];
    /** @var array<string,string> */
    private static array $originalClovers = [];

    public function testOverlappingNativeMergesRetainEveryTestIdentityAsAList(): void
    {
        $root = dirname(__DIR__, 2);
        $parts = [];
        foreach (['legacy' => 'tests', 'application' => 'application'] as $part => $stack) {
            $output = tempnam(sys_get_temp_dir(), 'native-composition-overlap-');
            self::assertIsString($output);
            try {
                $process = new Process([PHP_BINARY, '-d', 'pcov.directory=' . $root . '/tests/Fixtures', '-d', 'pcov.exclude=~/(include/vendor|tests/vendor)/~',
                    $root . '/tests/Fixtures/native-coverage-composition-producer.php', $stack, $part, $output, 'overlap']);
                $process->mustRun();
                $source = file_get_contents($output);
                self::assertIsString($source);
                $raw = json_decode($source, true, 512, JSON_THROW_ON_ERROR);
                $ids = ['native composition ' . $stack, 'native overlap B ' . $stack, 'native overlap C ' . $stack];
                self::assertSame($ids, array_keys($raw['tests']));
                $shared = false;
                foreach ($raw['lines'] as $measured) {
                    foreach ($measured as $hits) {
                        if ($hits === null) continue;
                        self::assertIsArray($hits);
                        self::assertTrue(array_is_list($hits), 'Merged native hit indexes must serialize as a JSON list.');
                        if ($hits === $ids) $shared = true;
                    }
                }
                self::assertTrue($shared, 'All three actually executed native tests must retain their shared statements.');
                $parts[$part] = $raw;
            } finally {
                unlink($output);
                if (is_file($output . '.xml')) unlink($output . '.xml');
            }
        }
        $composed = self::composeInTestsStack($parts);
        self::assertSame($parts['legacy']['tests'] + $parts['application']['tests'], $composed['tests']);
    }

    public function testRealLockedLibrariesExportAndComposeTheirOriginalTestIdentities(): void
    {
        $parts = self::parts();
        self::assertStringStartsWith('12.', $parts['legacy']['stack']['version']);
        self::assertStringStartsWith('10.', $parts['application']['stack']['version']);
        $root = dirname(__DIR__, 2);
        $coverage = self::composeInTestsStack($parts);
        self::assertSame(['native composition tests', 'native composition application'], array_keys($coverage['tests']));
        self::assertSame($parts['legacy']['tests'] + $parts['application']['tests'], $coverage['tests']);
        self::assertCount(3, $coverage['filter']);
        $lines = $coverage['lines'][$root . '/tests/Fixtures/native-coverage-composition-target.php'];
        self::assertContains(['native composition tests'], $lines);
        self::assertContains(['native composition application'], $lines);
        self::assertContains(['native composition tests', 'native composition application'], $lines);
        self::assertSame([], $parts['legacy']['functions']);
        self::assertSame([], $parts['application']['functions']);
    }

    public function testMissingStaleConflictingAndUnsupportedEvidenceIsRefused(): void
    {
        $parts = self::parts();
        $cases = ['missing producer' => array_intersect_key($parts, ['legacy' => true])];
        foreach ([['source', ['stale', 'stale']], ['part', 'foreign'], ['version', true], ['locks', []], ['tools', []], ['stack', []],
            ['functions', ['unsupported branch data']], ['filter', []], ['lines', []], ['tests', []], ['sources', []]] as [$field, $value]) {
            $bad = $parts;
            $bad['application'][$field] = $value;
            $cases[$field] = $bad;
        }
        $file = 'tests/Fixtures/native-coverage-composition-target.php';
        $bad = $parts;
        $bad['application']['sources'][$file] = str_repeat('0', 64);
        $cases['stale source bytes'] = $bad;
        $bad = $parts;
        $bad['application']['tests']['native composition tests'] = ['size' => 'unknown', 'status' => 'conflict', 'time' => 0];
        $cases['conflicting original test identity'] = $bad;
        $bad = $parts;
        $bad['application']['lines'][$file][1] = ['unrecorded test'];
        $cases['unbound hit'] = $bad;
        $bad = $parts;
        $bad['application']['lines'][$file][0] = [];
        $cases['invalid line'] = $bad;
        $bad = $parts;
        $bad['application']['lines'][$file][1] = [2 => 'native composition application'];
        $cases['non-list hit map'] = $bad;
        $bad = $parts;
        foreach ($bad['application']['lines'] as &$measured) {
            foreach ($measured as &$hits) $hits = [];
            unset($hits);
        }
        unset($measured);
        $cases['no executed application tests'] = $bad;
        foreach ($cases as $name => $bad) {
            $result = self::composeInTestsStack($bad, false);
            self::assertNotSame(0, $result['exit'], $name);
            self::assertStringContainsString('RuntimeException', $result['error'], $name);
        }
    }

    public function testTheActualCommandPublishesCloverAndRefusesMissingOrStaleSidecarsBeforeReplacement(): void
    {
        $root = dirname(__DIR__, 2);
        $directory = sys_get_temp_dir() . '/native-composition-command-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($directory, 0700));
        try {
            $parts = self::parts();
            foreach ($parts as $part => $raw) self::assertNotFalse(file_put_contents($directory . '/' . $part . '.json', json_encode($raw, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)));
            $command = [PHP_BINARY, $root . '/tests/Symfony/compose_native_coverage.php', $directory . '/legacy.json', $directory . '/application.json', $directory . '/coverage.xml'];
            $process = new Process($command);
            $process->mustRun();
            self::assertStringContainsString('before uncovered-file expansion', $process->getOutput());
            $xml = simplexml_load_file($directory . '/coverage.xml');
            self::assertNotFalse($xml);
            self::assertGreaterThan(0, (int) $xml->project->metrics['coveredstatements']);
            $files = $xml->xpath('//file');
            self::assertIsArray($files);
            self::assertCount(3, $files);
            $retained = $xml->xpath('//file[@name="' . $root . '/tests/Fixtures/native-coverage-stack-target.php"]/metrics');
            self::assertIsArray($retained);
            self::assertCount(1, $retained);
            self::assertSame(0, (int) $retained[0]['coveredstatements']);
            self::assertGreaterThan(0, (int) $retained[0]['statements']);
            $applicationFile = $root . '/tests/Fixtures/native-coverage-composition-application.php';
            $originalUnion = [];
            foreach (self::$originalClovers as $clover) {
                $originalXml = simplexml_load_string($clover);
                self::assertNotFalse($originalXml);
                foreach ($originalXml->xpath('//file[@name="' . $applicationFile . '"]/line[@type="stmt"]') as $line) {
                    $number = (int) $line['num'];
                    $originalUnion[$number] = max($originalUnion[$number] ?? 0, (int) $line['count']);
                }
            }
            $composedLines = [];
            foreach ($xml->xpath('//file[@name="' . $applicationFile . '"]/line[@type="stmt"]') as $line) $composedLines[(int) $line['num']] = (int) $line['count'];
            self::assertNotEmpty($composedLines);
            self::assertNotContains(0, $composedLines, 'All physical application-only statements were executed.');
            $structuralMisses = array_filter(array_diff_key($originalUnion, $composedLines), static fn(int $count): bool => $count === 0);
            self::assertNotEmpty($structuralMisses, 'Separate static expansion must reproduce the coverage10/12 structural-line discrepancy.');
            self::assertArrayNotHasKey('tests/Fixtures/native-coverage-composition-application.php', $parts['legacy']['lines'], 'The legacy producer must not invent execution of this file.');
            $original = file_get_contents($directory . '/coverage.xml');
            self::assertIsString($original);
            $parts['application']['source'] = ['stale', 'stale'];
            self::assertNotFalse(file_put_contents($directory . '/application.json', json_encode($parts['application'], JSON_THROW_ON_ERROR)));
            $process->run();
            self::assertFalse($process->isSuccessful());
            self::assertStringContainsString('missing or stale', $process->getOutput() . $process->getErrorOutput());
            self::assertSame($original, file_get_contents($directory . '/coverage.xml'));
            self::assertTrue(unlink($directory . '/application.json'));
            $process->run();
            self::assertFalse($process->isSuccessful());
            self::assertStringContainsString('sidecar is missing', $process->getOutput() . $process->getErrorOutput());
            self::assertSame($original, file_get_contents($directory . '/coverage.xml'));
            self::assertNotFalse(file_put_contents($directory . '/application.json', json_encode(self::parts()['application'], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)));
            foreach (['missing', 'not-directory'] as $destination) {
                if ($destination === 'not-directory') self::assertSame(5, file_put_contents($directory . '/' . $destination, 'owned'));
                $command[4] = $directory . '/' . $destination . '/coverage.xml';
                $refused = new Process($command);
                $refused->run();
                self::assertFalse($refused->isSuccessful());
                self::assertStringContainsString('destination directory is unavailable', $refused->getOutput() . $refused->getErrorOutput());
                self::assertSame($original, file_get_contents($directory . '/coverage.xml'));
                self::assertSame([], glob($directory . '/native-composed-*'));
            }
            self::assertDirectoryDoesNotExist($directory . '/missing');
            self::assertSame('owned', file_get_contents($directory . '/not-directory'));
            self::assertTrue(chmod($directory, 0500));
            try {
                // Root may retain write permission despite mode0500. Assert
                // denial only when the real filesystem reports it unwritable.
                clearstatcache(true, $directory);
                if (!is_writable($directory)) {
                    $command[4] = $directory . '/coverage.xml';
                    $refused = new Process($command);
                    $refused->run();
                    self::assertFalse($refused->isSuccessful());
                    self::assertStringContainsString('destination directory is unavailable', $refused->getOutput() . $refused->getErrorOutput());
                    self::assertSame($original, file_get_contents($directory . '/coverage.xml'));
                    self::assertSame([], glob($directory . '/native-composed-*'));
                }
            } finally {
                self::assertTrue(chmod($directory, 0700));
            }
        } finally {
            foreach (glob($directory . '/*') ?: [] as $owned) unlink($owned);
            rmdir($directory);
        }
    }

    /** @return array<string,array<string,mixed>> */
    private static function parts(): array
    {
        if (self::$parts !== []) return self::$parts;
        $root = dirname(__DIR__, 2);
        foreach (['legacy' => 'tests', 'application' => 'application'] as $part => $stack) {
            $output = tempnam(sys_get_temp_dir(), 'native-composition-fixture-');
            self::assertIsString($output);
            try {
                $process = new Process([PHP_BINARY, '-d', 'pcov.directory=' . $root . '/tests/Fixtures', '-d', 'pcov.exclude=~/(include/vendor|tests/vendor)/~',
                    $root . '/tests/Fixtures/native-coverage-composition-producer.php', $stack, $part, $output]);
                $process->mustRun();
                $raw = file_get_contents($output);
                self::assertIsString($raw);
                self::$parts[$part] = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                $clover = file_get_contents($output . '.xml');
                self::assertIsString($clover);
                self::$originalClovers[$part] = $clover;
            } finally {
                unlink($output);
                if (is_file($output . '.xml')) unlink($output . '.xml');
            }
        }
        return self::$parts;
    }

    /** @param array<string,array<string,mixed>> $parts @return array<string,mixed> */
    private static function composeInTestsStack(array $parts, bool $success = true): array
    {
        $root = dirname(__DIR__, 2);
        $input = tempnam(sys_get_temp_dir(), 'native-composition-input-');
        self::assertIsString($input);
        try {
            self::assertNotFalse(file_put_contents($input, json_encode($parts, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)));
            $script = 'require $argv[1]."/tests/vendor/autoload.php"; require $argv[1]."/tests/Symfony/NativeCoverageComposition.php"; '
                . '$c=\\Kadupul\\Tests\\NativeCoverageComposition::compose($argv[1],json_decode(file_get_contents($argv[2]),true,512,JSON_THROW_ON_ERROR)); '
                . 'echo json_encode(["tests"=>$c->getTests(),"filter"=>$c->filter()->files(),"lines"=>$c->getData(true)->lineCoverage()],JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION);';
            $process = new Process([PHP_BINARY, '-r', $script, $root, $input]);
            $process->run();
            if (!$success) return ['exit' => $process->getExitCode(), 'error' => $process->getErrorOutput() . $process->getOutput()];
            self::assertTrue($process->isSuccessful(), $process->getErrorOutput() . $process->getOutput());
            return json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        } finally {
            unlink($input);
        }
    }
}
