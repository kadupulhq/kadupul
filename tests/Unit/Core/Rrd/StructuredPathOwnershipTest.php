<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace StructuredPathOwnershipTest;

require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';

eval('namespace ' . __NAMESPACE__ . ';' . \test_php_function_source(file_get_contents(dirname(__DIR__, 4) . '/lib/rrd.php'), 'rrdtool_ownership_path'));

/** Load the global path checks the helper relies on, as ImportPackageFileDestinationTest does. */
function load_path_checks()
{
    $functions = file_get_contents(dirname(__DIR__, 4) . '/lib/functions.php');

    foreach (array('validate_relative_path_within', 'cacti_path_is_within') as $name) {
        if (!function_exists($name)) {
            eval(\test_php_function_source($functions, $name));
        }
    }
}

function cacti_log($message, $output = false, $environ = 'CMDPHP')
{
    $GLOBALS['ownership_log'][] = $environ . ':' . $message;
}

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

/**
 * Run the real ownership loop from lib/rrd.php against one filesystem state.
 *
 * The loop is taken from the file rather than retyped, and runs in a namespace
 * where fileowner(), filegroup(), lchown() and lchgrp() resolve to stubs, so an
 * unqualified call in production source reaches them without touching a disk.
 *
 * @param int $dir_uid   UID the directory currently has.
 * @param int $dir_gid   GID the directory currently has.
 * @param int $owner_id  UID of rra/, the value the loop should converge on.
 * @param int    $group_id GID of rra/.
 * @param string $file     The file holding the loop.
 * @param string $fail     'chown' or 'chgrp' to make that call fail, '' for neither.
 * @param string $rrd      The data source path; its directory decides how many
 *                         segments the loop walks.
 * @param string $link     Segment under rra/ to create as a symbolic link, to
 *                         'outside' next to rra/ or, as 'inside', to rra/real.
 * @param array  $touched  Receives the paths lchown() and lchgrp() were given.
 *
 * @return array<int, string> The ownership calls the loop made, in order.
 */
function structured_path_calls($dir_uid, $dir_gid, $owner_id, $group_id, $file = 'lib/rrd.php', $fail = '', $rrd = '/rra/host/device.rrd', $link = '', $target = 'outside', &$touched = array())
{
    // The walk resolves real paths, so the directories it visits have to exist.
    $base = realpath(sys_get_temp_dir()) . '/rrd-owner-' . bin2hex(random_bytes(6));
    mkdir($base . '/rra/real', 0700, true);
    mkdir($base . '/outside', 0700);

    if ($link !== '') {
        symlink($target === 'inside' ? $base . '/rra/real' : $base . '/outside', $base . '/rra/' . $link);
    }

    $directory = dirname($base . $rrd);
    if (!is_dir($directory)) {
        mkdir($directory, 0700, true);
    }

    $source = file_get_contents(dirname(__DIR__, 4) . '/' . $file);
    expect($source)->not->toBeFalse();

    $start = strpos($source, '$success  = true;');
    expect($start)->not->toBeFalse();

    // Balance braces from the loop's own opening one; an end marker inside the
    // body stops short of the closing braces and yields unparsable source.
    $open  = strpos($source, '{', strpos($source, 'foreach', $start));
    $depth = 0;
    $end   = $open;

    for ($i = $open; $i < strlen($source); $i++) {
        if ($source[$i] === '{') {
            $depth++;
        } elseif ($source[$i] === '}') {
            $depth--;

            if ($depth === 0) {
                $end = $i + 1;

                break;
            }
        }
    }

    $fragment = substr($source, $start, $end - $start);
    expect($fragment)->toContain('$pgroup_id');
    expect(substr(rtrim($fragment), -1))->toBe('}');

    $probe = '<?php
namespace Probe;
require_once ' . var_export(dirname(__DIR__, 4) . '/lib/functions.php', true) . ';
$calls = array();
function fileowner($path) { return $GLOBALS["dir_uid"]; }
function filegroup($path) { return $GLOBALS["dir_gid"]; }
function lchown($path, $uid) { $GLOBALS["calls"][] = "chown:" . $uid; $GLOBALS["touched"][] = $path; return $GLOBALS["fail"] !== "chown"; }
function lchgrp($path, $gid) { $GLOBALS["calls"][] = "chgrp:" . $gid; $GLOBALS["touched"][] = $path; return $GLOBALS["fail"] !== "chgrp"; }
function cacti_log($message, $flag = true, $environ = "") { $GLOBALS["calls"][] = "log:" . (strpos($message, "ERROR: Unable to set directory permissions") === 0 ? "permissions" : (strpos($message, "WARNING: Not changing ownership") === 0 ? "skipped" : "other")); }
$GLOBALS["touched"] = array();
$logopt = "POLLER";
' . \test_php_function_source($source, 'rrdtool_ownership_path') . '
$GLOBALS["fail"] = ' . var_export($fail, true) . ';
$GLOBALS["dir_uid"] = ' . (int) $dir_uid . ';
$GLOBALS["dir_gid"] = ' . (int) $dir_gid . ';
$owner_id = ' . (int) $owner_id . ';
$group_id = ' . (int) $group_id . ';
$config = array("rra_path" => ' . var_export($base . '/rra', true) . ');
$data_source_path = ' . var_export($base . $rrd, true) . ';
' . $fragment . '
echo json_encode(array($GLOBALS["calls"], $GLOBALS["touched"]));
';

    $file = tempnam(sys_get_temp_dir(), 'rrd-owner-');
    file_put_contents($file, $probe);

    try {
        $out    = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($file) . ' 2>&1');
        $result = json_decode($out, true);

        expect($result)->toBeArray($out);

        $touched = array_map(static function ($path) use ($base) {
            return substr($path, strlen($base));
        }, $result[1]);

        return $result[0];
    } finally {
        unlink($file);
        remove_tree($base);
    }
}

