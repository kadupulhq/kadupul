<?php

/* Copyright (C) 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later */

require_once __DIR__ . '/../../../Helpers/GraphRealtimeHarness.php';

test('shipped realtime error page exits successfully with complete document metadata', function ($config, $message) {
	$run = graph_realtime_run(array(
		'method' => 'GET',
		'request' => array('local_graph_id' => 5, 'ds_step' => 10, 'graph_start' => -60, 'size' => 100),
		'allowed' => array(5),
		'config' => $config + array('realtime_interval' => '10')
	));
	expect($run['exitCode'])->toBe(0);
	expect($run['stdout'])->toStartWith('<!DOCTYPE html>');
	$doc = new DOMDocument();
	$doc->loadHTML($run['stdout']);
	expect($doc->documentElement->getAttribute('lang'))->toBe('en-US');
	expect($doc->getElementsByTagName('title')->length)->toBe(1);
	expect($doc->getElementsByTagName('title')->item(0)->textContent)->toBe('Cacti Real-time Graphing');
	expect($doc->getElementsByTagName('strong')->item(0)->textContent)->toContain($message);
	expect($run['calls']['settings'])->toBe(array());
})->with(array(
	array(array('realtime_enabled' => ''), 'Real-time has been disabled'),
	array(array('realtime_enabled' => 'on', 'realtime_cache_path' => '/nonexistent-kadupul-realtime-test'),
		'The Image Cache Directory does not exist')
));
