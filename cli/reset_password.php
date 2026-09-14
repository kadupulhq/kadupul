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
 * Turn terminal echo back on after reset_password_read() turned it off.
 *
 * @return void
 */
function reset_password_echo_on() {
	shell_exec('stty echo');
}

/**
 * Read the new password from STDIN. At a terminal it is typed twice without
 * echo; otherwise the first line is used, so it can come from a file or a
 * here-string rather than the command line where other users could see it.
 *
 * @return string|false
 */
function reset_password_read() {
	if (!stream_isatty(STDIN)) {
		$line = fgets(STDIN);

		return ($line === false) ? '' : rtrim($line, "\r\n");
	}

	print 'New password: ';
	shell_exec('stty -echo');

	/* Ctrl-C or a fatal error must not leave the terminal without echo */
	register_shutdown_function(function () {
		reset_password_echo_on();
	});

	if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
		pcntl_async_signals(true);
		pcntl_signal(SIGINT, function () {
			reset_password_echo_on();
			print PHP_EOL;

			exit(130);
		});
	}

	$password = rtrim((string) fgets(STDIN), "\r\n");
	print PHP_EOL . 'Confirm password: ';
	$confirm = rtrim((string) fgets(STDIN), "\r\n");
	reset_password_echo_on();
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
	$user = db_fetch_row_prepared('SELECT id, username, password, password_history, enabled, locked
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

	$history = intval(read_config_option('secpass_history'));

	/* as auth_changepassword.php checks; secpass_check_history() only reads enabled accounts */
	if ($history > 0 && $user['enabled'] == 'on' && !secpass_check_history($user['id'], $password)) {
		print 'ERROR: ' . __('You cannot use a previously entered password!') . PHP_EOL;

		return 1;
	}

	/* auth_changepassword.php refuses the current password even with history off */
	if (compat_password_verify($password, $user['password'])) {
		print 'ERROR: ' . __('Your new password cannot be the same as the old password. Please try again.') . PHP_EOL;

		return 1;
	}

	/* db_check_password_length() dies on failure, and die() alone exits 0 */
	$GLOBALS['reset_password_pending'] = true;

	register_shutdown_function(function () {
		if (!empty($GLOBALS['reset_password_pending'])) {
			print PHP_EOL . 'ERROR: The password was not changed' . PHP_EOL;

			exit(1);
		}
	});

	db_check_password_length();

	$GLOBALS['reset_password_pending'] = false;

	/* keep the replaced hash so the forced change can not set it again, as auth_changepassword.php does */
	$password_history = $user['password_history'];

	if ($history > 0) {
		$passes = explode('|', (string) $user['password_history']);

		while (cacti_count($passes) > $history - 1) {
			array_shift($passes);
		}

		$passes[]         = $user['password'];
		$password_history = implode('|', $passes);
	}

	/* without a transaction a failed later write would leave tokens and sessions revoked but the old password in place */
	if (!db_begin_transaction()) {
		print "ERROR: Could not start a database transaction for local user '" . $user['username'] . "'; the password was not changed" . PHP_EOL;

		return 1;
	}

	$steps = array(
		'revoke remember-me tokens' => array('DELETE FROM user_auth_cache WHERE user_id = ?', array($user['id'])),
		'end sessions'              => array('DELETE FROM sessions WHERE user_id = ?', array($user['id'])),
		'save the password'         => array("UPDATE user_auth
			SET password = ?,
			password_history = ?,
			must_change_password = 'on',
			password_change = 'on'
			WHERE id = ?",
			array(compat_password_hash($password, PASSWORD_DEFAULT), $password_history, $user['id'])),
	);

	foreach ($steps as $step => $query) {
		if (!db_execute_prepared($query[0], $query[1])) {
			db_rollback_transaction();

			print "ERROR: Could not $step for local user '" . $user['username'] . "'; the password was not changed" . PHP_EOL;

			return 1;
		}
	}

	if (!db_commit_transaction()) {
		db_rollback_transaction();

		print "ERROR: Could not commit the change for local user '" . $user['username'] . "'; the password was not changed" . PHP_EOL;

		return 1;
	}

	cacti_log("CLI: Password reset for local user '" . $user['username'] . "', a new password is required at the next login", false, 'AUTH');

	print "Password reset for local user '" . $user['username'] . "'. A new password is required at the next login." . PHP_EOL;

	if ($user['enabled'] != 'on') {
		print 'NOTE: This account is disabled. Enable it in User Management before it can log in.' . PHP_EOL;
	}

	if ($user['locked'] == 'on') {
		print 'NOTE: This account is locked. Unlock it in User Management before it can log in.' . PHP_EOL;
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
	print 'without echo.  Otherwise the first line is used, for example from a file' . PHP_EOL;
	print 'only root can read, or from a variable read without echo:' . PHP_EOL . PHP_EOL;
	print '    php reset_password.php --username=admin < /root/new_password' . PHP_EOL . PHP_EOL;
	print '    read -rs NEW_PASSWORD' . PHP_EOL;
	print '    php reset_password.php --username=admin <<< "$NEW_PASSWORD"' . PHP_EOL;
	print '    unset NEW_PASSWORD' . PHP_EOL . PHP_EOL;
	print 'Exit status is 0 on success and 1 on any error.' . PHP_EOL . PHP_EOL;
}
