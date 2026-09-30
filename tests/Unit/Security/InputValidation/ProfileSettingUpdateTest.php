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
 * auth_profile.php?action=update_data saves one user setting per request as
 * the settings form changes. It stored any value for any known setting and
 * skipped the graph_settings permission that form_save() checks, so a user
 * could store text that later reaches script blocks, HTML attributes and
 * rrdtool, or change settings the form never showed them.
 *
 * The shipped functions run in a child process with the database and
 * permission helpers stubbed. The field definitions mirror
 * include/global_settings.php.
 */

require_once dirname(__DIR__, 3) . '/Helpers/AuthEntryProbe.php';

function profile_setting_update(string $name, $value, bool $graph_settings = true) : array {
	$profile = file_get_contents(dirname(__DIR__, 4) . '/auth_profile.php');

	$source = <<<'PHP'
<?php
$scenario = json_decode(stream_get_contents(STDIN), true);

$_SESSION = array('sess_user_id' => 42);
$GLOBALS['writes'] = array();

$settings_user = array(
	'general' => array(
		'selected_theme'   => array('method' => 'drop_array', 'default' => 'modern', 'array' => array('classic' => 'Classic', 'modern' => 'Modern')),
		'show_graph_title' => array('method' => 'checkbox', 'default' => ''),
		'page_refresh'     => array('method' => 'drop_array', 'default' => '300', 'array' => array(15 => '15 Seconds', 300 => '5 Minutes')),
		'user_language'    => array('method' => 'drop_language', 'default' => 'en-US', 'array' => array('en-US' => 'English', 'de-DE' => 'German')),
	),
	'timespan' => array(
		'default_rra_id'  => array('method' => 'drop_sql', 'sql' => 'SELECT id, name FROM data_source_profiles_rra ORDER BY steps', 'default' => '1'),
		'day_shift_start' => array('method' => 'textbox', 'default' => '07:00', 'max_length' => '5'),
	),
	'tree' => array(
		'default_tree_id' => array('method' => 'drop_sql', 'sql' => 'SELECT id,name FROM graph_tree ORDER BY name', 'default' => '0'),
		'min_tree_width'  => array('method' => 'textbox', 'default' => '170', 'max_length' => '5'),
	),
	'thumbnail' => array(
		'thumbnail_sections' => array('method' => 'checkbox_group', 'items' => array('thumbnail_section_preview' => array('default' => 'on'))),
	),
	'fonts' => array(
		'title_font' => array('method' => 'font', 'max_length' => '100'),
	),
);

function is_view_allowed($view) {
	return $view == 'graph_settings' && $GLOBALS['scenario']['graph_settings'];
}

function is_tree_allowed($tree_id, $user_id = 0) {
	return in_array((int) $tree_id, array(4), true);
}

function db_fetch_assoc($sql) {
	return array(array('id' => 1, 'name' => 'Daily'), array('id' => 2, 'name' => 'Weekly'));
}

function db_execute_prepared($sql, $params = array()) {
	$GLOBALS['writes'][] = $params;

	return true;
}

function cacti_sizeof($array) {
	return is_array($array) ? count($array) : 0;
}

function kill_session_var($name) {
	unset($_SESSION[$name]);
}

PHP;

	foreach (array('api_auth_update_user_setting', 'api_auth_user_setting_valid') as $function) {
		$source .= cacti_test_function_source($profile, $function) . "\n\n";
	}

	$source .= "api_auth_update_user_setting(\$scenario['name'], \$scenario['value']);\n";
	$source .= "print json_encode(array('writes' => \$GLOBALS['writes']));\n";

	return cacti_test_run_php_source($source, array('name' => $name, 'value' => $value, 'graph_settings' => $graph_settings))['writes'];
}

test('values the settings form offers are saved', function () {
	$accepted = array(
		array('selected_theme', 'classic'),
		array('show_graph_title', 'on'),
		array('show_graph_title', ''),
		array('page_refresh', '15'),
		array('user_language', 'de-DE'),
		array('default_rra_id', '2'),
		array('default_tree_id', '4'),
		array('default_tree_id', '0'),
		array('day_shift_start', '08:30'),
		array('min_tree_width', '200'),
		array('title_font', 'DejaVu Sans'),
	);

	foreach ($accepted as $case) {
		expect(profile_setting_update($case[0], $case[1]))->toBe(array(array($case[0], $case[1], 42)), $case[0]);
	}
});

test('values the settings form could not submit are dropped', function () {
	$refused = array(
		array('min_tree_width', '1;alert(document.domain)//'),
		array('min_tree_width', '123456'),
		array('page_refresh', '1;alert(1)'),
		array('show_graph_title', 'yes'),
		array('selected_theme', '../../include'),
		array('user_language', 'xx-XX'),
		array('default_rra_id', '99'),
		array('default_rra_id', '1 OR 1=1'),
		array('default_tree_id', '5'),
		array('day_shift_start', '07:00:00'),
	);

	foreach ($refused as $case) {
		expect(profile_setting_update($case[0], $case[1]))->toBe(array(), $case[0] . '=' . $case[1]);
	}
});

test('a value that is not a single string is dropped', function () {
	expect(profile_setting_update('show_graph_title', array('on')))->toBe(array());
});

test('a name that is not a user setting is ignored', function () {
	expect(profile_setting_update('admin_user', '1'))->toBe(array())
		->and(profile_setting_update('thumbnail_sections', 'on'))->toBe(array());
});

test('a user without graph_settings can not change settings', function () {
	expect(profile_setting_update('show_graph_title', 'on', false))->toBe(array());
});

test('a user without graph_settings still edits their name and email as before', function () {
	expect(profile_setting_update('full_name', 'Alice Example', false))->toBe(array(array('Alice Example', 42)))
		->and(profile_setting_update('email_address', 'alice@example.com', false))->toBe(array(array('alice@example.com', 42)));
});
