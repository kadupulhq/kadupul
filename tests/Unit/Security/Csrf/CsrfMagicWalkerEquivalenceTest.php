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
 * The output handler skips plain tags with a regular expression and reads
 * the rest with csrf_parse_tag(). These tests run it and the reference
 * walker, which reads every tag, over the same pages and require the same
 * rewritten output.
 */

$basePath = dirname(__DIR__, 4);
$GLOBALS['config'] = array(
	'base_path'    => $basePath,
	'include_path' => $basePath . '/include',
	'is_web'       => false,
);
$config = $GLOBALS['config'];

require_once($basePath . '/include/csrf.php');
require_once($basePath . '/tests/Helpers/CsrfMagicReferenceWalker.php');

const CSRF_WALKER_FIELD = '{FIELD}';

function csrf_walker_mismatches(array $pages) {
	$mismatches = array();

	foreach ($pages as $name => $page) {
		$expected = csrf_reference_rewrite_forms($page, CSRF_WALKER_FIELD);
		if (csrf_rewrite_forms($page, CSRF_WALKER_FIELD) !== $expected) {
			$mismatches[] = $name . ': ' . substr(json_encode($page), 0, 300);
		}
	}

	return $mismatches;
}

/*
 * Random tag soup from fragments that exercise every branch of the walker:
 * quotes in and out of values, "=" in odd places, raw-text elements and
 * their end tags, comments, bogus comments, nested forms, base elements and
 * markup the walker stops at.
 */
function csrf_walker_soup($count, $seed) {
	$fragments = array(
		'<form method=post>', "<form method='post' action='x.php'>", '<FORM METHOD="POST">', '</form>', '</FORM >',
		"<form method='post' action='//evil.example/'>", "<form method=post action='&#47;&#47;evil'>", '<formx method=post>',
		"<base href='https://evil.example/'>", "<base href='/cacti/'>", "<base href='/x/", '<select>', '</select>',
		'<template>', '</template>', '<textarea>', '</textarea>', '</textareax>', '<title>', '</title>', '<script>',
		'</script>', '<style>', '</style>', '<xmp>', '</xmp>', '<iframe>', '</iframe>', '<noembed>', '<noframes>',
		'<!--', '-->', '--!>', '<!-->', '<!--->', '<!DOCTYPE html>', '<?x ?>', '<![CDATA[', ']]>', '</>', '</ x>',
		'<svg>', '<noscript>', '<math>', '<plaintext>', '<frameset>', '</svg>', '<tr>', '<td class="a">', "<a href='x'>",
		'</a>', '<p title="', '">', "<p title='", "'>", '<div a=b>', '<div a="x"b="y">', '<div a ="x>">', '<div ="x>">',
		'<i a="x"=y>', "<span a='>'>", '<b a=x\'y>', '<p/>', '<p/a="b">', '<input name=action value=save>', '<br>',
		'<', '>', '"', "'", '=', '/', ' ', "\n", "\t", 'text', '&amp;', '<1', '< p>', '<a', '<!', '</', "<a\tb='c'>",
		"<td\nclass='x'>", '<x =y>', '<x a= "q">', "<x a=\t'q'>", '<x a=>', '<x a b c>',
	);
	mt_srand($seed);
	$pages = array();

	for ($i = 0; $i < $count; $i++) {
		$page = '';
		$length = mt_rand(1, 40);
		for ($j = 0; $j < $length; $j++) {
			$page .= $fragments[mt_rand(0, count($fragments) - 1)];
		}

		$pages['soup ' . $seed . '-' . $i] = $page;
	}

	return $pages;
}

function csrf_walker_device_page($rows) {
	$row = "<tr class='selectable tableRow' id='line%d'><td class='nowrap'><a class='linkEditMain' href='host.php?action=edit&amp;id=%d'>Device %d</a></td>" .
		"<td class='checkbox'><input type='checkbox' class='checkbox' id='chk_%d' name='chk_%d' title='x'><label class='formCheckboxLabel' for='chk_%d'></label></td></tr>\n";
	$page = "<!DOCTYPE html><html><head><title>Devices</title></head><body><form id='form_devices' action='host.php'><input type='text' id='filter'></form>" .
		"<form class='cactiForm' method='post' action='host.php'><table class='cactiTable'>";
	for ($i = 0; $i < $rows; $i++) {
		$page .= sprintf($row, $i, $i, $i, $i, $i, $i);
	}

	return $page . "</table></form><script type='text/javascript'>var x = '<form method=\"post\">';</script><form method='post'></form></body></html>";
}

test('the walkers agree on the probe pages and every form tag in the source', function () use ($basePath) {
	$corpus = require($basePath . '/tests/fixtures/csrf_magic_rewrite_corpus.php');

	expect(count($corpus))->toBeGreaterThan(80)
		->and(csrf_walker_mismatches($corpus))->toBe(array());
});

test('the walkers agree on random tag soup', function () {
	foreach (array(1, 2, 3, 4) as $seed) {
		expect(csrf_walker_mismatches(csrf_walker_soup(2500, $seed)))->toBe(array());
	}
});

test('the walkers agree on a large device list', function () {
	$page = csrf_walker_device_page(3000);

	expect(substr_count(csrf_rewrite_forms($page, CSRF_WALKER_FIELD), CSRF_WALKER_FIELD))->toBe(2)
		->and(csrf_walker_mismatches(array('device list' => $page)))->toBe(array());
});

test('the walkers agree when the regular expression hits a PCRE limit', function () {
	$limit = ini_get('pcre.backtrack_limit');
	ini_set('pcre.backtrack_limit', '20');

	try {
		$page = csrf_walker_device_page(50);
		expect(preg_match('~\G(?:<[^>]*+>|[^<]++){0,1000}+~', $page))->toBeFalse()
			->and(csrf_walker_mismatches(array('device list' => $page) + csrf_walker_soup(300, 5)))->toBe(array());
	} finally {
		ini_set('pcre.backtrack_limit', $limit);
	}
});
