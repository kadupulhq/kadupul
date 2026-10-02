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

require_once __DIR__ . '/NativeChildCoverageEvidence.php';

if (!function_exists('child_coverage_command')) {
    /**
     * @param list<string> $command PHP_BINARY, options, '-r', code, arguments
     * @param string|null $directory set to the directory to collect, or null
     *
     * @return list<string>
     */
    function child_coverage_command(array $command, ?string &$directory, array $registration): array
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

        $registration['scenario'] = hash('sha256', serialize(array($registration['scenario'], $command)));
        $GLOBALS['child_coverage_registrations'][$directory] = $registration;
        // Hash in the child before executing production; the parent independently
        // validates its own producer/source/marker registration before importing.
        $evidence = '$GLOBALS["nativeChildCoverageSnapshot"] = NativeChildCoverageEvidence::snapshot('
            . var_export($root, true) . ', ' . var_export($registration['producer'], true) . ', '
            . var_export($registration['scenario'], true) . ', ' . var_export($registration['sources'], true) . ');';

        // Pest 1 declares implicitly nullable parameters, which PHP 8.4 reports
        // as deprecated; a child that sets E_ALL must not see that on stderr.
        $command[$code + 1] = 'define("AUTH_HARDENING_TEST_COVERAGE", true);'
            . 'define("RRD_TEST_COVERAGE_DIRECTORY", ' . var_export($directory, true) . ');'
            . ($registration['collectorPrelude'] ?? '')
            . '$child_coverage_reporting = error_reporting(error_reporting() & ~E_DEPRECATED);'
            . 'require_once ' . var_export(__DIR__ . '/NativeChildCoverageEvidence.php', true) . ';'
            . $evidence
            . 'require ' . var_export($root . '/tests/Fixtures/rrd-process-coverage.php', true) . ';'
            . 'error_reporting($child_coverage_reporting);'
            . 'unset($child_coverage_reporting);'
            . $command[$code + 1];
        array_splice($command, 1, 0, array('-d', 'auto_prepend_file=', '-d', 'pcov.directory=' . $root, '-d', 'pcov.exclude=~/(include/vendor|tests)/~'));

        return $command;
    }

    function child_coverage_collect(?string $directory): void
    {
        if ($directory === null) {
            return;
        }

        $reports = glob($directory . '/*.coverage');
        $registration = $GLOBALS['child_coverage_registrations'][$directory] ?? null;
        if ($reports === false || count($reports) !== 1 || $registration === null) {
            throw new RuntimeException('Expected exactly one registered native child coverage report.');
        }
        $root = dirname(__DIR__, 2);
        $report = $reports[0];
        $arguments = array($report, $root, $registration['producer'], $registration['scenario'], $registration['sources'], $registration['markers'], $registration['hits']);
        $measured = NativeChildCoverageEvidence::load(...$arguments);
        $probeKey = $registration['producer'] . ':' . $registration['kind'];
        if (!isset($GLOBALS['child_coverage_negative_probes'][$probeKey])) {
            $count = NativeChildCoverageEvidence::verifyRejections(...array_merge($arguments, array('lib/boost.php')));
            if ($count !== count($registration['sources']) + count($registration['markers']) + 10) {
                throw new RuntimeException('Native child evidence omission probes were incomplete.');
            }
            $GLOBALS['child_coverage_negative_probes'][$probeKey] = $count;
        }
        TestSuite::getInstance()->test->getTestResultObject()->getCodeCoverage()->merge($measured);
        unlink($report . '.json');
        unlink($report);
        unset($GLOBALS['child_coverage_registrations'][$directory]);
        rmdir($directory);
    }

    /** Explicit scenario/worker registration shared by the two process boundaries. */
    function child_coverage_registration(string $producer, string $kind, array $scenario, array $markers, array $hits, array $workers = array()): array
    {
        $root = dirname(__DIR__, 2);
        $canonicalProducer = realpath($producer);
        if ($canonicalProducer === false || !str_starts_with($canonicalProducer, realpath($root) . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Native child producer must belong to the verified source root.');
        }
        $producer = str_replace(DIRECTORY_SEPARATOR, '/', substr($canonicalProducer, strlen(realpath($root)) + 1));
        $sources = array(
            'tests/Helpers/ChildProcessCoverage.php', 'tests/Helpers/NativeChildCoverageEvidence.php',
            'tests/Fixtures/rrd-process-coverage.php', 'include/csrf.php', 'include/auth.php',
            'include/global_session.php', 'lib/auth.php', 'lib/functions.php', 'lib/clog_webapi.php',
            'logout.php', 'data_debug.php', 'managers.php', 'utilities.php', 'rrdcleaner.php',
            'cli/refresh_csrf.php', 'lib/html_utility.php', 'include/vendor/csrf/csrf-magic.php',
            'include/vendor/csrf/csrf-conf.php', 'lib/rrd.php', 'src/Graphing/Infrastructure/Rrd/ProxyCipher.php',
            'lib/dsdebug.php', 'lib/rrd_maintenance.php', 'lib/poller.php', 'lib/boost.php',
            'lib/api_data_source.php', 'lib/rrdcheck.php', 'lib/dsstats.php'
        );
        return array('producer' => $producer, 'kind' => $kind,
            'scenario' => hash('sha256', serialize(array($kind, $scenario))),
            'sources' => array_values(array_unique(array_merge($sources, $workers))),
            'markers' => $markers, 'hits' => $hits);
    }
}
