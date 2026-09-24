<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/*
 * cli/import_template.php passed $preview_only as the third argument of
 * import_display_results(), which is $web. Under --preview that selected the
 * web branch, so the CLI printed raw HTML, and $preview stayed false, so the
 * headings claimed the Template had been imported when nothing was written.
 *
 * The call is top-level script code rather than a function, so the statement
 * is read from the file and executed here against a recorder. That keeps the
 * assertion on which parameter each value reaches instead of on the text of
 * the line.
 */

namespace ImportTemplateCliPreviewOutputTest;

function import_display_results($import_debug_info, $filestatus, $web = false, $preview = false) {
	$GLOBALS['itcp_call'] = array(
		'debug'      => $import_debug_info,
		'filestatus' => $filestatus,
		'web'        => $web,
		'preview'    => $preview,
	);
}

/** Run the repository's own call statement with $preview_only set as given. */
function run_call_statement($preview_only) {
	$path   = dirname(__DIR__, 3) . '/cli/import_template.php';
	$source = file_get_contents($path);

	expect($source)->not->toBeFalse();

	preg_match('/^\s*import_display_results\(.*?\);$/m', $source, $match);

	expect($match)->not->toBeEmpty();

	$GLOBALS['itcp_call'] = null;
	$debug_data           = array('sentinel' => true);

	// test-only eval of a statement read from this repository, not external input
	eval('namespace ' . __NAMESPACE__ . '; ' . trim($match[0]));

	return $GLOBALS['itcp_call'];
}

it('renders the preview through the command line branch, not the web one', function () {
	$call = run_call_statement(1);

	expect($call)->not->toBeNull();
	expect($call['web'])->toBeFalsy();
});

it('tells the display that the run was a preview', function () {
	$call = run_call_statement(1);

	expect($call['preview'])->toBeTruthy();
});

it('leaves a real import reporting as an import on both flags', function () {
	$call = run_call_statement(0);

	expect($call['web'])->toBeFalsy();
	expect($call['preview'])->toBeFalsy();
});
