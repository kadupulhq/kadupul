<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

require_once __DIR__ . '/../Helpers/PestCodeCoverageCompatibility.php';
require_once __DIR__ . '/../Helpers/NativeChildCoverageEvidence.php';
require_once __DIR__ . '/../Helpers/PresentationPageEvidence.php';

use PHPUnit\Framework\TestCase;

final class PresentationPageNativeCoverageTest extends TestCase
{
    use \PestCodeCoverageCompatibility;

    #[\PHPUnit\Framework\Attributes\DataProvider('pages')]
    public function testNativePageRetainsRecordedFormControlsLabelsLinksAndIcons(string $name, array $scenario): void
    {
        $root = dirname(__DIR__, 2);
        $directory = sys_get_temp_dir() . '/presentation-page-native-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($directory, 0700));
        $coverage = method_exists($this, 'getTestResultObject') ? $this->getTestResultObject()->getCodeCoverage() : null;
        $command = array(PHP_BINARY, '-d', 'error_reporting=-1', '-d', 'display_errors=stderr', '-d', 'auto_prepend_file=',
            '-d', 'date.timezone=UTC', '-d', 'session.gc_maxlifetime=1440', '-d', 'upload_max_filesize=2M',
            '-d', 'post_max_size=8M', '-d', 'memory_limit=512M', '-d', 'max_execution_time=0', '-d', 'default_charset=UTF-8',
            '-d', 'pcov.directory=/', '-d', 'pcov.exclude=~/(include/vendor|tests)/~',
            $root . '/tests/Fixtures/presentation-pages-native.php', $root, $directory, $name);
        try {
            $environment = getenv();
            $environment['PRESENTATION_PAGE_COVERAGE'] = $coverage === null ? '0' : '1';
            $process = proc_open($command, array(0 => array('file', '/dev/null', 'r'), 1 => array('pipe', 'w'), 2 => array('file', $directory . '/stderr', 'w')), $pipes, $root, $environment);
            self::assertIsResource($process);
            $output = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            $status = proc_close($process);
            $errors = file_get_contents($directory . '/stderr');
            self::assertSame(0, $status, $errors . $output);
            self::assertSame('', $errors);
            $state = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
            $golden = file_get_contents($root . '/tests/Golden/forms/pages/' . $name . '.html');
            self::assertNotFalse($golden);
            $expected = PresentationPageEvidence::contracts($golden);
            self::assertNotEmpty($expected['controls'], 'Native page must have a recorded form/control oracle');
            self::assertSame($expected, PresentationPageEvidence::contracts($state['html']));
            self::assertSame(array(), $state['diagnostics']);
            if ($coverage !== null) {
                $sources = PresentationPageEvidence::sources();
                $markers = PresentationPageEvidence::markers($name);
                $hits = array($scenario['page'], 'include/global_settings.php', 'lib/html_form.php');
                $report = $directory . '/page.coverage';
                $child = NativeChildCoverageEvidence::load($report, $root, 'tests/Fixtures/presentation-pages-native.php', $name, $sources, $markers, $hits);
                if ($name === 'settings-visual') {
                    self::assertSame(count($sources) + count($markers) + 10, NativeChildCoverageEvidence::verifyRejections($report, $root, 'tests/Fixtures/presentation-pages-native.php', $name, $sources, $markers, $hits, 'cli/refresh_csrf.php'));
                }
                $coverage->merge($child);
            }
        } finally {
            $entries = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($entries as $entry) {
                $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
            rmdir($directory);
        }
    }

    public function testClockPatchedSourceCannotQualifyAsNativePageEvidence(): void
    {
        $root = dirname(__DIR__, 2);
        $directory = sys_get_temp_dir() . '/presentation-clock-control-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($directory, 0700));
        try {
            $goldenSource = file_get_contents($root . '/tests/Fixtures/legacy-form-golden.php');
            $producerSource = file_get_contents($root . '/tests/Fixtures/presentation-pages-native.php');
            self::assertNotFalse($goldenSource);
            self::assertNotFalse($producerSource);
            $goldenSource = str_replace("if (!defined('PRESENTATION_PAGE_NATIVE'))", 'if (true)', $goldenSource, $changedMode);
            self::assertSame(1, $changedMode);
            $producerSource = str_replace("require \$root . '/tests/Fixtures/legacy-form-golden.php';", 'require ' . var_export($directory . '/clock-patched.php', true) . ';', $producerSource, $changedInclude);
            self::assertSame(1, $changedInclude);
            self::assertSame(strlen($goldenSource), file_put_contents($directory . '/clock-patched.php', $goldenSource));
            self::assertSame(strlen($producerSource), file_put_contents($directory . '/producer.php', $producerSource));
            $environment = getenv();
            $environment['PRESENTATION_PAGE_COVERAGE'] = '0';
            $process = proc_open(
                array(PHP_BINARY, '-d', 'auto_prepend_file=', '-d', 'display_errors=stderr', '-d', 'date.timezone=UTC', $directory . '/producer.php', $root, $directory, 'settings-visual'),
                array(0 => array('file', '/dev/null', 'r'), 1 => array('file', $directory . '/stdout', 'w'), 2 => array('file', $directory . '/stderr', 'w')),
                $pipes,
                $root,
                $environment
            );
            self::assertIsResource($process);
            self::assertSame(255, proc_close($process));
            self::assertStringContainsString('Clock-patched sources cannot establish native coverage', file_get_contents($directory . '/stderr'));
            self::assertSame(array(), glob($directory . '/*.coverage'));
        } finally {
            $entries = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($entries as $entry) {
                $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
            rmdir($directory);
        }
    }

    public static function pages(): array
    {
        $scenarios = require dirname(__DIR__) . '/Fixtures/legacy-form-golden-scenarios.php';
        $cases = array();
        foreach ($scenarios['pages'] as $name => $scenario) {
            // The original manager edit scenario emits no form. Its invocation
            // remains covered by LegacyFormGoldenTest, outside this DOM contract.
            if ($name !== 'managers-edit') {
                $cases[$name] = array($name, $scenario);
            }
        }
        if ($cases === array()) {
            throw new RuntimeException('Native presentation page discovery is empty');
        }
        return $cases;
    }

}
