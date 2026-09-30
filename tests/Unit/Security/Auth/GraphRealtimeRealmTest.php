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
 * graph_realtime.php refuses every poll and image without the Realtime realm
 * (25). The single graph page still offered the Realtime button when either
 * real-time was enabled or the user held the realm, where the graph list in
 * lib/html.php asks for both. The pop-out page itself, and the POST that
 * saves the real-time preferences from it, did not check the realm at all.
 */

namespace GraphRealtimeRealmTest;

require_once dirname(__DIR__, 3) . '/Helpers/GraphRealtimeHarness.php';

class State {
	public static $enabled = '';
	public static $realm   = false;
}

function read_config_option($name) {
	return $name === 'realtime_enabled' ? State::$enabled : '';
}

function is_realm_allowed($realm) {
	return $realm === 25 && State::$realm;
}

function realtime_button_condition(string $file) : string {
	$source = file_get_contents(dirname(__DIR__, 4) . '/' . $file);

	if (preg_match_all("/if \((read_config_option\('realtime_enabled'\)[^{]*?is_realm_allowed\(25\))\) \{/", $source, $matches) !== 1) {
		throw new \RuntimeException('Missing production Realtime button condition in ' . $file);
	}

	return $matches[1][0];
}

function realtime_button_shown(string $condition, string $enabled, bool $realm) : bool {
	State::$enabled = $enabled;
	State::$realm   = $realm;

	return eval('namespace GraphRealtimeRealmTest; return (bool) (' . $condition . ');');
}

dataset('realtime button cases', array(
	'enabled with realm'       => array('on', true, true),
	'enabled without realm'    => array('on', false, false),
	'disabled with realm'      => array('', true, false),
	'disabled without realm'   => array('', false, false),
));

test('the single graph page shows the Realtime button only with real-time enabled and the realm', function ($enabled, $realm, $shown) {
	expect(realtime_button_shown(realtime_button_condition('graph.php'), $enabled, $realm))->toBe($shown)
		->and(realtime_button_shown(realtime_button_condition('lib/html.php'), $enabled, $realm))->toBe($shown);
})->with('realtime button cases');

$popRequest = array('local_graph_id' => 5, 'ds_step' => 30, 'graph_start' => -300, 'size' => 50, 'graph_nolegend' => 'true', 'top' => 0, 'left' => 0);
$popConfig  = array('realtime_enabled' => 'on', 'realtime_interval' => '10');

test('the pop-out page is refused without the Realtime realm', function () use ($popRequest, $popConfig) {
	foreach (array('GET', 'POST') as $method) {
		$run = \graph_realtime_run(array('method' => $method, 'request' => $popRequest, 'allowed' => array(5), 'realms' => array(), 'config' => $popConfig));

		expect($run['exitCode'])->toBe(0)
			->and($run['stdout'])->toStartWith('<!DOCTYPE html>')
			->and($run['stdout'])->toContain('<strong>Permission Denied</strong>')
			->and($run['stdout'])->not->toContain("id='gform'")
			->and($run['calls']['settings'])->toBe(array())
			->and($run['polls'])->toBe(array());
	}
});

test('the pop-out page saves no preferences without the Realtime realm while real-time is disabled', function () use ($popRequest, $popConfig) {
	$config = array('realtime_enabled' => '') + $popConfig;

	$run = \graph_realtime_run(array('method' => 'POST', 'request' => $popRequest, 'allowed' => array(5), 'realms' => array(), 'config' => $config));

	expect($run['stdout'])->toContain('Real-time has been disabled')
		->and($run['calls']['settings'])->toBe(array());
});

test('the pop-out page still saves preferences by POST with the Realtime realm', function () use ($popRequest, $popConfig) {
	$config = array('realtime_enabled' => '') + $popConfig;

	$run = \graph_realtime_run(array('method' => 'POST', 'request' => $popRequest, 'allowed' => array(5), 'realms' => array(25), 'config' => $config));

	expect($run['calls']['settings'])->toBe(array('realtime_interval' => 30, 'realtime_gwindow' => 300, 'realtime_size' => 50, 'realtime_nolegend' => 'true'));
});
