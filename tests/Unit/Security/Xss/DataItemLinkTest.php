<?php

/*
 * Copyright (C) 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace DataItemLinkTest;

function get_request_var($name) {
	expect($name)->toBe('id');
	return $GLOBALS['data_item_link_id'];
}

test('item link parameters round-trip without changing action or attribute boundaries', function ($file, $payload) {
	$source = file_get_contents(dirname(__DIR__, 4) . '/' . $file);
	$pattern = "/htmlspecialchars\\(\\s*'(data_sources|data_templates)\\.php\\?action="
		. "(ds_edit|template_edit|rrd_remove|rrd_add)&id='\\s+.*?,\\s*ENT_QUOTES \\| ENT_SUBSTITUTE,\\s*'UTF-8'\\s*\\)/s";
	expect(preg_match_all($pattern, $source, $matches, PREG_SET_ORDER))->toBe($file === 'data_sources.php' ? 3 : 2);
	$template_data_rrd = array('id' => 'item-' . $payload);
	$GLOBALS['data_item_link_id'] = $payload;
	try {
		foreach ($matches as $match) {
			$encoded = eval('namespace DataItemLinkTest; return ' . $match[0] . ';');
			$attribute = $match[2] === 'ds_edit' || $match[2] === 'template_edit' ? 'href' : 'data-url';
			$document = new \DOMDocument();
			$document->loadHTML('<!doctype html><html><head><meta charset="UTF-8"></head><body><a '
				. $attribute . "='" . $encoded . "'>Item</a></body></html>");
			$links = $document->getElementsByTagName('a');
			expect($links->length)->toBe(1);
			expect($links->item(0)->attributes->length)->toBe(1);
			$url = $links->item(0)->getAttribute($attribute);
			expect(parse_url($url, PHP_URL_PATH))->toBe($file);
			expect(parse_url($url, PHP_URL_FRAGMENT))->toBeNull();
			parse_str(parse_url($url, PHP_URL_QUERY), $query);
			$expected = array('action' => $match[2], 'id' => $payload);
			if ($match[2] === 'rrd_remove') {
				$expected['id'] = 'item-' . $payload;
				$expected[$file === 'data_sources.php' ? 'local_data_id' : 'data_template_id'] = $payload;
			} elseif ($match[2] !== 'rrd_add') {
				$expected['view_rrd'] = 'item-' . $payload;
			}
			expect($query)->toBe($expected);
			expect($encoded)->not->toContain(chr(96));
			expect($document->getElementsByTagName('script')->length)->toBe(0);
			expect($document->getElementsByTagName('img')->length)->toBe(0);
		}
	} finally {
		unset($GLOBALS['data_item_link_id']);
	}
})->with(array('data_sources.php', 'data_templates.php'))->with(array(
	'ordinary ID' => '42',
	'leading zeroes' => '0042',
	'empty ID' => '',
	'Unicode' => 'réseau 日本語',
	'attribute boundary' => '\' autofocus onfocus="alert(1)',
	'element boundary' => '\'><img src=x onerror=alert(1)><script>alert(1)</script>',
	'query pollution' => '7&action=rrd_remove&id[]=9#fragment%20+ space',
	'entity text' => '&#39;&quot;&amp;',
	'backtick' => chr(96) . ' value',
));