/*
 * The group test read the directory UID, so it compared an owner against a
 * group. rrdtool_function_create() and boost_rrdtool_function_create() both
 * reach the one copy of the loop, in rrdtool_create_structured_path().
 */
$copies = array('lib/rrd.php');

test('a directory whose UID equals the target GID still gets its group fixed', function () use ($copies) {
    foreach ($copies as $file) {
        // UID 500 matches $group_id, which made the old comparison see a match.
        expect(structured_path_calls(500, 999, 0, 500, $file))->toContain('chgrp:500');
    }
});

test('a directory already in the right group is not chgrp-ed for its UID', function () use ($copies) {
    $wrong = array();

    foreach ($copies as $file) {
        // Group is already 500; only the owner is wrong.
        $calls = structured_path_calls(42, 500, 0, 500, $file);

        if (!in_array('chown:0', $calls, true) || in_array('chgrp:500', $calls, true)) {
            $wrong[$file] = $calls;
        }
    }

    expect($wrong)->toBe(array());
});

test('a directory that already matches is left alone', function () use ($copies) {
    $wrong = array();

    foreach ($copies as $file) {
        $calls = structured_path_calls(0, 500, 0, 500, $file);

        if ($calls !== array()) {
            $wrong[$file] = $calls;
        }
    }

    expect($wrong)->toBe(array());
});

test('a directory wrong in both is corrected once each', function () use ($copies) {
    $wrong = array();

    foreach ($copies as $file) {
        $calls = structured_path_calls(42, 999, 0, 500, $file);

        if ($calls !== array('chown:0', 'chgrp:500')) {
            $wrong[$file] = $calls;
        }
    }

    expect($wrong)->toBe(array());
});

/*
 * A failed chown or chgrp has to log and stop, not carry on to the next path
 * segment as if the directory were correct. The stubs returned true before, so
 * nothing reached the `if (!$success)` branch.
 */
test('a failed ownership change is logged and stops the walk', function () use ($copies) {
    $wrong = array();

    foreach ($copies as $file) {
        // chown fails first, so the chgrp below it must not run at all.
        $calls = structured_path_calls(42, 999, 0, 500, $file, 'chown');

        if ($calls !== array('chown:0', 'log:permissions')) {
            $wrong[$file . ' chown'] = $calls;
        }

        // chgrp fails after a successful chown.
        $calls = structured_path_calls(42, 999, 0, 500, $file, 'chgrp');

        if ($calls !== array('chown:0', 'chgrp:500', 'log:permissions')) {
            $wrong[$file . ' chgrp'] = $calls;
        }
    }

    expect($wrong)->toBe(array());
});

