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
*/

/*
 * The Permission Denied page closes with a nonce-carrying script block. Its
 * opening tag had no space before the nonce and no closing '>', so the script
 * text became attribute text and the markup after it was swallowed into the
 * script element. The shipped include/auth.php runs through the auth entry
 * probe for a signed-in user who lacks the page's realm.
 */

require_once dirname(__DIR__, 3) . '/Helpers/AuthEntryProbe.php';

function permission_denied_page(array $server = array()) : array {
	return cacti_test_run_auth_entry_probe(array(
		'config'          => array('auth_method' => 1),
		'users'           => array(array('id' => '42', 'username' => 'alice', 'realm' => 0, 'enabled' => 'on', 'locked' => '')),
		'realms'          => array(),
		'realm_filenames' => array('probe.php' => 15),
		'session'         => array('sess_user_id' => '42'),
		'server'          => $server,
	));
}

test('the denied page script block is a well formed element carrying the nonce', function () {
	$result = permission_denied_page();

	expect($result['events'])->toContain('error_page')
		->and($result['page_continued'])->toBeFalse()
		->and(preg_match('#<script type=\'text/javascript\' nonce="probe">(.*?)</script>#s', $result['output'], $match))->toBe(1)
		->and($match[1])->toContain('$(function() {')
		->and($match[1])->not->toContain('<');
});

test('the markup after the script block stays outside it', function () {
	$result = permission_denied_page();
	$script = strpos($result['output'], '</script>');

	expect($script)->not->toBeFalse()
		->and(strpos($result['output'], '</body>', $script))->not->toBeFalse()
		->and(substr_count($result['output'], '<script'))->toBe(1);
});

test('a signed-in user with the realm is not shown the denied page', function () {
	$result = cacti_test_run_auth_entry_probe(array(
		'config'          => array('auth_method' => 1),
		'users'           => array(array('id' => '42', 'username' => 'alice', 'realm' => 0, 'enabled' => 'on', 'locked' => '')),
		'realms'          => array(array(42, 15)),
		'realm_filenames' => array('probe.php' => 15),
		'session'         => array('sess_user_id' => '42'),
	));

	expect($result['events'])->not->toContain('error_page')
		->and($result['output'])->toBe('')
		->and($result['page_continued'])->toBeTrue();
});
