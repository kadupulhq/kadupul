<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace RrdOwnershipNativeTest;

require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';

/*
 * The ownership code runs only as root in production. These run the real
 * library in a child as the current user: lchown() and lchgrp() to its own
 * IDs succeed and to an unused ID fail, so both outcomes are real calls.
 */

/** Remove a fixture tree without following the links inside it. */
function remove_tree($path)
{
    if (is_link($path) || is_file($path)) {
        unlink($path);

        return;
    }

    if (is_dir($path)) {
        foreach (scandir($path) as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                remove_tree($path . '/' . $entry);
            }
        }

        rmdir($path);
    }
}

/** Run $script with PHP, merge its coverage into $coverage, and decode its JSON output. */
function run_child($script, $directory, $coverage)
{
    $process = proc_open(array(PHP_BINARY, '-d', 'pcov.directory=/', '-d', 'pcov.exclude=~/(include/vendor|tests)/~', $script), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
    $output  = stream_get_contents($pipes[1]);
    $error   = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    expect(proc_close($process))->toBe(0, $error . $output)->and($error)->toBe('');

    if ($coverage !== null) {
        $reports = glob($directory . '/*.coverage');
        expect($reports)->toHaveCount(1);
        $coverage->merge(unserialize(file_get_contents($reports[0])));
    }

    return json_decode($output, true, 512, JSON_THROW_ON_ERROR);
}

/** The path checks from lib/functions.php, without the rest of that file. */
function path_check_source()
{
    $functions = file_get_contents(dirname(__DIR__, 4) . '/lib/functions.php');

    return \test_php_function_source($functions, 'validate_relative_path_within') . "\n"
        . \test_php_function_source($functions, 'cacti_path_is_within') . "\n";
}

test('the RRD ownership helpers act only on plain paths inside the RRA directory', function () {
    $root = dirname(__DIR__, 4);
    $base = realpath(sys_get_temp_dir()) . '/rrd-owner-native-' . bin2hex(random_bytes(6));
    foreach (array('rra/real', 'rra/a/b', 'rra/c/d', 'rra/f', 'rra/-other/host', 'rra-other/host', 'outside', 'archive-target') as $path) {
        mkdir($base . '/' . $path, 0700, true);
    }
    touch($base . '/rra/real/device.rrd');
    symlink($base . '/rra/real', $base . '/rra/alias');
    symlink($base . '/outside', $base . '/rra/away');
    symlink($base . '/archive-target', $base . '/archive-link');
    mkdir($base . '/rra/away/e', 0700);
    mkdir($base . '/archive-link/new', 0700);

    $coverage  = $this->getTestResultObject()->getCodeCoverage();
    $bootstrap = '<?php ';
    if ($coverage !== null) {
        $bootstrap .= 'define("RRD_TEST_COVERAGE_DIRECTORY", ' . var_export($base, true) . '); require ' . var_export($root . '/tests/Fixtures/rrd-process-coverage.php', true) . ';';
    }
    $bootstrap .= path_check_source();
    $bootstrap .= '$base = ' . var_export($base, true) . ';';
    $bootstrap .= '$temp = ' . var_export(sys_get_temp_dir() . '/' . basename($base), true) . ';';
    $bootstrap .= 'require ' . var_export($root . '/lib/rrd.php', true) . ';';
    $bootstrap .= <<<'SOURCE'
$config = array('cacti_server_os' => 'unix', 'rra_path' => $base . '/rra');
$logs = array();
function cacti_log($message, $output = false, $environ = '') { $GLOBALS['logs'][] = $environ . ':' . str_replace($GLOBALS['base'], '', $message); }
function run($operation) { $GLOBALS['logs'] = array(); $result = $operation(); return array($result, $GLOBALS['logs']); }
// A refused lchown() warns; the log lines are what the test reads.
set_error_handler(fn() => true);
$uid = posix_getuid();
$gid = posix_getgid();
$unused = 2147483000;
$failed = array("ERROR: Unable to set ownership for '%s'", "ERROR: Unable to set group for '%s'");
$missing = "ERROR: RRD file '%s' does not exist for ownership assignment";
$boost = array("WARNING: Unable to set owner for '%s'", "WARNING: Unable to set group for '%s'");
$results = array(
    'plain'         => run(fn() => rrdtool_set_rrd_ownership($base . '/rra/real/device.rrd', $uid, $gid, 'POLLER', $failed, $missing)),
    'denied'        => run(fn() => rrdtool_set_rrd_ownership($base . '/rra/real/device.rrd', $unused, $unused, 'BOOST', $boost)),
    'missing'       => run(fn() => rrdtool_set_rrd_ownership($base . '/rra/real/none.rrd', $uid, $gid, 'POLLER', $failed, $missing)),
    'boost missing' => run(fn() => rrdtool_set_rrd_ownership($base . '/rra/real/none.rrd', $uid, $gid, 'BOOST', $boost)),
    'linked'        => run(fn() => rrdtool_set_rrd_ownership($base . '/rra/alias/device.rrd', $uid, $gid, 'BOOST', $boost)),
    'walk'          => run(fn() => rrdtool_set_structured_path_ownership($base . '/rra/a/b/x.rrd', $uid, $gid, 'POLLER')),
    'walk denied'   => run(fn() => rrdtool_set_structured_path_ownership($base . '/rra/c/d/x.rrd', $unused, $gid, 'POLLER')),
    'walk linked'   => run(fn() => rrdtool_set_structured_path_ownership($base . '/rra/away/e/x.rrd', $uid, $gid, 'POLLER')),
    // A sibling whose name starts with the RRA directory's; rra/-other exists
    // so a walk of the wrong path would reach it.
    'walk sibling'  => run(fn() => rrdtool_set_structured_path_ownership($base . '/rra-other/host/x.rrd', $unused, $unused, 'POLLER')),
    // Any other group this account is in can be given without privilege.
    'walk group'    => run(function () use ($base, $uid) {
        $other = array_values(array_diff(posix_getgroups(), array(filegroup($base . '/rra/f'))));
        if ($other === array()) {
            return true;
        }
        rrdtool_set_structured_path_ownership($base . '/rra/f/x.rrd', $uid, $other[0], 'POLLER');
        clearstatcache();
        return filegroup($base . '/rra/f') === $other[0];
    }),
    'archive'       => run(fn() => rrdtool_ownership_path($base . '/archive-target/new', null, 'MAINT') === $base . '/archive-target/new'),
    'archive link'  => run(fn() => rrdtool_ownership_path($base . '/archive-link/new', null, 'MAINT')),
    // The temporary directory as PHP names it, through /var on macOS.
    'system link'   => run(fn() => rrdtool_ownership_path($temp . '/archive-target', null, 'MAINT') === $temp . '/archive-target'),
);
echo json_encode($results);
SOURCE;
    file_put_contents($base . '/run.php', $bootstrap);

    try {
        $results = run_child($base . '/run.php', $base, $coverage);
        $refused = static function ($environ, $path) {
            return $environ . ":WARNING: Not changing ownership of '" . $path . "', a symbolic link or outside the storage directory";
        };

        expect($results['plain'][1])->toBe(array());
        expect($results['denied'][1])->toBe(array(
            "BOOST:WARNING: Unable to set owner for '/rra/real/device.rrd'",
            "BOOST:WARNING: Unable to set group for '/rra/real/device.rrd'",
        ));
        expect($results['missing'][1])->toBe(array("POLLER:ERROR: RRD file '/rra/real/none.rrd' does not exist for ownership assignment"));
        expect($results['boost missing'][1])->toBe(array(
            "BOOST:WARNING: Unable to set owner for '/rra/real/none.rrd'",
            "BOOST:WARNING: Unable to set group for '/rra/real/none.rrd'",
        ));
        expect($results['linked'][1])->toBe(array($refused('BOOST', '/rra/alias/device.rrd')));
        expect($results['walk'][1])->toBe(array());
        expect($results['walk denied'][1])->toBe(array(":ERROR: Unable to set directory permissions for '/rra/c'"));
        expect($results['walk group'])->toBe(array(true, array()));
        expect($results['walk sibling'][1])->toBe(array($refused('POLLER', '/rra-other/host')));
        expect($results['walk linked'][1])->toBe(array($refused('POLLER', '/rra/away/e')));
        expect($results['archive'])->toBe(array(true, array()));
        expect($results['archive link'])->toBe(array(false, array($refused('MAINT', '/archive-link/new'))));
        expect($results['system link'])->toBe(array(true, array()));
    } finally {
        remove_tree($base);
    }
});

/*
 * The archive may be anywhere, so no RRA bound applies, but a link above the
 * new directory would still carry lchown() and lchgrp() somewhere else.
 */
test('RRDfile maintenance skips an archive directory reached through a link', function () {
    $root = dirname(__DIR__, 4);
    $base = realpath(sys_get_temp_dir()) . '/rrd-owner-maint-' . bin2hex(random_bytes(6));
    foreach (array('include', 'rra', 'target') as $path) {
        mkdir($base . '/' . $path, 0700, true);
    }
    symlink($base . '/target', $base . '/linked');
    copy($root . '/poller_maintenance.php', $base . '/poller_maintenance.php');

    $coverage  = $this->getTestResultObject()->getCodeCoverage();
    $bootstrap = '<?php ';
    if ($coverage !== null) {
        $bootstrap .= 'define("RRD_TEST_COVERAGE_DIRECTORY", dirname(__DIR__));'
            . 'define("RRD_TEST_CLI_COVERAGE_COPY", dirname(__DIR__) . "/poller_maintenance.php");'
            . 'define("RRD_TEST_CLI_COVERAGE_SOURCE", ' . var_export($root . '/poller_maintenance.php', true) . ');'
            . 'require ' . var_export($root . '/tests/Fixtures/rrd-process-coverage.php', true) . ';';
    }
    $bootstrap .= 'require ' . var_export($root . '/lib/rrd.php', true) . ';';
    $bootstrap .= <<<'SOURCE'
$base = dirname(__DIR__);
$config = array('cacti_server_os' => 'unix', 'rra_path' => $base . '/rra');
$logs = array();
function cacti_log($message, $output = false, $environ = '') { $GLOBALS['logs'][] = $environ . ':' . str_replace($GLOBALS['base'], '', $message); }
$plain = rrdclean_create_path($base . '/rra/archive/new');
$linked = rrdclean_create_path($base . '/linked/new');
echo json_encode(array($plain, $linked, is_dir($base . '/target/new'), $logs));
exit;
SOURCE;
    file_put_contents($base . '/include/cli_check.php', $bootstrap);

    try {
        list($plain, $linked, $created, $logs) = run_child($base . '/poller_maintenance.php', $base, $coverage);

        // Both directories are created and usable; only the linked one keeps
        // the ownership mkdir() gave it.
        expect($plain)->toBeTrue()->and($linked)->toBeTrue()->and($created)->toBeTrue();
        expect($logs)->toBe(array("MAINT:WARNING: Not changing ownership of '/linked/new', a symbolic link or outside the storage directory"));
    } finally {
        remove_tree($base);
    }
});
