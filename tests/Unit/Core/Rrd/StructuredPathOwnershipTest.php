<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace StructuredPathOwnershipTest;

/**
 * Run the real ownership loop from lib/rrd.php against one filesystem state.
 *
 * The loop is taken from the file rather than retyped, and runs in a namespace
 * where fileowner(), filegroup(), chown() and chgrp() resolve to stubs, so an
 * unqualified call in production source reaches them without touching a disk.
 *
 * @param int $dir_uid   UID the directory currently has.
 * @param int $dir_gid   GID the directory currently has.
 * @param int $owner_id  UID of rra/, the value the loop should converge on.
 * @param int    $group_id GID of rra/.
 * @param string $file     The file holding the loop; rrd.php and boost.php both have one.
 * @param string $fail     'chown' or 'chgrp' to make that call fail, '' for neither.
 *
 * @return array<int, string> The ownership calls the loop made, in order.
 */
function structured_path_calls($dir_uid, $dir_gid, $owner_id, $group_id, $file = 'lib/rrd.php', $fail = '')
{
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
$calls = array();
function fileowner($path) { return $GLOBALS["dir_uid"]; }
function filegroup($path) { return $GLOBALS["dir_gid"]; }
function chown($path, $uid) { $GLOBALS["calls"][] = "chown:" . $uid; return $GLOBALS["fail"] !== "chown"; }
function chgrp($path, $gid) { $GLOBALS["calls"][] = "chgrp:" . $gid; return $GLOBALS["fail"] !== "chgrp"; }
function cacti_log($message, $flag = true) { $GLOBALS["calls"][] = "log:" . (strpos($message, "ERROR: Unable to set directory permissions") === 0 ? "permissions" : "other"); }
$GLOBALS["fail"] = ' . var_export($fail, true) . ';
$GLOBALS["dir_uid"] = ' . (int) $dir_uid . ';
$GLOBALS["dir_gid"] = ' . (int) $dir_gid . ';
$owner_id = ' . (int) $owner_id . ';
$group_id = ' . (int) $group_id . ';
$config = array("rra_path" => "/rra");
$data_source_path = "/rra/host/device.rrd";
' . $fragment . '
echo json_encode($GLOBALS["calls"]);
';

    $file = tempnam(sys_get_temp_dir(), 'rrd-owner-');
    file_put_contents($file, $probe);

    try {
        $out = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($file) . ' 2>&1');
        $calls = json_decode($out, true);

        expect($calls)->toBeArray($out);

        return $calls;
    } finally {
        unlink($file);
    }
}

/*
 * The group test read the directory UID, so it compared an owner against a
 * group. lib/rrd.php and lib/boost.php each carry their own copy of the loop,
 * and boost_rrdtool_function_create() reaches the boost one, so both are
 * exercised here rather than only the one the issue happened to name.
 */
$copies = array('lib/rrd.php', 'lib/boost.php');

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
