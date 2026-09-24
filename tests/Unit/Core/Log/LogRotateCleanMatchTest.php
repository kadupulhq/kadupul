<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace LogRotateCleanMatchTest;

$root = dirname(__DIR__, 4);

/**
 * Run the real logrotate_file_clean() against a throwaway directory and report
 * which files survived. The function is taken from poller_maintenance.php, so
 * a regression in its matching fails here rather than in a copy of it.
 *
 * @param array  $files    Names to create in the directory.
 * @param string $log      The active log's basename.
 * @param int    $rotation Days to retain.
 *
 * @return array{survivors: array<int, string>, out: string}
 */
function clean_directory(array $files, $log = 'cacti.log', $rotation = 7)
{
    $source = file_get_contents(dirname(__DIR__, 4) . '/poller_maintenance.php');
    expect($source)->not->toBeFalse();

    $start = strpos($source, 'function logrotate_file_clean(');
    expect($start)->not->toBeFalse();

    // Balance braces so the whole function comes across intact.
    $open  = strpos($source, '{', $start);
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

    $function = substr($source, $start, $end - $start);
    expect($function)->toContain('@unlink');

    $directory = sys_get_temp_dir() . '/kadupul-logclean-' . bin2hex(random_bytes(6));
    mkdir($directory, 0o755, true);

    foreach ($files as $name) {
        file_put_contents($directory . '/' . $name, 'x');
    }

    $code = '<?php
function cacti_sizeof($a) { return is_array($a) ? count($a) : 0; }
function cacti_log($m, $s = true, $f = "", $v = 0) { $GLOBALS["log"][] = $m; }
define("POLLER_VERBOSITY_HIGH", 3);
define("POLLER_VERBOSITY_DEBUG", 5);
$GLOBALS["log"] = array();
$config = array();
' . $function . '
logrotate_file_clean("Kadupul", ' . var_export($directory . '/' . $log, true) . ', new \DateTime("2026-09-22"), ' . (int) $rotation . ');
$left = array_values(array_diff(scandir(' . var_export($directory, true) . '), array(".", "..")));
sort($left);
echo json_encode(array("survivors" => $left, "log" => $GLOBALS["log"]));
';

    $file = tempnam(sys_get_temp_dir(), 'logclean-');
    file_put_contents($file, $code);

    try {
        $out    = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($file) . ' 2>&1');
        $result = json_decode($out, true);

        expect($result)->toBeArray($out);

        return array('survivors' => $result['survivors'], 'out' => implode("\n", $result['log']));
    } finally {
        unlink($file);

        foreach (glob($directory . '/*') as $left) {
            unlink($left);
        }

        rmdir($directory);
    }
}

/*
 * The reported defect: the old matching accepted any name merely containing
 * the log basename, so a neighbour with an old date in its name was deleted.
 */
test('a file that only contains the log name is left alone', function () {
    $result = clean_directory(array('cacti.log', 'backup-cacti.log-20200101', 'cacti.log-20200101'));

    expect($result['survivors'])->toBe(array('backup-cacti.log-20200101', 'cacti.log'));
});

test('expired rotations are still removed and current ones kept', function () {
    // 20260916 is inside the 7 day window from 2026-09-22; 20200101 is not.
    $result = clean_directory(array('cacti.log', 'cacti.log-20200101', 'cacti.log-20260916'));

    expect($result['survivors'])->toBe(array('cacti.log', 'cacti.log-20260916'));
});

/* logrotate_file_rotate() counts from 1 to 99 unpadded, so those are the
   only counters it can produce, and the pattern accepts no others. */
test('a numbered collision rotation is recognised', function () {
    $result = clean_directory(array('cacti.log', 'cacti.log-20200101-1', 'cacti.log-20200101-99'));

    expect($result['survivors'])->toBe(array('cacti.log'));
});

test('a counter the writer cannot emit is not treated as a rotation', function () {
    $result = clean_directory(array(
        'cacti.log',
        'cacti.log-20200101-0',
        'cacti.log-20200101-01',
        'cacti.log-20200101-100',
    ));

    expect($result['survivors'])->toBe(array(
        'cacti.log',
        'cacti.log-20200101-0',
        'cacti.log-20200101-01',
        'cacti.log-20200101-100',
    ));
});

