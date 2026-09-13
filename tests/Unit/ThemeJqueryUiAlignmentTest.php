<?php
/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

function read_theme_jquery_ui_css(string $theme): string {
	$path = dirname(__DIR__, 2) . "/include/themes/$theme/jquery-ui.css";

	expect(is_file($path))->toBeTrue("Missing jquery-ui.css for theme [$theme]");

	$css = file_get_contents($path);

	expect($css)->not->toBeFalse();

	return $css;
}

test('all in-tree theme jquery ui bundles are aligned to 1.14.x', function () {
	$themes = array_map('basename', glob(dirname(__DIR__, 2) . '/include/themes/*', GLOB_ONLYDIR));

	expect($themes)->not->toBeEmpty();

	foreach ($themes as $theme) {
		$css = read_theme_jquery_ui_css($theme);

		expect($css)->toMatch('/jQuery UI - v1\.14\./')
			->and($css)->not->toContain('jQuery UI - v1.12.1');
	}
});
