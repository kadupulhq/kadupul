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

if (!preg_match('/function form_end\(.*?^\}/ms', $source, $match)) {
	throw new \RuntimeException('Form-end helper not found');
}
eval('namespace FormStartAttributeTest; ' . $match[0]);

class CactiSecureHeaders {
	public static function getNonceAttribute() {
		return 'nonce="test-nonce"';
	}
}

function __($text) {
	return $text;
}

function __esc($text) {
	return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function render_form($action, $id, $multipart, $ajax = null) {
	$saved = array();
	foreach (array('form_id', 'form_action') as $name) {
		$saved[$name] = array(array_key_exists($name, $GLOBALS), $GLOBALS[$name] ?? null);
	}
	ob_start();
	try {
		form_start($action, $id, $multipart);
		if ($ajax !== null) {
			form_end($ajax);
		}
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

test('paired form helpers encode JavaScript values and preserve non-AJAX output', function ($payload) {
	$action = 'host.php?filter=' . $payload;
	$id = 'form_' . $payload;
	list($output, $rawId, $rawAction) = render_form($action, $id, true, true);
	expect($rawId)->toBe(trim($id));
	expect($rawAction)->toBe($action);
	$document = new \DOMDocument();
	// libxml's HTML4 parser reports closing tags inside the existing JavaScript HTML strings.
	$previousErrors = libxml_use_internal_errors(true);
	try {
		$document->loadHTML('<!doctype html><html><head><meta charset="UTF-8"></head><body>'
			. $output . '</body></html>');
	} finally {
		libxml_clear_errors();
		libxml_use_internal_errors($previousErrors);
	}
	expect($document->getElementsByTagName('form')->length)->toBe(1);
	$scripts = $document->getElementsByTagName('script');
	expect($scripts->length)->toBe(1);
	expect($scripts->item(0)->getAttribute('nonce'))->toBe('test-nonce');
	$script = $scripts->item(0)->textContent;
	expect(preg_match('/var formId = ([^\r\n]+);/', $script, $idMatch))->toBe(1);
	$renderedId = $document->getElementsByTagName('form')->item(0)->getAttribute('id');
	expect(json_decode(trim($idMatch[1]), true))->toBe($renderedId);
	expect(preg_match('/strURL = ([^\r\n]+);/', $script, $actionMatch))->toBe(1);
	$renderedAction = $document->getElementsByTagName('form')->item(0)->getAttribute('action');
	expect(json_decode(trim($actionMatch[1]), true))->toBe($renderedAction);
	if (strpos($payload, chr(255)) !== false) {
		expect($renderedId)->toBe('form_invalid' . "\u{FFFD}");
		expect($renderedAction)->toBe('host.php?filter=invalid' . "\u{FFFD}");
	} else {
		expect($renderedId)->toBe(trim($id));
		expect($renderedAction)->toBe($action);
	}
	expect($script)->toContain('$(document.getElementById(formId))');
	expect($script)->toContain("form.on('submit'");
	expect($script)->toContain("$.post(strURL, json)");
	expect($script)->toContain("'header=false'");
	expect($document->getElementsByTagName('img')->length)->toBe(0);
	$plain = render_form($action, $id, false, false);
	expect($plain[0])->not->toContain('<script');
	expect($plain[0])->toEndWith('</form>' . PHP_EOL);
})->with(array(
	'ordinary' => '42',
	'Unicode' => 'réseau 日本語',
	'quote' => "';alert(1);//",
	'script boundary' => '</script><script>alert(1)</script>',
	'entities' => '&#39;&quot;&amp;',
	'URL syntax' => '7&tab=other#fragment%20+ space',
	'CSS punctuation' => 'a:b.c[d]',
	'invalid UTF-8' => 'invalid' . chr(255),
	'backtick' => chr(96) . ' value',
));
