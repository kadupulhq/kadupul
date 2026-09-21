<?php

/*
 * Copyright (C) 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace FormStartAttributeTest;

$source = file_get_contents(dirname(__DIR__, 4) . '/lib/html_form.php');
if (!preg_match('/function form_start\(.*?^\}/ms', $source, $match)) {
	throw new \RuntimeException('Form-start helper not found');
}
eval('namespace FormStartAttributeTest; ' . $match[0]);

function render_form($action, $id, $multipart) {
	$saved = array();
	foreach (array('form_id', 'form_action') as $name) {
		$saved[$name] = array(array_key_exists($name, $GLOBALS), $GLOBALS[$name] ?? null);
	}
	ob_start();
	try {
		form_start($action, $id, $multipart);
		$output = ob_get_contents();
		return array($output, $GLOBALS['form_id'], $GLOBALS['form_action']);
	} finally {
		ob_end_clean();
		foreach ($saved as $name => $previous) {
			if ($previous[0]) {
				$GLOBALS[$name] = $previous[1];
			} else {
				unset($GLOBALS[$name]);
			}
		}
	}
}

test('form-start attributes round-trip without changing raw globals', function ($multipart, $payload) {
	$action = 'host.php?action=edit&filter=' . $payload;
	$id = '  form_' . $payload . '  ';
	list($output, $rawId, $rawAction) = render_form($action, $id, $multipart);
	expect($rawId)->toBe(trim($id));
	expect($rawAction)->toBe($action);
	$document = new \DOMDocument();
	$document->loadHTML('<!doctype html><html><head><meta charset="UTF-8"></head><body>'
		. $output . '</form></body></html>');
	$forms = $document->getElementsByTagName('form');
	expect($forms->length)->toBe(1);
	$form = $forms->item(0);
	expect($form->attributes->length)->toBe($multipart ? 7 : 6);
	expect($form->getAttribute('id'))->toBe(trim($id));
	expect($form->getAttribute('name'))->toBe(trim($id));
	expect($form->getAttribute('action'))->toBe($action);
	expect($form->getAttribute('class'))->toBe('cactiFormStart');
	expect($form->getAttribute('method'))->toBe('post');
	expect($form->getAttribute('autocomplete'))->toBe('off');
	expect($form->getAttribute('enctype'))->toBe($multipart ? 'multipart/form-data' : '');
	expect($document->getElementsByTagName('script')->length)->toBe(0);
	expect($document->getElementsByTagName('img')->length)->toBe(0);
	expect($output)->not->toContain(chr(96));
})->with(array(false, true))->with(array(
	'ordinary text' => '42',
	'leading zeros' => '0042',
	'empty text' => '',
	'Unicode' => 'réseau 日本語',
	'quote boundary' => '\' autofocus onfocus="alert(1)',
	'element boundary' => '\'><img src=x onerror=alert(1)><script>alert(1)</script>',
	'URL syntax' => '7&tab=other#fragment%20+ space',
	'entity-like text' => '&#39;&quot;&amp;',
	'backtick' => chr(96) . ' value',
));

test('automatic IDs advance only for unnamed forms', function () {
	$first = render_form('host.php', '', false);
	$named = render_form('host.php', ' explicit ', false);
	$second = render_form('host.php', '', true);
	expect(preg_match('/^form([0-9]+)$/', $first[1], $match))->toBe(1);
	expect($named[1])->toBe('explicit');
	expect($second[1])->toBe('form' . ((int) $match[1] + 1));
	expect($first[0])->toContain("id='" . $first[1] . "' name='" . $first[1] . "'");
	expect($second[0])->toContain("id='" . $second[1] . "' name='" . $second[1] . "'");
});
