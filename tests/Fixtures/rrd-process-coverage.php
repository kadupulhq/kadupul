<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

// Loaded only when the parent PHPUnit run is collecting real coverage.
$coverageRoot = dirname(__DIR__, 2);
if (defined('LEGACY_COMMAND_OUTPUT_TEST_COVERAGE')) {
    require_once $coverageRoot . '/include/vendor/autoload.php';
}
require_once $coverageRoot . '/tests/vendor/autoload.php';
$testVendorPath = $coverageRoot . '/tests/vendor';
$testLoader = Composer\Autoload\ClassLoader::getRegisteredLoaders()[$testVendorPath] ?? null;
if (!$testLoader instanceof Composer\Autoload\ClassLoader) {
    throw new RuntimeException('Unable to locate the test dependency autoloader');
}
// The application later prepends its own Composer loader, which carries an
// older php-code-coverage RawCodeCoverageData class. Load PHPUnit 12's class
// first so child coverage collection cannot mix incompatible library versions.
class_exists(SebastianBergmann\CodeCoverage\Data\RawCodeCoverageData::class);
$coverageFilter = new SebastianBergmann\CodeCoverage\Filter();
if (defined('LEGACY_COMMAND_OUTPUT_TEST_COVERAGE')) {
    $coverageFilter->includeFile($coverageRoot . '/src/Platform/Infrastructure/Legacy/LegacyCommandOutput.php');
}
if (defined('HOST_REINDEX_TEST_COVERAGE')) {
    $coverageFilter->includeFile($coverageRoot . '/host.php');
    $coverageFilter->includeFile($coverageRoot . '/lib/html_utility.php');
}
if (defined('REALTIME_EXEC_TEST_COVERAGE')) {
    $coverageFilter->includeFile($coverageRoot . '/graph_realtime.php');
    $coverageFilter->includeFile($coverageRoot . '/lib/html_utility.php');
}
if (defined('WHITELIST_EXEC_TEST_COVERAGE')) {
    $coverageFilter->includeFile($coverageRoot . '/data_input.php');
    $coverageFilter->includeFile($coverageRoot . '/lib/functions.php');
    $coverageFilter->includeFile($coverageRoot . '/lib/html_utility.php');
}
if (defined('AGGREGATE_QUERY_TEST_COVERAGE')) {
    $coverageFilter->includeFile($coverageRoot . '/lib/api_aggregate.php');
}
if (defined('SPIKE_CSRF_TEST_COVERAGE')) {
    $coverageFilter->includeFile($coverageRoot . '/spikekill.php');
    $coverageFilter->includeFile($coverageRoot . '/lib/html_utility.php');
}
if (defined('IMPORT_PREVIEW_TEST_COVERAGE')) {
    $coverageFilter->includeFile($coverageRoot . '/package_import.php');
    $coverageFilter->includeFile($coverageRoot . '/templates_import.php');
    $coverageFilter->includeFile($coverageRoot . '/lib/import.php');
}
if (defined('BULK_CSRF_CONTROLLER')) {
    $coverageFilter->includeFile(BULK_CSRF_CONTROLLER);
    $coverageFilter->includeFile($coverageRoot . '/lib/html_utility.php');
}
if (defined('ADMIN_MUTATION_TEST_COVERAGE')) {
    $coverageFilter->includeFile($coverageRoot . '/plugins.php');
    $coverageFilter->includeFile($coverageRoot . '/user_admin.php');
    $coverageFilter->includeFile($coverageRoot . '/user_group_admin.php');
    $coverageFilter->includeFile($coverageRoot . '/lib/html_utility.php');
}
if (defined('GRAPH_INPUT_TEST_COVERAGE')) {
    $coverageFilter->includeFile($coverageRoot . '/lib/graph_template_input.php');
    $coverageFilter->includeFile($coverageRoot . '/lib/import.php');
    $coverageFilter->includeFile($coverageRoot . '/lib/api_graph.php');
    $coverageFilter->includeFile($coverageRoot . '/lib/html_form_template.php');
    $coverageFilter->includeFile($coverageRoot . '/graphs.php');
    $coverageFilter->includeFile($coverageRoot . '/graph_templates_inputs.php');
    $coverageFilter->includeFile($coverageRoot . '/lib/template.php');
    $coverageFilter->includeFile($coverageRoot . '/lib/html_utility.php');
}
if (defined('INSTALLER_CSRF_BOOTSTRAP_COVERAGE')) {
    $coverageFilter->includeFile($coverageRoot . '/include/csrf.php');
}
if (defined('GRAPH_TEMPLATE_SECURITY_TEST_COVERAGE')) {
    $coverageFilter->includeFile($coverageRoot . '/graph_templates.php');
    $coverageFilter->includeFile($coverageRoot . '/lib/html_utility.php');
}
if (defined('DATA_SOURCE_LIMIT_TEST_COVERAGE')) {
    $coverageFilter->includeFile($coverageRoot . '/data_sources.php');
    $coverageFilter->includeFile($coverageRoot . '/data_templates.php');
    $coverageFilter->includeFile($coverageRoot . '/lib/functions.php');
}
if (defined('BASIC_AUTH_TEST_COVERAGE')) {
    $coverageFilter->includeFile($coverageRoot . '/include/auth.php');
    $coverageFilter->includeFile($coverageRoot . '/lib/auth.php');
    $coverageFilter->includeFile($coverageRoot . '/user_admin.php');
}
$coverageFilter->includeFile($coverageRoot . '/lib/rrd.php');
$coverageFilter->includeFile($coverageRoot . '/src/Graphing/Infrastructure/Rrd/ProxyCipher.php');
$coverageFilter->includeFile($coverageRoot . '/lib/dsdebug.php');
$coverageFilter->includeFile($coverageRoot . '/lib/rrd_maintenance.php');
$coverageFilter->includeFile($coverageRoot . '/lib/poller.php');
$coverageFilter->includeFile($coverageRoot . '/lib/boost.php');
$coverageFilter->includeFile($coverageRoot . '/lib/api_data_source.php');
$coverageFilter->includeFile($coverageRoot . '/lib/rrdcheck.php');
$coverageFilter->includeFile($coverageRoot . '/lib/dsstats.php');
if (defined('RRD_TEST_INSTALLER_COVERAGE')) {
    $coverageFilter->includeFile($coverageRoot . '/lib/installer.php');
    $coverageFilter->includeFile($coverageRoot . '/install/upgrades/1_1_6.php');
    $coverageFilter->includeFile($coverageRoot . '/install/upgrades/1_2_31.php');
}
if (defined('REPORT_SECURITY_TEST_COVERAGE')) {
    $coverageFilter->includeFile($coverageRoot . '/lib/auth.php');
    $coverageFilter->includeFile($coverageRoot . '/lib/html_reports.php');
    $coverageFilter->includeFile($coverageRoot . '/lib/reports.php');
}
if (defined('PROFILE_SECURITY_TEST_COVERAGE')) {
    $coverageFilter->includeFile($coverageRoot . '/auth_profile.php');
    $coverageFilter->includeFile($coverageRoot . '/auth_changepassword.php');
    $coverageFilter->includeFile($coverageRoot . '/auth_login.php');
    $coverageFilter->includeFile($coverageRoot . '/lib/html_utility.php');
}
if (defined('RRD_TEST_CLI_COVERAGE_COPY')) {
    $coverageFilter->includeFile(RRD_TEST_CLI_COVERAGE_COPY);
}
if (defined('MAILER_TEST_COVERAGE')) {
    $coverageFilter->includeFile($coverageRoot . '/lib/functions.php');
}
$childCoverage = new SebastianBergmann\CodeCoverage\CodeCoverage(
    (new SebastianBergmann\CodeCoverage\Driver\Selector())->forLineCoverage($coverageFilter),
    $coverageFilter
);
$childCoverage->start('native RRD child ' . getmypid());
$childCoverageFile = RRD_TEST_COVERAGE_DIRECTORY . '/child-' . getmypid() . '.coverage';
register_shutdown_function(function () use ($childCoverage, $childCoverageFile, $testLoader) {
    // Append collection after application shutdown handlers so implicit pipe
    // close/drain is measured too, not just the main body of the child script.
    register_shutdown_function(function () use ($childCoverage, $childCoverageFile, $testLoader) {
        // Application bootstrap prepends its Composer loader. Restore the test
        // loader as first choice before PHPUnit 12 lazily creates its analyser.
        $testLoader->unregister();
        $testLoader->register(true);
        $childCoverage->stop();
        if (defined('RRD_TEST_CLI_COVERAGE_COPY')) {
            // Measure the real copied CLI, then map only its filename. Refuse
            // attribution unless every source byte (and thus line) is identical.
            $copyHash = hash_file('sha256', RRD_TEST_CLI_COVERAGE_COPY);
            $sourceHash = hash_file('sha256', RRD_TEST_CLI_COVERAGE_SOURCE);
            if ($copyHash === false || $sourceHash === false || !hash_equals($sourceHash, $copyHash)) {
                throw new RuntimeException('Copied CLI changed while measuring coverage');
            }
            $childCoverage->getData(true)->renameFile(RRD_TEST_CLI_COVERAGE_COPY, RRD_TEST_CLI_COVERAGE_SOURCE);
            // PHPUnit 12's code-coverage filter is an allowlist and no longer
            // exposes excludeFile(); the path remapping above removes the
            // copied filename from collected coverage data.
            if (method_exists($childCoverage->filter(), 'excludeFile')) {
                $childCoverage->filter()->excludeFile(RRD_TEST_CLI_COVERAGE_COPY);
            }
            $childCoverage->filter()->includeFile(RRD_TEST_CLI_COVERAGE_SOURCE);

            // Test cleanup removes copied entrypoints before the aggregate
            // Clover report runs. Preserve their exact mapping outside the
            // fixture directory so PHPUnit can re-open each copied source.
            $sourceMapFile = sys_get_temp_dir() . '/kadupul-coverage-source-map-' . bin2hex(random_bytes(8)) . '.json';
            $sourceMap = json_encode(array(
                'copy' => RRD_TEST_CLI_COVERAGE_COPY,
                'source' => RRD_TEST_CLI_COVERAGE_SOURCE,
            ), JSON_THROW_ON_ERROR);
            if (file_put_contents($sourceMapFile, $sourceMap, LOCK_EX) === false) {
                throw new RuntimeException('Unable to preserve child coverage source mapping');
            }
        }
        if (file_put_contents($childCoverageFile, serialize($childCoverage)) === false) {
            throw new RuntimeException('Unable to preserve child process coverage');
        }
    });
});
