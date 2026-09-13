#!/usr/bin/env php
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

require(__DIR__ . '/../include/cli_check.php');

/* switch to main database for cli's */
if ($config['poller_id'] > 1) {
	db_switch_remote_to_main();
}

/* process calling arguments */
$parms = $_SERVER['argv'];
array_shift($parms);

if (cacti_sizeof($parms) == 0) {
	display_help();

	exit(1);
}

$username = '';

foreach ($parms as $parameter) {
	if (strpos($parameter, '=')) {
		list($arg, $value) = explode('=', $parameter, 2);
	} else {
		$arg   = $parameter;
		$value = '';
	}

	switch ($arg) {
		case '--username':
		case '-u':
			$username = $value;

			break;
		case '--version':
		case '-V':
		case '-v':
			display_version();

			exit(0);
		case '--help':
		case '-H':
		case '-h':
			display_help();

			exit(0);
		default:
			print "ERROR: Invalid Argument: ($arg)" . PHP_EOL . PHP_EOL;
			display_help();

			exit(1);
	}
}

if ($username == '') {
	print 'ERROR: You must supply --username=<local user>' . PHP_EOL . PHP_EOL;
	display_help();

	exit(1);
}

$password = reset_password_read();

if ($password === false) {
	exit(1);
}

exit(reset_password_apply($username, $password));

/**
 * Read the new password from STDIN. At a terminal it is typed twice without
 * echo; otherwise the first line is used, so it can be piped in rather than
 * passed on the command line where other users could see it.
 *
 * @return string|false
 */
function reset_password_read() {
	$terminal = function_exists('posix_isatty') && posix_isatty(STDIN);

	if (!$terminal) {
		$line = fgets(STDIN);

		return ($line === false) ? '' : rtrim($line, "\r\n");
	}

	print 'New password: ';
	shell_exec('stty -echo');
	$password = rtrim((string) fgets(STDIN), "\r\n");
	print PHP_EOL . 'Confirm password: ';
	$confirm = rtrim((string) fgets(STDIN), "\r\n");
	shell_exec('stty echo');
	print PHP_EOL;

	if ($password !== $confirm) {
		print 'ERROR: The passwords do not match' . PHP_EOL;

		return false;
	}

	return $password;
}

/**
 * Set a local account's password, require a new one at the next login, and
 * end its remember-me tokens and sessions.
 *
 * @param string $username Local (realm 0) account name.
 * @param string $password New password; never printed or logged.
 *
 * @return int Exit status: 0 on success, 1 on failure.
 */
function reset_password_apply($username, $password) {
	$user = db_fetch_row_prepared('SELECT id, username, enabled
		FROM user_auth
		WHERE username = ?
		AND realm = 0',
		array($username));

	if (!cacti_sizeof($user)) {
		print "ERROR: No local user named '$username'. Only local accounts have a Cacti password." . PHP_EOL;

		return 1;
	}

	if ($password == '') {
		print 'ERROR: The password can not be empty' . PHP_EOL;

		return 1;
	}

	$check = secpass_check_pass($password);

	if ($check != 'ok') {
		print 'ERROR: ' . $check . PHP_EOL;

		return 1;
	}

	db_check_password_length();

	db_execute_prepared("UPDATE user_auth
		SET password = ?,
		must_change_password = 'on',
		password_change = 'on'
		WHERE id = ?",
		array(compat_password_hash($password, PASSWORD_DEFAULT), $user['id']));

	db_execute_prepared('DELETE FROM user_auth_cache WHERE user_id = ?', array($user['id']));
	db_execute_prepared('DELETE FROM sessions WHERE user_id = ?', array($user['id']));

	cacti_log("CLI: Password reset for local user '" . $user['username'] . "', a new password is required at the next login", false, 'AUTH');

	print "Password reset for local user '" . $user['username'] . "'. A new password is required at the next login." . PHP_EOL;

	if ($user['enabled'] != 'on') {
		print 'NOTE: This account is disabled. Enable it in User Management before it can log in.' . PHP_EOL;
	}

	return 0;
}

/* display_version - displays version information */
function display_version() {
	$version = get_cacti_cli_version();
	print "Cacti Reset Password Utility, Version $version, " . COPYRIGHT_YEARS . PHP_EOL;
}

/* display_help - displays the usage of the function */
function display_help() {
	display_version();

	print PHP_EOL . 'usage: reset_password.php --username=<local user>' . PHP_EOL . PHP_EOL;
	print 'Sets a new password for a local Cacti account and requires the user to change' . PHP_EOL;
	print 'it at the next login.  Remember-me tokens and sessions for the account end.' . PHP_EOL . PHP_EOL;
	print 'The password is read from standard input.  At a terminal it is typed twice' . PHP_EOL;
	print 'without echo; otherwise the first line is used, for example:' . PHP_EOL . PHP_EOL;
	print '    printf \'%s\n\' "$NEW_PASSWORD" | php reset_password.php --username=admin' . PHP_EOL . PHP_EOL;
	print 'Exit status is 0 on success and 1 on any error.' . PHP_EOL . PHP_EOL;
}
