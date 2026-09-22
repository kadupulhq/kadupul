<?php

/* Copyright (C) 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later */

namespace HostFilterAccessibleLabelTest;

require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';
class State {
	public static $theme = 'classic';
	public static $autocomplete = false;
}
function get_selected_theme() { return State::$theme; }
function read_config_option($name) { return State::$autocomplete; }
function isset_request_var($name) { return false; }
function get_allowed_devices($where) { return array(array('id' => 7, 'description' => 'Device seven')); }
function cacti_sizeof($value) { return count($value); }
function __($value) { return $value; }
function strip_domain($value) { return $value; }
function html_escape($value) { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
function db_fetch_cell_prepared($sql, $params) { return 'Device seven'; }
$source = file_get_contents(dirname(__DIR__, 4) . '/lib/html.php');
eval('namespace HostFilterAccessibleLabelTest; ' . \test_php_function_source($source, 'html_host_filter'));

test('device filter labels the visible control for every supported rendering mode', function ($theme, $enabled, $id) {
	State::$theme = $theme;
	State::$autocomplete = $enabled;
	ob_start();
	try {
		html_host_filter($id);
		$html = ob_get_contents();
	} finally {
		ob_end_clean();
	}
	$doc = new \DOMDocument();
	$doc->loadHTML('<table><tr>' . $html . '</tr></table>');
	$labels = $doc->getElementsByTagName('label');
	expect($labels->length)->toBe(1);
	$target = $theme !== 'classic' && $enabled ? 'host' : 'host_id';
	expect($labels->item(0)->getAttribute('for'))->toBe($target);
	expect($labels->item(0)->textContent)->toBe('Device');
	$control = (new \DOMXPath($doc))->query('//*[@id="' . $target . '"]')->item(0);
	expect($control)->not->toBeNull();
	expect($control->getAttribute('type'))->not->toBe('hidden');
})->with(array(array('classic', false), array('classic', true), array('modern', false), array('modern', true)))
	->with(array(-1, 0, 7));