/*
 * The single-segment fixture above cannot show that the loop stops: with one
 * path segment there is no later one to skip. A nested path gives the break
 * something to prevent.
 */
test('a failure stops the walk instead of continuing to the next segment', function () use ($copies) {
    $nested = '/rra/alpha/beta/device.rrd';
    $wrong  = array();

    foreach ($copies as $file) {
        // Both segments are wrong, so a completed walk makes four calls.
        $calls = structured_path_calls(42, 999, 0, 500, $file, '', $nested);

        if ($calls !== array('chown:0', 'chgrp:500', 'chown:0', 'chgrp:500')) {
            $wrong[$file . ' both segments'] = $calls;
        }

        // chown fails on the first segment, so the second is never touched.
        $calls = structured_path_calls(42, 999, 0, 500, $file, 'chown', $nested);

        if ($calls !== array('chown:0', 'log:permissions')) {
            $wrong[$file . ' stops after chown'] = $calls;
        }

        $calls = structured_path_calls(42, 999, 0, 500, $file, 'chgrp', $nested);

        if ($calls !== array('chown:0', 'chgrp:500', 'log:permissions')) {
            $wrong[$file . ' stops after chgrp'] = $calls;
        }
    }

    expect($wrong)->toBe(array());
});

/* A third copy of the loop would otherwise go unnoticed and unfixed. */
test('no copy of the ownership loop reads its group with fileowner', function () {
    $root  = dirname(__DIR__, 4);
    $wrong = array();

    foreach (glob($root . '/lib/*.php') as $path) {
        $source = file_get_contents($path);

        if (strpos($source, '$pgroup_id') === false) {
            continue;
        }

        foreach (preg_grep('/\$pgroup_id\s*=/', explode("\n", $source)) as $line) {
            if (strpos($line, 'filegroup(') === false) {
                $wrong[basename($path)] = trim($line);
            }
        }
    }

    expect($wrong)->toBe(array());
});

/*
 * chown() and chgrp() follow symbolic links, so a link in the new directory's
 * path would move root's ownership change onto whatever it points at.
 */
test('the walk never changes ownership through a symbolic link', function () {
    foreach (array('outside', 'inside') as $target) {
        $touched = array();
        $calls   = structured_path_calls(42, 999, 0, 500, 'lib/rrd.php', '', '/rra/alpha/beta/device.rrd', 'alpha', $target, $touched);

        expect($calls)->toBe(array('log:skipped'));
        expect($touched)->toBe(array());
    }
});

test('the walk still changes plain directories inside the RRA path', function () {
    $touched = array();
    $calls   = structured_path_calls(42, 999, 0, 500, 'lib/rrd.php', '', '/rra/alpha/beta/device.rrd', '', 'outside', $touched);

    expect($calls)->toBe(array('chown:0', 'chgrp:500', 'chown:0', 'chgrp:500'));
    expect($touched)->toBe(array('/rra/alpha', '/rra/alpha', '/rra/alpha/beta', '/rra/alpha/beta'));
});

