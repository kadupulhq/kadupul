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
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

function read_theme_jquery_ui_css(string $theme): string {
	$path = dirname(__DIR__, 4) . "/include/themes/$theme/jquery-ui.css";

	expect(is_file($path))->toBeTrue("Missing jquery-ui.css for theme [$theme]");

	$css = file_get_contents($path);

	expect($css)->not->toBeFalse();

	return $css;
}

test('all in-tree theme jquery ui bundles are aligned to 1.14.x', function () {
    $directories = glob(dirname(__DIR__, 4) . '/include/themes/*', GLOB_ONLYDIR);
    $themes = array_map('basename', $directories);
    expect($themes)->toContain('classic', 'modern', 'midwinter');

	foreach ($themes as $theme) {
		$css = read_theme_jquery_ui_css($theme);

		expect($css)->toMatch('/jQuery UI - v1\.14\./')
			->and($css)->not->toContain('jQuery UI - v1.12.1');
	}
});

test('every shipped theme retains the widget selectors required by filters and dialogs', function () {
    foreach (glob(dirname(__DIR__, 4) . '/include/themes/*/jquery-ui.css') as $path) {
        $css = file_get_contents($path);
        expect($css)->toContain('.ui-selectmenu-button', '.ui-dialog', '.ui-button', '.ui-menu')
            ->and($css)->not->toContain('-webkit-tap-highlight-color: 1px solid');
    }
});
