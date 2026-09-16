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
    expect($themes)->toContain('classic')->toContain('modern')->toContain('midwinter');

	foreach ($themes as $theme) {
		$css = read_theme_jquery_ui_css($theme);

		expect($css)->toMatch('/jQuery UI - v1\.14\./')
			->and($css)->not->toContain('jQuery UI - v1.12.1');
	}
});

test('every shipped theme retains the widget selectors required by filters and dialogs', function () {
    foreach (glob(dirname(__DIR__, 4) . '/include/themes/*/jquery-ui.css') as $path) {
        $css = file_get_contents($path);
        expect($css)->toContain('.ui-selectmenu-button')->toContain('.ui-dialog')->toContain('.ui-button')->toContain('.ui-menu')
            ->and($css)->not->toContain('-webkit-tap-highlight-color: 1px solid');
    }
});


// Reviewed shipped bundles: changes require checking the complete CSS diff,
// including Midwinter's theme rules, before updating these reference hashes.
test('shipped theme bundles match their reviewed reference content', function ($theme, $digest) {
    expect(hash('sha256', read_theme_jquery_ui_css($theme)))->toBe($digest);
})->with(array(
    array('classic', 'fbf38a6a4caaeb6fe938c241f9f7e096a62a0a3b2c25da5eddd49d4e79987258'),
    array('modern', 'b41c951087c7eec66678b6e6ab54d6e27109e5c058b241ac4aa92d569f104dea'),
    array('midwinter', 'c6b55b7b337d6b1eaaa870056eddd3c7712439e5db11dd13581353e4998025f1'),
    array('paw', 'b895be0b91960fa951fd13dfbaa50adaae24427495a1f49bedce6a293e36f055'),
    array('dark', 'f358625c8f5fb489ddd894010df50e3ff657f10c9b25cebab716be31c854852d'),
    array('sunrise', 'c6b55b7b337d6b1eaaa870056eddd3c7712439e5db11dd13581353e4998025f1'),
    array('paper-plane', '1806948ccae44528b12468559bd5b6ab29a735b00611c67b195029cbe86ea86b')
));
