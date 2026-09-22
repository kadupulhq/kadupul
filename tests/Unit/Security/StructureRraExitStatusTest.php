<?php

/* Copyright (C) 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later */

namespace StructureRraExitStatusTest;

$source = file_get_contents(dirname(__DIR__, 3) . '/cli/structure_rra_paths.php');
$cases = array();
// Extract each real fatal diagnostic through its exit, without loading the
// operational CLI bootstrap or touching any RRD files or database.
preg_match_all('/print [^;]*FATAL:[^;]*;\s*(?:display_help\(\);\s*)?exit[^;]*;/', $source, $matches);
foreach ($matches[0] as $index => $fragment) {
	$cases['fatal branch ' . $index] = array($fragment, array(1, 1, 1, 1, 1, 1, 1, 5, 6, 3)[$index]);
}
dataset('structure RRA fatal branches', $cases);

test('all ten structure RRA fatal exits including eight corrected ones are covered', function () use ($matches) {
	expect(count($matches[0]))->toBe(10);
});

test('real fatal branches print diagnostics and exit nonzero before continuing', function ($fragment, $expected) {
	$setup = 'define("PHP_DEOL", PHP_EOL . PHP_EOL); function display_help() { echo "HELP", PHP_EOL; } '
		. '$new_base_path = "fixture-dir"; $new_rrd_path = "fixture-new.rrd"; $old_rrd_path = "fixture-old.rrd"; ';
	$process = proc_open(array(PHP_BINARY, '-r', $setup . $fragment . 'echo "UNREACHABLE";'),
		array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
	expect(is_resource($process))->toBeTrue();
	fclose($pipes[0]);
	$output = stream_get_contents($pipes[1]);
	$error = stream_get_contents($pipes[2]);
	fclose($pipes[1]);
	fclose($pipes[2]);
	$status = proc_close($process);
	expect($status)->toBe($expected);
	expect($output)->toContain('FATAL:');
	expect($output)->not->toContain('UNREACHABLE');
	expect($error)->toBe('');
})->with('structure RRA fatal branches');
