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
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * Installer::installTemplate() lets shipped packages import every Data Input
 * Method when IN_CACTI_INSTALL is defined. api_plugin_install() defines that
 * constant too, for a plugin install in a web request. These checks keep the
 * decision on the installer's own path: installTemplate() is reachable only
 * through Installer::install(), plugin code never drives the Installer, and no
 * other caller passes import_package() a decision.
 */

$root = dirname(__DIR__, 3);

$installerSource = file_get_contents($root . '/lib/installer.php');
$pluginsSource   = file_get_contents($root . '/lib/plugins.php');

test('installTemplate() and install() are private to the Installer', function () use ($installerSource) {
	expect($installerSource)->toContain('private function installTemplate(')
		->and($installerSource)->toContain('private function install(');
});

test('installTemplate() is called only from Installer::install()', function () use ($installerSource) {
	$start = strpos($installerSource, 'private function install(');
	$end   = strpos($installerSource, "\n\t}\n", $start);
	$body  = substr($installerSource, $start, $end - $start);

	expect(substr_count($installerSource, '->installTemplate('))->toBe(substr_count($body, '->installTemplate('))
		->and(substr_count($body, '->installTemplate('))->toBeGreaterThan(0);
});

test('a plugin install defines IN_PLUGIN_INSTALL but never drives the Installer or a package import', function () use ($pluginsSource) {
	expect($pluginsSource)->toContain("define('IN_PLUGIN_INSTALL', 1);")
		->and($pluginsSource)->not->toContain('Installer')
		->and($pluginsSource)->not->toContain('import_package(')
		->and($pluginsSource)->not->toContain('installTemplate');
});

test('only the installer passes import_package() a data input decision', function () use ($root) {
	$callers = array();

	$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

	foreach ($files as $file) {
		$path = substr($file->getPathname(), strlen($root) + 1);

		if (substr($path, -4) !== '.php' || strpos($path, 'tests/') === 0 || strpos($path, 'include/vendor/') === 0 || strpos($path, 'plugins/') === 0) {
			continue;
		}

		/* calls, not the comments that mention import_package() */
		if (preg_match_all('/^\s*\$\w+\s*=\s*import_package\((.*)\);\s*$/m', file_get_contents($file->getPathname()), $matches)) {
			foreach ($matches[1] as $arguments) {
				if (strpos($arguments, 'data_input_allowed') !== false) {
					$callers[] = $path;
				}
			}
		}
	}

	expect($callers)->toBe(array('lib/installer.php'));
});
