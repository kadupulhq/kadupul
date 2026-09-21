<?php

/*
 * Copyright (C) 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace UserManagerConfirmationTest;

function get_nfilter_request_var($name) {
	return $GLOBALS['confirmation_request'][$name];
}

test('user and manager confirmations preserve values without creating markup', function ($variant, ?string $payload) {
	$file = strpos($variant, 'user') === 0 ? 'user_admin.php' : 'managers.php';
	$source = file_get_contents(dirname(__DIR__, 4) . '/' . $file);
	$count = preg_match_all('/\$selected_items_html = .*?<\/tr>[^;]*;/s', $source, $blocks);
	expect($count)->toBe($file === 'managers.php' ? 2 : 1);
	$block = $blocks[0][$variant === 'notifications' ? 1 : 0];
	$action = $variant === 'user copy' ? '2' : ($payload ?? '1');
	$user_id = $payload ?? '0042';
	if ($payload !== null) {
		$user_array = array($payload, '0042', 3);
		$selected_items = $variant === 'notifications'
			? array($payload => array($payload => 1)) : $user_array;
	}
	$expected = $variant === 'user copy' ? $user_id
		: ($payload === null ? '' : serialize($file === 'managers.php' ? $selected_items : $user_array));
	$save_html = '<button type="submit">Continue</button>';
	$GLOBALS['confirmation_request'] = array('drp_action' => $action, 'id' => $payload ?? '0042');
	ob_start();
	try {
		eval('namespace UserManagerConfirmationTest; ' . $block);
		$output = ob_get_contents();
	} finally {
		ob_end_clean();
		unset($GLOBALS['confirmation_request']);
	}
	$document = new \DOMDocument();
	$document->loadHTML('<!doctype html><html><head><meta charset="UTF-8"></head><body><table>'
		. $output . '</table></body></html>');
	$inputs = $document->getElementsByTagName('input');
	expect($inputs->length)->toBe($variant === 'notifications' ? 5 : ($variant === 'receivers' ? 4 : 3));
	$fields = array();
	foreach ($inputs as $input) {
		expect($input->attributes->length)->toBe(3);
		expect($input->getAttribute('type'))->toBe('hidden');
		$fields[$input->getAttribute('name')] = $input->getAttribute('value');
	}
	expect($fields['action'])->toBe('actions');
	expect($fields['drp_action'])->toBe($action);
	expect($fields['selected_items'])->toBe($expected);
	if ($variant !== 'user copy' && $payload !== null) {
		expect(unserialize($fields['selected_items'], array('allowed_classes' => false)))
			->toBe($file === 'managers.php' ? $selected_items : $user_array);
	}
	if ($variant === 'notifications') {
		expect($fields['action_receiver_notifications'])->toBe('1');
		expect($fields['id'])->toBe($payload ?? '0042');
	} elseif ($variant === 'receivers') {
		expect($fields['action_receivers'])->toBe('1');
	}
	expect($document->getElementsByTagName('script')->length)->toBe(0);
	expect($document->getElementsByTagName('img')->length)->toBe(0);
	expect($document->getElementsByTagName('button')->length)->toBe(1);
	expect($output)->not->toContain(chr(96));
	if ($payload === '&#39;&quot;&amp;') {
		expect($output)->toContain('&amp;#39;&amp;quot;&amp;amp;');
	}
})->with(array('user bulk', 'user copy', 'receivers', 'notifications'))->with(array(
	'ordinary ID' => '42',
	'leading zeros' => '0042',
	'empty value' => '',
	'missing selection' => array(null),
	'Unicode' => 'réseau 日本語',
	'attribute boundary' => '\' autofocus onfocus="alert(1)',
	'element boundary' => '\'><img src=x onerror=alert(1)><script>alert(1)</script>',
	'entity-like text' => '&#39;&quot;&amp;',
	'backtick' => chr(96) . ' value',
));
