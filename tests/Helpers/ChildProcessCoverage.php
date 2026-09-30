<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/*
 * Many authentication tests run the shipped files in a `php -r` child so each
 * case starts with a clean set of functions and globals. PCOV only measures
 * the process it runs in, so when the parent run collects coverage the child
 * starts tests/Fixtures/rrd-process-coverage.php and the parent merges what
 * it recorded into the current test.
 */

use Pest\TestSuite;

if (!function_exists('child_coverage_command')) {
    /**
     * @param list<string> $command PHP_BINARY, options, '-r', code, arguments
     * @param string|null $directory set to the directory to collect, or null
     *
     * @return list<string>
     */
    function child_coverage_command(array $command, ?string &$directory): array
    {
        $directory = null;
        $test = TestSuite::getInstance()->test;

        if ($test === null || $test->getTestResultObject()->getCodeCoverage() === null) {
            return $command;
        }

        $code = array_search('-r', $command, true);

        if ($code === false) {
            throw new InvalidArgumentException('child coverage needs a php -r command');
        }

        $root = dirname(__DIR__, 2);
        $directory = sys_get_temp_dir() . '/child-coverage-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);

        // Pest 1 declares implicitly nullable parameters, which PHP 8.4 reports
        // as deprecated; a child that sets E_ALL must not see that on stderr.
        $command[$code + 1] = 'define("AUTH_HARDENING_TEST_COVERAGE", true);'
            . 'define("RRD_TEST_COVERAGE_DIRECTORY", ' . var_export($directory, true) . ');'
            . '$child_coverage_reporting = error_reporting(error_reporting() & ~E_DEPRECATED);'
            . 'require ' . var_export($root . '/tests/Fixtures/rrd-process-coverage.php', true) . ';'
            . 'error_reporting($child_coverage_reporting);'
            . 'unset($child_coverage_reporting);'
            . $command[$code + 1];
        array_splice($command, 1, 0, array('-d', 'pcov.directory=' . $root, '-d', 'pcov.exclude=~/(include/vendor|tests)/~'));

        return $command;
    }

    function child_coverage_collect(?string $directory): void
    {
        if ($directory === null) {
            return;
        }

        $coverage = TestSuite::getInstance()->test->getTestResultObject()->getCodeCoverage();

        foreach (glob($directory . '/*.coverage') ?: array() as $file) {
            $coverage->merge(unserialize(file_get_contents($file)));
            unlink($file);
        }

        rmdir($directory);
    }
}