test('ownership is refused for a link or a path outside the root', function () {
    require_once dirname(__DIR__, 4) . '/lib/rrd.php';
    load_path_checks();

    // Defined at run time, after every test file has declared what it needs.
    if (!function_exists('cacti_log')) {
        eval('function cacti_log(...$args) {}');
    }

    $base = realpath(sys_get_temp_dir()) . '/rrd-owner-' . bin2hex(random_bytes(6));
    mkdir($base . '/rra/host', 0700, true);
    mkdir($base . '/rra-other', 0700);
    mkdir($base . '/outside', 0700);
    touch($base . '/rra/host/device.rrd');
    touch($base . '/outside/device.rrd');
    symlink($base . '/outside/device.rrd', $base . '/rra/host/linked.rrd');
    symlink($base . '/outside', $base . '/rra/away');
    symlink($base . '/rra/host', $base . '/rra/alias');

    try {
        $root = $base . '/rra';

        // The library's own copy, then the copy logging to this namespace.
        foreach (array('\\rrdtool_ownership_path', __NAMESPACE__ . '\\rrdtool_ownership_path') as $checked) {
            $GLOBALS['ownership_log'] = array();

            expect($checked($root . '/host/device.rrd', $root, 'BOOST'))->toBe($root . '/host/device.rrd');
            expect($checked($root . '/host', $root . '/'))->toBe($root . '/host');
            expect($checked($root . '/host/device.rrd'))->toBe($root . '/host/device.rrd');
            expect($GLOBALS['ownership_log'])->toBe(array());

            // A link is refused with or without a root to bound it.
            expect($checked($root . '/host/linked.rrd', $root, 'BOOST'))->toBeFalse();
            expect($checked($root . '/host/linked.rrd', null, 'MAINT'))->toBeFalse();

            // A plain file reached through a linked directory, inside the root
            // or out of it, or through '..'.
            expect($checked($root . '/alias/device.rrd', $root))->toBeFalse();
            expect($checked($root . '/away/device.rrd', $root))->toBeFalse();
            expect($checked($root . '/host/../../outside/device.rrd', $root))->toBeFalse();

            // The root itself, a missing path and a sibling sharing its prefix.
            expect($checked($root, $root))->toBeFalse();
            expect($checked($root . '/host/missing.rrd', $root))->toBeFalse();
            expect($checked($base . '/rra-other', $root))->toBeFalse();
        }

        expect(count($GLOBALS['ownership_log']))->toBe(8);
        expect($GLOBALS['ownership_log'][0])->toStartWith('BOOST:WARNING: Not changing ownership of');
        expect($GLOBALS['ownership_log'][1])->toStartWith('MAINT:');
    } finally {
        remove_tree($base);
    }
});

/**
 * Run the root-only ownership block after RRDtool create in $function, taken
 * from $file, against a real RRA tree with lchown(), lchgrp(), posix_getuid()
 * and cacti_log() stubbed.
 *
 * @param string $rrd    Path of the new RRD below the fixture base.
 * @param bool   $create Whether RRDtool created the file.
 * @param string $link   Directory under rra/ to create as a link to rra/real.
 *
 * @return array<int, string> Calls and log lines, in order.
 */
function create_ownership_calls($file, $function, $rrd, $create = true, $link = '')
{
    $base = realpath(sys_get_temp_dir()) . '/rrd-owner-' . bin2hex(random_bytes(6));
    mkdir($base . '/rra/real', 0700, true);

    if ($link !== '') {
        symlink($base . '/rra/real', $base . '/rra/' . $link);
    }

    if (!is_dir(dirname($base . $rrd))) {
        mkdir(dirname($base . $rrd), 0700, true);
    }

    if ($create) {
        touch($base . $rrd);
    }

    $root   = dirname(__DIR__, 4);
    $body   = \test_php_function_source(file_get_contents($root . '/' . $file), $function);
    $uid = strpos($body, 'posix_getuid() == 0');
    expect($uid)->not->toBeFalse();
    $start = strrpos(substr($body, 0, $uid), 'if (');

    $depth = 0;
    $end   = $start;
    for ($i = $start; $i < strlen($body); $i++) {
        if ($body[$i] === '{') {
            $depth++;
        } elseif ($body[$i] === '}' && --$depth === 0) {
            $end = $i + 1;

            break;
        }
    }

    $probe = '<?php
namespace Probe;
require_once ' . var_export($root . '/lib/functions.php', true) . ';
function posix_getuid() { return 0; }
function lchown($path, $uid) { $GLOBALS["calls"][] = "lchown:" . substr($path, strlen($GLOBALS["base"])); return true; }
function lchgrp($path, $gid) { $GLOBALS["calls"][] = "lchgrp:" . substr($path, strlen($GLOBALS["base"])); return true; }
function cacti_log($message, $flag = true, $environ = "") { $GLOBALS["calls"][] = $environ . ":" . str_replace($GLOBALS["base"], "", $message); }
' . \test_php_function_source(file_get_contents($root . '/lib/rrd.php'), 'rrdtool_ownership_path') . '
$GLOBALS["calls"] = array();
$GLOBALS["base"] = ' . var_export($base, true) . ';
$owner_id = 0;
$group_id = 0;
$config = array("cacti_server_os" => "unix", "rra_path" => ' . var_export($base . '/rra', true) . ');
$data_source_path = ' . var_export($base . $rrd, true) . ';
' . substr($body, $start, $end - $start) . '
echo json_encode($GLOBALS["calls"]);
';

    $script = tempnam(sys_get_temp_dir(), 'rrd-owner-');
    file_put_contents($script, $probe);

    try {
        $out   = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' 2>&1');
        $calls = json_decode($out, true);
        expect($calls)->toBeArray($out);

        return $calls;
    } finally {
        unlink($script);
        remove_tree($base);
    }
}

