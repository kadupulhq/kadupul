<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2026 The Kadupul project and contributors                 |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 +-------------------------------------------------------------------------+
*/

/*
 * #1038: Apply Suggested Fixes and Rerun Analysis change RRD files and the
 * debugger state, so data_debug.php sends them as POST actions and checks the
 * request method before doing any work. csrf_require_post() keeps the 1.2.31
 * same-origin GET allowance; this test checks that the check comes first.
 */

function data_debug_dispatch($action, $refuse) {
	$source = file_get_contents(dirname(__DIR__, 2) . '/data_debug.php');
	expect($source)->toBeString();
	$start = strpos($source, "switch (get_request_var('action'))");
	expect($start)->not->toBeFalse();
	$source = substr($source, $start);
	$depth = 0;
	$end = null;
	foreach (PhpToken::tokenize('<?php ' . $source) as $token) {
		if ($token->text === '{') {
			$depth++;
		} elseif ($token->text === '}' && --$depth === 0) {
			$end = $token->pos + 1 - strlen('<?php ');
			break;
		}
	}
	expect($end)->not->toBeNull();
	// A namespace lets the stubs replace built-ins such as header().
	$program = <<<'PHP'
namespace DataDebugProbe;
const MESSAGE_LEVEL_INFO = 1;
const MESSAGE_LEVEL_ERROR = 3;
function get_request_var($name) { return $GLOBALS['argv'][1]; }
function get_filter_request_var($name) { return 7; }
function csrf_require_post($strict = false) { echo 'method-check:'; if ($GLOBALS['argv'][2] === 'refuse') { exit(0); } }
function __($text) { return $text; }
function dsdebug_run_repair($id) { echo 'repair:'; return true; }
function debug_delete($items) { echo 'reset:'; }
function debug_rerun($items) { echo 'rerun:'; }
function raise_message($id, $text, $level) { echo 'message:'; }
function header($value) { echo 'redirect'; }
PHP;
	$process = proc_open(array(PHP_BINARY, '-r', $program . substr($source, 0, $end), $action, $refuse ? 'refuse' : 'allow'),
		array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
	expect(is_resource($process))->toBeTrue();
	$output = stream_get_contents($pipes[1]);
	$error = stream_get_contents($pipes[2]);
	fclose($pipes[1]);
	fclose($pipes[2]);
	expect(proc_close($process))->toBe(0, $error)->and($error)->toBe('');

	return $output;
}

test('a refused method stops rerun and repair before any work', function ($action) {
	expect(data_debug_dispatch($action, true))->toBe('method-check:');
})->with(array('run_debug', 'run_repair'));

test('an accepted request still reruns and repairs', function ($action, $expected) {
	expect(data_debug_dispatch($action, false))->toBe($expected);
})->with(array(
	'rerun'  => array('run_debug', 'method-check:reset:rerun:message:redirect'),
	'repair' => array('run_repair', 'method-check:repair:message:reset:rerun:message:redirect'),
));

test('the troubleshooter links submit rerun and repair as POST actions', function () {
	$source = file_get_contents(dirname(__DIR__, 2) . '/data_debug.php');
	expect($source)->toContain("class=\"cactiPostAction linkEditMain\" data-url=\"' . html_escape('data_debug.php?action=run_debug&id=' . \$id) . '\"")
		->and($source)->toContain("class=\"cactiPostAction linkEditMain\" data-url=\"' . html_escape('data_debug.php?action=run_repair&id=' . \$id) . '\"")
		->and($source)->not->toContain("loadPage('data_debug.php?action=run_");
});
