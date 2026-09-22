<?php

/* Copyright (C) 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later */

namespace SharedPageTitleTest;

require_once __DIR__ . '/../../../Helpers/PhpSource.php';

const CACTI_VERSION = 'LTS fixture';
class CactiSecureHeaders {
	public static function getNonceAttribute() { return ''; }
}
class State {
	public static $theme = 'classic';
}
function get_selected_theme() { return State::$theme; }
function read_user_setting($name, $default = '') { return $default; }
function read_config_option($name) { return ''; }
function is_view_allowed($name) { return false; }
function is_realm_allowed($name) { return false; }
function __($text, ...$args) { return $args ? vsprintf($text, $args) : $text; }
function __esc($text) { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
function get_md5_include_css($path) { return ''; }
function get_md5_include_js($path, $optional = false) { return ''; }
function api_plugin_hook($name) {}
function api_plugin_hook_function($name, $value) { return $value; }

$helperSource = test_php_function_source(file_get_contents(dirname(__DIR__, 4) . '/lib/html.php'), 'html_common_header');
eval('namespace SharedPageTitleTest; ' . $helperSource);

dataset('shared title pages', array(
	'login' => array('auth_login.php', 'Login to Cacti'),
	'password' => array('auth_changepassword.php', 'Change Password'),
	'installer' => array('install/install.php', 'Cacti Server vLTS fixture - Maintenance'),
	'realtime' => array('graph_realtime.php', 'Cacti Real-time Graphing'),
	'console' => array('include/top_header.php', 'Fixture navigation'),
	'general' => array('include/top_general_header.php', 'Fixture navigation'),
	'graphs' => array('include/top_graph_header.php', 'Fixture navigation')
));

test('each reported head emits exactly one title through the production shared helper', function ($file, $expected, $theme) {
	$GLOBALS['config'] = array('url_path' => '/');
	State::$theme = $theme;
	$page_title = 'Fixture navigation';
	$source = file_get_contents(dirname(__DIR__, 4) . '/' . $file);
	expect(preg_match_all('/html_common_header\([^\n]*\);/', $source, $matches))->toBe(1);
	ob_start();
	try {
		eval('namespace SharedPageTitleTest; ' . $matches[0][0]);
		$markup = ob_get_contents();
	} finally {
		ob_end_clean();
	}
	$doc = new \DOMDocument();
	$doc->loadHTML('<!doctype html><html><head>' . $markup . '</head><body></body></html>');
	$titles = $doc->getElementsByTagName('title');
	expect($titles->length)->toBe(1);
	expect($titles->item(0)->textContent)->toBe($expected);
})->with('shared title pages')->with(array('classic', 'modern'));