/*
 * The new RRD's own ownership change must not act through a linked directory
 * above it, even one that points back inside the RRA tree.
 */
test('a new RRD under a linked directory keeps its ownership', function () {
    $refused = "WARNING: Not changing ownership of '/rra/alias/device.rrd', a symbolic link or outside the storage directory";

    expect(create_ownership_calls('lib/rrd.php', 'rrdtool_function_create', '/rra/alias/device.rrd', true, 'alias'))
        ->toBe(array('POLLER:' . $refused));
    expect(create_ownership_calls('lib/boost.php', 'boost_rrdtool_function_create', '/rra/alias/device.rrd', true, 'alias'))
        ->toBe(array('BOOST:' . $refused));
});

test('a new RRD in a plain directory gets its owner and group', function () {
    $expected = array('lchown:/rra/real/device.rrd', 'lchgrp:/rra/real/device.rrd');

    expect(create_ownership_calls('lib/rrd.php', 'rrdtool_function_create', '/rra/real/device.rrd'))->toBe($expected);
    expect(create_ownership_calls('lib/boost.php', 'boost_rrdtool_function_create', '/rra/real/device.rrd'))->toBe($expected);
});

/* A create RRDtool failed reports what it reported before the check existed. */
test('a missing RRD keeps the messages it had before', function () {
    expect(create_ownership_calls('lib/rrd.php', 'rrdtool_function_create', '/rra/real/device.rrd', false))
        ->toBe(array("POLLER:ERROR: RRD file '/rra/real/device.rrd' does not exist for ownership assignment"));
    expect(create_ownership_calls('lib/boost.php', 'boost_rrdtool_function_create', '/rra/real/device.rrd', false))
        ->toBe(array(
            "BOOST:WARNING: Unable to set owner for '/rra/real/device.rrd'",
            "BOOST:WARNING: Unable to set group for '/rra/real/device.rrd'",
        ));
});

/*
 * Every root ownership change on the RRA tree is checked first and made with
 * lchown() or lchgrp(), so a link swapped in after the check is not followed.
 */
test('each RRA ownership change is checked and never follows a link', function () {
    $root  = dirname(__DIR__, 4);
    $rrd   = file_get_contents($root . '/lib/rrd.php');
    $boost = file_get_contents($root . '/lib/boost.php');
    $sites = array(
        'rrdtool_create_structured_path' => $rrd,
        'rrdtool_function_create'        => $rrd,
        'boost_rrdtool_function_create'  => $boost,
        'rrdclean_create_path'           => file_get_contents($root . '/poller_maintenance.php'),
    );
    $found = 0;
    $wrong = array();

    foreach ($sites as $name => $source) {
        $checked = false;

        // Tokens, so a name in a comment or string is not taken for a call.
        foreach (token_get_all('<?php ' . \test_php_function_source($source, $name)) as $token) {
            if (!is_array($token) || $token[0] !== T_STRING) {
                continue;
            }

            if ($token[1] === 'rrdtool_ownership_path') {
                $checked = true;
            } elseif (in_array($token[1], array('chown', 'chgrp'), true)) {
                $wrong[] = $name . ' calls ' . $token[1];
            } elseif (in_array($token[1], array('lchown', 'lchgrp'), true)) {
                $found++;

                if (!$checked) {
                    $wrong[] = $name . ' calls ' . $token[1] . ' before the check';
                }
            }
        }
    }

    expect($found)->toBe(8);
    expect($wrong)->toBe(array());
});
