<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

// Execute a byte-identical whole global.php up to its actual database include.
// That include is the owned port; no production DB connection/bootstrap follows.
$root = dirname(__DIR__, 2);
$scenario = json_decode($argv[1], true, 512, JSON_THROW_ON_ERROR);
$directory = $argv[2];
require $root . '/tests/Helpers/ProxyBootstrapCoverageRegistration.php';
require $root . '/tests/Helpers/NativeChildCoverageEvidence.php';
foreach (['include', 'lib'] as $subdirectory) {
    if (!mkdir($directory . '/' . $subdirectory, 0700)) throw new RuntimeException('Cannot create owned bootstrap directory.');
}
foreach (['global.php', 'runtime.php', 'cacti_version'] as $file) {
    $source = file_get_contents($root . '/include/' . $file);
    if (!is_string($source) || file_put_contents($directory . '/include/' . $file, $source) !== strlen($source)) throw new RuntimeException('Cannot create exact bootstrap copy.');
}
$configSource = '<?php $proxy_headers = ["HTTP_X_FORWARDED_FOR"];';
if ($scenario['configured']) $configSource .= '$proxy_trusted_addresses = ["192.0.2.10"];';
if (file_put_contents($directory . '/include/config.php', $configSource) !== strlen($configSource)) throw new RuntimeException('Cannot create owned proxy config.');
$port = <<<'PHPPORT'
<?php
require $GLOBALS['root'] . '/include/global_constants.php';
require $GLOBALS['root'] . '/lib/functions.php';
$config['is_web'] = false; // The owned DB boundary occurs before global.php sets this CLI bootstrap flag.
$config['config_options_array'] = ['selective_debug' => '', 'selective_plugin_debug' => '', 'log_verbosity' => 0];
$allowed_proxy_headers = ['HTTP_X_FORWARDED_FOR'];
$nativeChildCoverageMarkers = ProxyBootstrapCoverageRegistration::MARKERS;
echo json_encode(['headers' => $config['proxy_headers'], 'trusted' => $config['proxy_trusted_addresses'], 'client' => get_client_addr()], JSON_THROW_ON_ERROR);
exit;
PHPPORT;
if (file_put_contents($directory . '/lib/database.php', $port) !== strlen($port)) throw new RuntimeException('Cannot create owned database boundary.');
$_SERVER['REMOTE_ADDR'] = '192.0.2.10';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.5';
if (isset($argv[3])) {
    $nativeChildCoverageSnapshot = NativeChildCoverageEvidence::snapshot($root, 'tests/Fixtures/proxy-config-bootstrap-native.php', $argv[1], ProxyBootstrapCoverageRegistration::SOURCES);
    define('CLIENT_ADDR_TEST_COVERAGE', true);
    define('RRD_TEST_CLI_COVERAGE_COPY', $directory . '/include/global.php');
    define('RRD_TEST_CLI_COVERAGE_SOURCE', $root . '/include/global.php');
    define('RRD_TEST_COVERAGE_DIRECTORY', $directory);
    require $root . '/tests/Fixtures/rrd-process-coverage.php';
}
require $directory . '/include/global.php';
throw new RuntimeException('Native bootstrap did not reach its database boundary.');
