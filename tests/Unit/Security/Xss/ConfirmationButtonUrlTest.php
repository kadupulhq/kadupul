<?php

/*
 * Copyright (C) 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace ConfirmationButtonUrlTest;

function __esc($text) {
	return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$source = file_get_contents(dirname(__DIR__, 4) . '/lib/html_form.php');
if (!preg_match('/function form_confirm_buttons\(.*?<\?php \}/s', $source, $match)) {
	throw new \RuntimeException('Confirmation-button helper not found');
}
eval('namespace ConfirmationButtonUrlTest; ' . $match[0]);

test('confirmation buttons retain literal URLs and action classes', function ($prefix, $payload) {
	$hadConfig = array_key_exists('config', $GLOBALS);
	$originalConfig = $GLOBALS['config'] ?? null;
	$GLOBALS['config'] = array('url_path' => $prefix);
	$action = 'automation_graph_rules.php?action=remove&id=' . $payload;
	$cancel = 'automation_graph_rules.php?filter=' . $payload;
	ob_start();
	try {
		form_confirm_buttons($action, $cancel);
		$output = ob_get_contents();
	} finally {
		ob_end_clean();
		if ($hadConfig) {
			$GLOBALS['config'] = $originalConfig;
		} else {
			unset($GLOBALS['config']);
		}
	}
	$document = new \DOMDocument();
	$document->loadHTML('<!doctype html><html><head><meta charset="UTF-8"></head><body><table>'
		. $output . '</table></body></html>');
	$inputs = $document->getElementsByTagName('input');
	expect($inputs->length)->toBe(2);
	foreach ($inputs as $index => $input) {
		expect($input->attributes->length)->toBe(4);
		expect($input->getAttribute('type'))->toBe('button');
		expect($input->getAttribute('value'))->toBe($index === 0 ? 'Cancel' : 'Delete');
		expect($input->getAttribute('class'))->toBe('ui-button ui-corner-all ui-widget '
			. ($index === 0 ? 'cactiReturnTo' : 'cactiPostAction'));
		expect($input->getAttribute('data-url'))->toBe($prefix . ($index === 0 ? $cancel : $action . '&confirm=true'));
	}
	expect($document->getElementsByTagName('script')->length)->toBe(0);
	expect($document->getElementsByTagName('img')->length)->toBe(0);
	expect($output)->not->toContain(chr(96));
})->with(array('/', '/kadupul/', '/réseau/&literal;/'))->with(array(
	'ordinary ID' => '42',
	'leading zeros' => '0042',
	'empty ID' => '',
	'Unicode' => 'réseau 日本語',
	'quote boundary' => '\' autofocus onfocus="alert(1)',
	'element boundary' => '\'><img src=x onerror=alert(1)><script>alert(1)</script>',
	'URL syntax' => '7&tab=other#fragment%20+ space',
	'entity-like text' => '&#39;&quot;&amp;',
	'backtick' => chr(96) . ' value',
));
