<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

require_once __DIR__ . '/../Helpers/PestCodeCoverageCompatibility.php';
require_once __DIR__ . '/../Helpers/NativeChildCoverageEvidence.php';
require_once __DIR__ . '/../Helpers/PresentationSettingsEvidence.php';

use PHPUnit\Framework\TestCase;

final class PresentationSettingsNativeCoverageTest extends TestCase
{
    use \PestCodeCoverageCompatibility;

    #[\PHPUnit\Framework\Attributes\DataProvider('mutations')]
    public function testActualSettingsSavePersistsSelectedFieldsAndPreservesOmittedAndInvalidValues(string $case): void
    {
        $root = dirname(__DIR__, 2);
        $directory = sys_get_temp_dir() . '/presentation-settings-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($directory, 0700));
        $coverage = method_exists($this, 'getTestResultObject') ? $this->getTestResultObject()->getCodeCoverage() : null;
        try {
            $environment = getenv();
            $environment['PRESENTATION_SETTINGS_COVERAGE'] = $coverage === null ? '0' : '1';
            $process = proc_open(
                array(PHP_BINARY, '-d', 'auto_prepend_file=', '-d', 'display_errors=stderr',
                    '-d', 'date.timezone=UTC', '-d', 'session.gc_maxlifetime=1440', '-d', 'upload_max_filesize=2M',
                    '-d', 'post_max_size=8M', '-d', 'memory_limit=512M', '-d', 'max_execution_time=0', '-d', 'default_charset=UTF-8',
                    '-d', 'pcov.directory=/', '-d', 'pcov.exclude=~/(include/vendor|tests)/~',
                    $root . '/tests/Fixtures/presentation-settings-native.php', $root, $directory, $case),
                array(0 => array('file', '/dev/null', 'r'), 1 => array('file', $directory . '/stdout', 'w'), 2 => array('file', $directory . '/stderr', 'w')),
                $pipes,
                $root,
                $environment
            );
            self::assertIsResource($process);
            $status = proc_close($process);
            $errors = file_get_contents($directory . '/stderr');
            self::assertSame(0, $status, $errors);
            self::assertSame('', $errors);
            $result = json_decode(file_get_contents($directory . '/outcome.json'), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame($case, $result['case']);
            self::assertSame(PresentationSettingsEvidence::tables(), array_keys($result['after']));
            self::assertSame(array(), $result['after']['poller']);
            if ($coverage !== null) {
                $sources = PresentationSettingsEvidence::sources();
                $markers = PresentationSettingsEvidence::markers($case);
                $page = 'settings.php';
                $hits = array($page, 'lib/database.php');
                $report = $directory . '/settings.coverage';
                $child = NativeChildCoverageEvidence::load($report, $root, 'tests/Fixtures/presentation-settings-native.php', $case, $sources, $markers, $hits);
                if ($case === 'general-selected') {
                    self::assertSame(count($sources) + count($markers) + 10, NativeChildCoverageEvidence::verifyRejections(
                        $report,
                        $root,
                        'tests/Fixtures/presentation-settings-native.php',
                        $case,
                        $sources,
                        $markers,
                        $hits,
                        'cli/refresh_csrf.php'
                    ));
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

    public static function mutations(): array
    {
        $cases = array();
        foreach (array('general-selected', 'general-empty', 'general-multi-scalar', 'path-valid', 'path-invalid', 'auth-password-retain', 'auth-password-change', 'auth-password-mismatch') as $case) {
            $cases[$case] = array($case);
        }

        return $cases;
    }

    public function testIncompleteOrWarningSettingsSaveCannotProduceCompletedEvidence(): void
    {
        $root = dirname(__DIR__, 2);
        $source = file_get_contents($root . '/tests/Fixtures/presentation-settings-native.php');
        self::assertIsString($source);
        self::assertNotSame('', $source);
        foreach (array('omitted-save', 'captured-warning') as $control) {
            $case = 'general-selected';
            $call = $control === 'omitted-save' ? 'include $root . \'/settings.php\';'
                : 'if (($GLOBALS[\'diagnostics\'] ?? array()) !== array()) {';
            $directory = sys_get_temp_dir() . '/presentation-settings-control-' . bin2hex(random_bytes(8));
            self::assertTrue(mkdir($directory, 0700));
            try {
                $replacement = $control === 'omitted-save' ? '/* Actual settings save omitted for outcome rejection control. */'
                    : "trigger_error('native settings diagnostic control', E_USER_WARNING); " . $call;
                $altered = str_replace($call, $replacement, $source, $changed);
                self::assertSame(1, $changed);
                self::assertSame(strlen($altered), file_put_contents($directory . '/producer.php', $altered));
                $environment = getenv();
                $environment['PRESENTATION_SETTINGS_COVERAGE'] = '0';
                $process = proc_open(
                    array(PHP_BINARY, '-d', 'auto_prepend_file=', '-d', 'display_errors=stderr',
                        '-d', 'date.timezone=UTC', $directory . '/producer.php', $root, $directory, $case),
                    array(0 => array('file', '/dev/null', 'r'), 1 => array('file', $directory . '/stdout', 'w'), 2 => array('file', $directory . '/stderr', 'w')),
                    $pipes,
                    $root,
                    $environment
                );
                self::assertIsResource($process);
                self::assertSame(255, proc_close($process));
                self::assertStringContainsString('RuntimeException', file_get_contents($directory . '/stderr'));
                self::assertFileDoesNotExist($directory . '/outcome.json');
                self::assertSame(array(), glob($directory . '/*.coverage'));
            } finally {
                $entries = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
                foreach ($entries as $entry) {
                    $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
                }
                rmdir($directory);
            }
        }
    }
}
