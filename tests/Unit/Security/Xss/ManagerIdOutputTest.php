<?php

/*
 * Copyright (C) 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace ManagerIdOutputTest;

function get_request_var($name) {
	expect($name)->toBe('id');
	return $GLOBALS['manager_id_payload'];
}

function get_filter_request_var($name) {
	return get_request_var($name);
}

function render_id_markup($markup, $payload) {
	$id = $payload;
	$GLOBALS['manager_id_payload'] = $payload;
	ob_start();
	try {
		eval('namespace ManagerIdOutputTest; ?>' . $markup);
		return ob_get_contents();
	} finally {
		ob_end_clean();
		unset($GLOBALS['manager_id_payload']);
	}
}

test('manager IDs round-trip through URL and hidden attribute contexts', function ($context, $payload) {
	$source = file_get_contents(dirname(__DIR__, 4) . '/managers.php');
	$pattern = $context === 'URL'
		? "/strURL\\s*(?:\\+?=)\\s*'[^\\n]*<\\?php\\s+print rawurlencode\\(.*?\\?>[^\\n]*;/s"
		: "/<input type='hidden' (?:name|id)='id' value='<\\?php.*?\\?>'>/s";
	expect(preg_match_all($pattern, $source, $matches))->toBe(3);
	foreach ($matches[0] as $markup) {
		$output = render_id_markup($markup, $payload);
		if ($context === 'URL') {
			expect(substr_count($output, "'"))->toBe(2);
			expect($output)->not->toContain('<');
			expect(preg_match("/'([^']*)'/", $output, $url))->toBe(1);
			$queryText = strpos($url[1], '?') === false ? ltrim($url[1], '&') : parse_url($url[1], PHP_URL_QUERY);
			parse_str($queryText, $query);
			expect($query['id'])->toBe($payload);
			expect($query['action'])->toBe('edit');
			expect($query['tab'])->toBe(strpos($markup, 'tab=logs') === false ? 'notifications' : 'logs');
			if (strpos($markup, '&clear=1') !== false) {
				expect($query['clear'])->toBe('1');
				expect($query['header'])->toBe('false');
			}
		} else {
			$document = new \DOMDocument();
			$document->loadHTML('<!doctype html><html><head><meta charset="UTF-8"></head><body>'
				. $output . '</body></html>');
			$inputs = $document->getElementsByTagName('input');
			expect($inputs->length)->toBe(1);
			expect($inputs->item(0)->attributes->length)->toBe(3);
			expect($inputs->item(0)->getAttribute('value'))->toBe($payload);
			expect($document->getElementsByTagName('script')->length)->toBe(0);
			expect($document->getElementsByTagName('img')->length)->toBe(0);
		}
		expect($output)->not->toContain(chr(96));
	}
})->with(array('URL', 'hidden'))->with(array(
	'ordinary ID' => '42',
	'leading zeros' => '0042',
	'empty new record' => '',
	'Unicode' => 'réseau 日本語',
	'quote boundary' => '\' autofocus onfocus="alert(1)',
	'script boundary' => '</script><script>alert(1)</script>',
	'URL delimiters' => '7&tab=other#fragment%20+ space',
	'entity-like text' => '&#39;&quot;&amp;',
	'backtick' => chr(96) . ' value',
));
