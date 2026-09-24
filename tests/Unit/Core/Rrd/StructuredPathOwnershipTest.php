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
 * @param int $group_id  GID of rra/.
 *
 * @return array<int, string> The ownership calls the loop made, in order.
 */
function structured_path_calls($dir_uid, $dir_gid, $owner_id, $group_id)
{
    $source = file_get_contents(dirname(__DIR__, 4) . '/lib/rrd.php');
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
function chown($path, $uid) { $GLOBALS["calls"][] = "chown:" . $uid; return true; }
function chgrp($path, $gid) { $GLOBALS["calls"][] = "chgrp:" . $gid; return true; }
function cacti_log($message, $flag = true) { $GLOBALS["calls"][] = "log"; }
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
 * group. These two cases are the ones where that is not merely redundant.
 */
test('a directory whose UID equals the target GID still gets its group fixed', function () {
    // UID 500 matches $group_id, which made the old comparison see a match.
    $calls = structured_path_calls(500, 999, 0, 500);

    expect($calls)->toContain('chgrp:500');
});

test('a directory already in the right group is not chgrp-ed for its UID', function () {
    // Group is already 500; only the owner is wrong.
    $calls = structured_path_calls(42, 500, 0, 500);

    expect($calls)->toContain('chown:0');
    expect(in_array('chgrp:500', $calls, true))->toBeFalse();
});

test('a directory that already matches is left alone', function () {
    expect(structured_path_calls(0, 500, 0, 500))->toBe(array());
});

test('a directory wrong in both is corrected once each', function () {
    expect(structured_path_calls(42, 999, 0, 500))->toBe(array('chown:0', 'chgrp:500'));
});
