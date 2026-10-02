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

function theme_asset_root(): string {
	return dirname(__DIR__, 4);
}

function theme_stylesheets(): array {
	$root  = theme_asset_root();
	$files = array_merge(
		glob($root . '/include/themes/*/main.css'),
		glob($root . '/include/themes/midwinter/css/*/*.css')
	);

	sort($files);

	return $files;
}

/* Browsers drop a declaration whose value they cannot parse, so these
 * findings never render; they only hide what the author meant. */
function theme_css_defects(string $css): array {
	$defects = array();
	$css     = preg_replace('#/\*.*?\*/#s', '', $css);

	if (strpos($css, '*/') !== false) {
		$defects[] = 'comment terminator outside a comment';
	}

	preg_match_all('/\{([^{}]*)\}/', $css, $blocks);

	foreach ($blocks[1] as $block) {
		foreach (explode(';', $block) as $declaration) {
			if (strpos($declaration, ':') === false) {
				continue;
			}

			list($property, $value) = array_map('trim', explode(':', $declaration, 2));

			if (preg_match('/(^|-)color$/', $property) && preg_match('/^show\b/i', $value)) {
				$defects[] = "$property: $value";
			}

			preg_match_all('/#([0-9A-Za-z_-]+)/', $value, $hashes);

			foreach ($hashes[1] as $hash) {
				if (!preg_match('/^([0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $hash)) {
					$defects[] = "$property: $value";
				}
			}
		}
	}

	return $defects;
}

test('shipped theme stylesheets carry no declarations the browser discards', function () {
	$files = theme_stylesheets();

	expect(count($files))->toBeGreaterThan(7);

	foreach ($files as $file) {
		$relative = substr($file, strlen(theme_asset_root()) + 1);

		expect(theme_css_defects(file_get_contents($file)))->toBe(array(), $relative);
	}
});

test('the stylesheet check reports each kind of discarded declaration', function ($css, $defect) {
	expect(theme_css_defects($css))->toBe(array($defect));
})->with(array(
	'unknown colour keyword' => array(".a:hover {\n\tcolor: show;\n}", 'color: show'),
	'named colour after a hash' => array(".a {\n\tcolor: #white;\n}", 'color: #white'),
	'five digit hex' => array(".a {\n\tbackground-color: #12345;\n}", 'background-color: #12345'),
	'stray comment terminator' => array(".a {\n\tborder-color: #222;\n\t*/\n\tbox-shadow: none;\n}", 'comment terminator outside a comment'),
));

test('the stylesheet check accepts valid colours and commented declarations', function () {
	$css = "/* header */\n.a {\n\tcolor: #fff;\n\tbackground: #1a2b3c80;\n\t/* color: #white; */\n}\n.b:hover { color: white; empty-cells: show; }";

	expect(theme_css_defects($css))->toBe(array());
});

test('every icon a theme script inserts exists in the shipped Font Awesome', function () {
	$root    = theme_asset_root();
	$css     = file_get_contents($root . '/include/fa/css/all.css');
	$regular = file_get_contents($root . '/include/fa/webfonts/fa-regular-400.svg');
	$scripts = glob($root . '/include/themes/*/main.js');

	/* sunrise/main.css hides the login and logout logos that hold this icon */
	$hidden = array('sunrise' => array('fa-sun-o'));

	expect(file_get_contents($root . '/include/themes/sunrise/main.css'))->toMatch('/\.cactiLoginLogo, \.cactiLogoutLogo \{\s*display: none;/');

	expect($scripts)->not->toBe(array());

	foreach ($scripts as $script) {
		$theme = basename(dirname($script));

		preg_match_all('/<i class=[\'"]([^\'"]+)[\'"]/', file_get_contents($script), $markup);

		foreach ($markup[1] as $classes) {
			$classes = preg_split('/\s+/', trim($classes));

			foreach ($classes as $class) {
				if (!preg_match('/^fa-[a-z0-9-]+$/', $class) || in_array($class, $hidden[$theme] ?? array(), true)) {
					continue;
				}

				expect(strpos($css, ".$class:before") !== false)->toBeTrue("$theme uses $class");

				/* The regular weight is a subset in the free set */
				if (in_array('far', $classes, true) && !array_intersect(array('fa', 'fas'), $classes)) {
					expect(strpos($regular, 'glyph-name="' . substr($class, 3) . '"') !== false)->toBeTrue("$theme uses far $class");
				}
			}
		}
	}
});
