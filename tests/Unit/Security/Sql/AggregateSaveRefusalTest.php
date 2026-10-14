<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-2.0-or-later

it('reports a failed replacement without propagating or reporting success', function ($page, $table) {
	$fixture = dirname(__DIR__, 3) . '/fixtures/aggregate-caller-save-refusal.php';
	$process = proc_open(array(PHP_BINARY, $fixture, $page), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
	expect(is_resource($process))->toBeTrue();
	$output = stream_get_contents($pipes[1]);
	$error = stream_get_contents($pipes[2]);
	fclose($pipes[1]);
	fclose($pipes[2]);
	expect(proc_close($process))->toBe(0);
	expect($error)->toBe('');
	$trace = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
	expect($trace['items'])->toHaveCount(1);
	expect($trace['items'][0]['table'])->toBe($table);
	expect($trace['items'][0]['items'][0]['graph_templates_item_id'])->toBe(7);
	expect($trace['propagation'])->toBe(0);
	expect($trace['generation'])->toBe(0);
	expect($trace['messages'])->toHaveCount(1);
	expect($trace['messages'][0][0])->toBe('aggregate_items_save_failed');
})->with(array(
	array('aggregate_templates.php', 'aggregate_graph_templates_item'),
	array('aggregate_graphs.php', 'aggregate_graphs_graph_item'),
	array('graphs.php', 'aggregate_graphs_graph_item')
));
