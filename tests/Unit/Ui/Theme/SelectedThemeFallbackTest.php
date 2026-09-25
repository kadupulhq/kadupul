<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 | Copyright (C) 2026 The Kadupul project and contributors                 |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 +-------------------------------------------------------------------------+
*/

namespace Kadupul\Tests\ThemeSelectedFallback;

use PHPUnit\Framework\TestCase;

function read_config_option($name) {
	return $GLOBALS['theme_selected_fallback']['configured'];
}

function db_table_exists($table) {
	return true;
}

function db_fetch_cell_prepared($query, $params) {
	return $GLOBALS['theme_selected_fallback']['user_theme'];
}

function db_execute_prepared($query, $params) {
	$GLOBALS['theme_selected_fallback']['updates'][] = $params;

	return true;
}

function file_exists($path) {
	return in_array($path, $GLOBALS['theme_selected_fallback']['files'], true);
}

$source = file_get_contents(dirname(__DIR__, 4) . '/lib/functions.php');
if ($source === false || preg_match('/^function get_selected_theme\(\).*?^}\R/ms', $source, $matches) !== 1) {
	throw new \RuntimeException('Unable to extract get_selected_theme() from lib/functions.php');
}

eval('namespace Kadupul\\Tests\\ThemeSelectedFallback;' . $matches[0]); // nosemgrep: php.lang.security.eval-use.eval-use

final class SelectedThemeFallbackTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['config'] = array('base_path' => '/cacti');
		$GLOBALS['themes'] = array('classic' => 'Classic');
		$GLOBALS['theme_selected_fallback'] = array(
			'configured' => 'invalid/theme',
			'user_theme' => '../../etc/passwd',
			'files' => array(),
			'updates' => array(),
		);
		$_SESSION = array('sess_user_id' => 7);
	}

	public function testInvalidPersistedThemeFallsBackToClassicWhenNoThemeHasAStylesheet(): void {
		self::assertSame('classic', get_selected_theme());
		self::assertSame('classic', $_SESSION['selected_theme']);
		self::assertSame(array(array('classic', 7)), $GLOBALS['theme_selected_fallback']['updates']);
	}

	public function testInvalidConfiguredThemeFallsBackWithoutLoggedInUser(): void {
		$GLOBALS['theme_selected_fallback']['user_theme'] = '';
		$_SESSION = array();

		self::assertSame('classic', get_selected_theme());
		self::assertSame('classic', $_SESSION['selected_theme']);
		self::assertSame(array(), $GLOBALS['theme_selected_fallback']['updates']);
	}

	public function testInvalidScalarAndNonScalarSessionThemesFallBackSafely(): void {
		$GLOBALS['theme_selected_fallback']['user_theme'] = '';
		unset($_SESSION['sess_user_id']);

		foreach (array('../../etc/passwd', array('unexpected')) as $requested_theme) {
			$_SESSION['selected_theme'] = $requested_theme;

			self::assertSame('classic', get_selected_theme());
			self::assertSame('classic', $_SESSION['selected_theme']);
		}

		self::assertSame(array(), $GLOBALS['theme_selected_fallback']['updates']);
	}
}
