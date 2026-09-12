<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 +-------------------------------------------------------------------------+
 +-------------------------------------------------------------------------+
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

test('midwinter keeps its custom selectmenu overrides in valid css', function () {
	$css = read_theme_jquery_ui_css('midwinter');

	expect($css)->toContain('button.ui-multiselect,')
		->and($css)->toContain('max-width: 25rem !important;')
		->and($css)->toContain('.ui-selectmenu-button.ui-button:focus-visible')
		->and($css)->toContain('.ui-button.ui-state-active:focus-within')
		->and($css)->toContain('background: var(--background-progress);')
		->and($css)->not->toContain('&:focus-within')
		->and($css)->not->toContain('-webkit-tap-highlight-color: 1px solid');
});
