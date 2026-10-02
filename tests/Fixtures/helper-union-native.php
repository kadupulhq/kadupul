<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

$root = dirname(__DIR__, 2);
$case = $argv[1];
$directory = $argv[2];
$file = match (true) {
    str_starts_with($case, 'filename-') => 'api_device.php',
    str_starts_with($case, 'command-') => 'poller.php',
    str_starts_with($case, 'uid-') => 'csp_report_endpoint.php',
    default => 'rrd_maintenance.php',
};
mkdir($directory . '/lib', 0700);
$copy = $directory . '/lib/' . $file;
copy($root . '/lib/' . $file, $copy);
symlink($root . '/src', $directory . '/src');
symlink($root . '/include', $directory . '/include');
if (isset($argv[3])) {
    define('HELPER_UNION_TEST_COVERAGE', true);
    define('RRD_TEST_COVERAGE_DIRECTORY', $directory);
    define('RRD_TEST_CLI_COVERAGE_COPY', $copy);
    define('RRD_TEST_CLI_COVERAGE_SOURCE', $root . '/lib/' . $file);
    require __DIR__ . '/rrd-process-coverage.php';
}
define('CACTI_CSP_REPORT_TEST_MODE', 1);
// These two refusal cases replace only disabled native I/O boundaries.
if ($case === 'command-refused') {
    function proc_open($command, $descriptors, &$pipes)
    {
        return false;
    }
}
if ($case === 'uid-temp-refused') {
    function tempnam($directory, $prefix)
    {
        return false;
    }
}
require $copy;
$config = ['rra_path' => $directory . '/rra'];
mkdir($config['rra_path'], 0700);
$state = [];
if (str_starts_with($case, 'filename-')) {
    if ($case === 'filename-exhausted') {
        for ($i = 1; $i < 20; $i++) {
            file_put_contents($directory . '/template._' . sprintf('%02d', $i) . '.xml', 'existing');
        }
    }
    $state['result'] = api_clone_get_unique_filename($directory . '/template.xml');
    $state['files'] = count(glob($directory . '/template.*.xml'));
} elseif (str_starts_with($case, 'command-')) {
    $output = ['original'];
    $status = 99;
    $command = [PHP_BINARY, '-r', $case === 'command-output' ? 'echo "first\nlast"; exit(7);' : 'exit(3);'];
    $state['result'] = exec_with_timeout($command, $output, $status);
    $state['output'] = $output;
    $state['status'] = $status;
} elseif (str_starts_with($case, 'uid-')) {
    $state['result'] = csp_report_process_uid();
    $state['probes'] = glob($directory . '/kadupul_csp_uid*');
} elseif (str_starts_with($case, 'workspace-')) {
    $workspace = rrd_maintenance_workspace();
    $state['result'] = $workspace;
    $state['mode'] = $workspace === false ? null : fileperms($workspace) & 0777;
    if ($workspace !== false) {
        rmdir($workspace);
    }
} else {
    if ($case === 'locks-missing') {
        $config['rra_path'] .= '/missing';
    }
    $saved = $config;
    $busy = null;
    $locks = rrd_maintenance_acquire_paths([], 0, $busy);
    $state['result'] = $locks === false ? false : count($locks);
    $state['busy'] = $busy;
    $state['config_preserved'] = $saved === $config;
    if ($locks !== false) {
        rrd_maintenance_release($locks);
    }
}
define('NATIVE_COVERAGE_COMPLETED', ['helper-result-observed']);
file_put_contents($directory . '/result.json', json_encode($state, JSON_THROW_ON_ERROR));
