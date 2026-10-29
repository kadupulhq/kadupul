<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

$graphXportSource = file_get_contents(__DIR__ . '/../../graph_xport.php');

test('GHSA-977w-79m7-xjc4: HTML preview escapes the graph title', function () use ($graphXportSource) {
	// title_cache is attacker-controllable via the graph template and
	// was echoed raw into the preview table on the vulnerable branch.
	expect($graphXportSource)->toContain("html_escape(\$xport_array['meta']['title_cache'])");
});

test('GHSA-977w-79m7-xjc4: HTML preview escapes each legend column', function () use ($graphXportSource) {
	// Per-column legends are joined into <th> cells; they need the same
	// escaping path as the title to stop reflected XSS.
	expect($graphXportSource)->toContain("html_escape(\$xport_array['meta']['legend']['col' . \$i])");
});

require_once __DIR__ . '/../Helpers/GraphXport.php';

test('GHSA-977w-79m7-xjc4: CSV legend export applies RFC 4180 quote doubling', function () {
	[$csv] = \Kadupul\Tests\GraphXport\runExport(\Kadupul\Tests\GraphXport\exportMeta(array('legend' => array('col1' => "In\r\n\"bound\",value"))));
	$rows = \Kadupul\Tests\GraphXport\parseCsv($csv);
	$header = $rows[array_key_last($rows) - 1];

	expect($header)->toBe(array('Date', 'In  "bound",value'))
		->and($csv)->toContain(',"In  ""bound"",value"');
});

test('GHSA-977w-79m7-xjc4: CSV cells are wrapped in quotes after sanitisation', function () {
	$legend = "safe\",\"injected\"\n\"row";
	[$csv] = \Kadupul\Tests\GraphXport\runExport(\Kadupul\Tests\GraphXport\exportMeta(array('legend' => array('col1' => $legend))));
	$rows = \Kadupul\Tests\GraphXport\parseCsv($csv);
	$header = $rows[array_key_last($rows) - 1];

	expect($header)->toBe(array('Date', 'safe","injected" "row'))
		->and($rows[array_key_last($rows)])->toBe(array('1970-01-01 00:00:00', '1'));
});