test('lookalikes on either side of the name survive', function () {
    $result = clean_directory(array(
        'cacti.log',
        'cacti.log.old-20200101',
        'my-cacti.log-20200101',
        'cacti.log-20200101.gz',
        'cacti.log-2020010',
        'cacti.log-202001011',
        'cacti.logx-20200101',
    ));

    expect($result['survivors'])->toBe(array(
        'cacti.log',
        'cacti.log-2020010',
        'cacti.log-20200101.gz',
        'cacti.log-202001011',
        'cacti.log.old-20200101',
        'cacti.logx-20200101',
        'my-cacti.log-20200101',
    ));
});

/*
 * PCRE's $ also matches just before a trailing newline, so an anchored pattern
 * ending in $ accepted "cacti.log-20200101\n", a name a user can create on any
 * filesystem that allows it. The pattern ends in \z for that reason.
 */
test('a name with a trailing newline is not treated as a rotation', function () {
    $result = clean_directory(array('cacti.log', "cacti.log-20200101\n", 'cacti.log-20200101'));

    expect($result['survivors'])->toBe(array('cacti.log', "cacti.log-20200101\n"));
});

/* The active log has no date, so it must never match its own cleanup. */
test('the active log is never a candidate', function () {
    $result = clean_directory(array('cacti.log'));

    expect($result['survivors'])->toBe(array('cacti.log'));
});

/*
 * The skip message used to be printed for files that did match, which is the
 * opposite of what it says.
 */
test('the skip notice names only the files that were skipped', function () {
    $result = clean_directory(array('cacti.log', 'unrelated.txt', 'cacti.log-20200101'));

    expect($result['out'])->toContain('ignoring Kadupul Log : unrelated.txt');
    expect(strpos($result['out'], 'ignoring Kadupul Log : cacti.log-20200101'))->toBeFalse();
    expect($result['out'])->toContain('Purging Kadupul Log : cacti.log-20200101');
});

/**
 * Run logrotate_rotatenow() with its collaborators stubbed and report which
 * logs it rotated and cleaned, in order.
 *
 * @param string|null $stderr The configured stderr log, or null for none.
 *
 * @return array{rotated: array<int, string>, cleaned: array<int, string>}
 */
function rotatenow_calls($stderr = null)
{
    $source = file_get_contents(dirname(__DIR__, 4) . '/poller_maintenance.php');
    expect($source)->not->toBeFalse();

    $start = strpos($source, 'function logrotate_rotatenow()');
    expect($start)->not->toBeFalse();

    $open  = strpos($source, '{', $start);
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

    $function = substr($source, $start, $end - $start);

    $code = '<?php
$GLOBALS["rotated"] = array();
$GLOBALS["cleaned"] = array();
$config = array("base_path" => "/opt/cacti");
function read_config_option($name) {
	if ($name === "path_cactilog")  { return "/opt/cacti/log/cacti.log"; }
	if ($name === "path_stderrlog") { return ' . var_export($stderr === null ? '' : $stderr, true) . '; }
	if ($name === "logrotate_retain") { return 7; }
	return "";
}
function set_config_option($n, $v) {}
function cacti_log($m, $s = true, $f = "", $v = 0) {}
function logrotate_file_rotate($name, $log, $date) { $GLOBALS["rotated"][] = $name; return 1; }
function logrotate_file_clean($name, $log, $date, $rotation) { $GLOBALS["cleaned"][] = $name; return 1; }
' . $function . '
logrotate_rotatenow();
echo json_encode(array("rotated" => $GLOBALS["rotated"], "cleaned" => $GLOBALS["cleaned"]));
';

    $file = tempnam(sys_get_temp_dir(), 'rotatenow-');
    file_put_contents($file, $code);

    try {
        $out    = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($file) . ' 2>&1');
        $result = json_decode($out, true);

        expect($result)->toBeArray($out);

        return $result;
    } finally {
        unlink($file);
    }
}

/*
 * A stray call after the loop cleaned whichever log the loop happened to leave
 * in $name, so one log was scanned twice and the reported count was inflated.
 * The log names differ between branches, so assert the invariant rather than
 * the names: whatever was rotated is what gets cleaned, once each.
 */
test('each configured log is cleaned exactly once', function () {
    $both = rotatenow_calls('/opt/cacti/log/cacti_stderr.log');

    expect(count($both['rotated']))->toBe(2)
        ->and($both['rotated'])->toBe(array_unique($both['rotated']))
        ->and($both['cleaned'])->toBe($both['rotated']);

    // With no stderr log configured the duplicate hit the active log instead.
    $one = rotatenow_calls();

    expect(count($one['rotated']))->toBe(1)
        ->and($one['cleaned'])->toBe($one['rotated']);
});
