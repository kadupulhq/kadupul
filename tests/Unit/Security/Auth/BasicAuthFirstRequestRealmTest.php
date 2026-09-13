<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2026 The Kadupul project and contributors				   |
 |																		   |
 | This program is free software; you can redistribute it and/or		   |
 | modify it under the terms of the GNU General Public License			   |
 | as published by the Free Software Foundation; either version 2		   |
 | of the License, or (at your option) any later version.				   |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution					   |
 +-------------------------------------------------------------------------+
*/

/*
 * The first request of a Web Basic session signs the user in. Every later
 * request already runs the guest page and realm checks, so the first request
 * must end exactly as the second one does for the same user and page.
 */

require_once dirname(__DIR__, 3) . '/Helpers/AuthEntryProbe.php';

/* ids are strings because the database layer returns them that way */
function web_basic_request(string $username, string $page, bool $first, array $realms = array()) : array {
	return array(
		'config'		  => array('auth_method' => 2, 'guest_user' => 'guest'),
		'users'			  => array(
			array('id' => '3', 'username' => 'guest', 'realm' => 2, 'enabled' => 'on', 'locked' => ''),
			array('id' => '42', 'username' => 'alice', 'realm' => 2, 'enabled' => 'on', 'locked' => ''),
		),
		'realms'		  => $realms,
		'page'			  => $page,
		'realm_filenames' => array('graph_view.php' => 7, 'user_admin.php' => 1, 'install.php' => 26),
		'guest_account'	  => $page === 'graph_view.php',
		'server'		  => array('REMOTE_USER' => $username),
		'session'		  => $first ? array() : array('sess_user_id' => $username === 'guest' ? '3' : '42'),
	);
}

/** @return array{continued: bool, denied: bool, login: bool, user: mixed, install_grant: bool} */
function web_basic_outcome(array $result) : array {
	$grants = array_filter($result['executed'], function (array $row) : bool {
		return strpos($row['sql'], 'INSERT INTO `user_auth_realm`') === 0;
	});

	return array(
		'continued'		=> $result['page_continued'],
		'denied'		=> in_array('error_page', $result['events'], true),
		'login'			=> in_array('login_page', $result['events'], true),
		'user'			=> $result['session']['sess_user_id'] ?? null,
		'install_grant' => count($grants) > 0,
	);
}

function web_basic_first_and_second(string $username, string $page, array $realms = array()) : array {
	return array(
		web_basic_outcome(cacti_test_run_auth_entry_probe(web_basic_request($username, $page, true, $realms))),
		web_basic_outcome(cacti_test_run_auth_entry_probe(web_basic_request($username, $page, false, $realms))),
	);
}

test('the first Web Basic request is denied a page without its realm', function () {
	$outcome = web_basic_outcome(cacti_test_run_auth_entry_probe(web_basic_request('alice', 'user_admin.php', true)));

	expect($outcome['denied'])->toBeTrue()
		->and($outcome['continued'])->toBeFalse()
		->and($outcome['user'])->toBe('42');
});

test('the first Web Basic request still reaches a page the user holds the realm for', function () {
	list($first, $second) = web_basic_first_and_second('alice', 'user_admin.php', array(array(42, 1)));

	expect($first)->toBe($second)
		->and($first['continued'])->toBeTrue()
		->and($first['denied'])->toBeFalse();
});

test('the first Web Basic request is denied where the second request is denied', function () {
	list($first, $second) = web_basic_first_and_second('alice', 'user_admin.php');

	expect($first)->toBe($second);
});

test('a guest page stays open to a Web Basic user without its realm on every request', function () {
	list($first, $second) = web_basic_first_and_second('alice', 'graph_view.php');

	expect($first)->toBe($second)
		->and($first['continued'])->toBeTrue()
		->and($first['user'])->toBe('42');
});

test('a Web Basic sign-in as the guest account ends as its second request does', function () {
	list($guest_first, $guest_second) = web_basic_first_and_second('guest', 'graph_view.php');
	list($other_first, $other_second) = web_basic_first_and_second('guest', 'user_admin.php', array(array(3, 1)));

	expect($guest_first)->toBe($guest_second)
		->and($guest_first['continued'])->toBeTrue()
		->and($other_first)->toBe($other_second)
		->and($other_first['login'])->toBeTrue()
		->and($other_first['continued'])->toBeFalse();
});

test('install realm handling runs on the first Web Basic request as on the second', function () {
	list($holder_first, $holder_second) = web_basic_first_and_second('alice', 'install.php', array(array(42, 26)));
	list($grant_first, $grant_second)	= web_basic_first_and_second('alice', 'install.php', array(array(42, 15)));

	expect($holder_first)->toBe($holder_second)
		->and($holder_first['continued'])->toBeTrue()
		->and($holder_first['install_grant'])->toBeFalse()
		->and($grant_first)->toBe($grant_second)
		->and($grant_first['install_grant'])->toBeTrue();
});
