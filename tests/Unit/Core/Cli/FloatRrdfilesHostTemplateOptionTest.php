<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/*
 * float_rrdfiles.php parsed --host-template_id while its usage line, its
 * option list and its own validation message all say --host-template-id. The
 * documented spelling fell through to the default branch, printed "Invalid
 * Parameter" and exited 1, so the only way to filter by Device Template was a
 * spelling that appears nowhere in the help.
 *
 * The underscore form stays accepted; anything already scripted against it
 * keeps working.
 */

namespace FloatRrdfilesHostTemplateOptionTest;

require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';

function float_source() : string {
	$source = file_get_contents(dirname(__DIR__, 4) . '/cli/float_rrdfiles.php');

	expect($source)->not->toBeFalse();

	return $source;
}

/** Option names the argument switch has a case for. */
function accepted_options(string $source) : array {
	preg_match_all("/case '(--[a-z0-9_-]+)':/", $source, $matches);

	return array_values(array_unique($matches[1]));
}

/** Long options the help text tells the operator to use. */
function documented_options(string $source) : array {
	// Tokenised extraction, because the definition is `display_help ()` and a
	// brace- or regex-based grab of the body drifts with the spacing.
	$help = \test_php_function_source($source, 'display_help');

	expect($help)->not->toBeEmpty();

	preg_match_all('/(--[a-z][a-z0-9-]+)/', $help, $matches);

	return array_values(array_unique($matches[1]));
}

it('accepts the device template option the help documents', function () {
	expect(accepted_options(float_source()))->toContain('--host-template-id');
});

it('still accepts the undocumented underscore spelling', function () {
	expect(accepted_options(float_source()))->toContain('--host-template_id');
});

it('parses every long option its help documents', function () {
	$source   = float_source();
	$accepted = accepted_options($source);

	$undocumented = array();

	foreach (documented_options($source) as $option) {
		if (!in_array($option, $accepted, true)) {
			$undocumented[] = $option;
		}
	}

	expect($undocumented)->toBe(array());
});
