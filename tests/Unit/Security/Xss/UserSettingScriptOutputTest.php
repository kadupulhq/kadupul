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
 * Graph pages print the user's tree widths and page refresh into script blocks
 * that carry the CSP nonce, so a stored value that is not a number would run
 * as trusted script, and a non-numeric page refresh raised a TypeError on
 * PHP 8. Each shipped print expression is evaluated here with the setting
 * reader stubbed, so the test checks what the page would actually emit.
 */

namespace UserSettingScriptOutputTest;

$GLOBALS['user_setting_value'] = '';

function read_user_setting($name, $default = false, $force = false, $user = 0) {
	return $GLOBALS['user_setting_value'];
}

function user_setting_sinks() : array {
	$root  = dirname(__DIR__, 4);
	$sinks = array();

	foreach (array('graph_view.php' => 3, 'graph.php' => 1, 'lib/html.php' => 2, 'lib/html_graph.php' => 1) as $file => $expected) {
		preg_match_all("/<\?php print ((?:(?!\?>).)*read_user_setting\('(?:min_tree_width|max_tree_width|page_refresh)'\)(?:(?!\?>).)*);\?>/", file_get_contents($root . '/' . $file), $matches);

		expect($matches[1])->toHaveCount($expected);

		foreach ($matches[1] as $index => $expression) {
			$sinks[$file . ' #' . ($index + 1)] = $expression;
		}
	}

	return $sinks;
}

function user_setting_render(string $expression, string $stored) : string {
	$GLOBALS['user_setting_value'] = $stored;

	return (string) eval('namespace ' . __NAMESPACE__ . '; return ' . $expression . ';');
}

test('a stored value that is not a number cannot leave the script assignment', function () {
	foreach (user_setting_sinks() as $sink => $expression) {
		foreach (array('1;alert(document.domain)//', "1</script><script>alert(1)</script>", '0x10', '-5e3') as $stored) {
			expect(user_setting_render($expression, $stored))->toMatch('/^-?[0-9]+$/', $sink);
		}
	}
});

test('a stored value with no number in it prints a number instead of failing', function () {
	foreach (user_setting_sinks() as $sink => $expression) {
		foreach (array('abc', '', 'on') as $stored) {
			expect(user_setting_render($expression, $stored))->toBe('0', $sink);
		}
	}
});

test('a valid stored value prints what it printed before', function () {
	$sinks = user_setting_sinks();

	expect(user_setting_render($sinks['graph_view.php #1'], '170'))->toBe('170')
		->and(user_setting_render($sinks['graph_view.php #2'], '600'))->toBe('600');

	foreach (array('graph_view.php #3', 'graph.php #1', 'lib/html.php #1', 'lib/html.php #2', 'lib/html_graph.php #1') as $sink) {
		expect(user_setting_render($sinks[$sink], '300'))->toBe('300000', $sink);
	}
});
