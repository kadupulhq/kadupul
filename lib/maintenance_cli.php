<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 | Copyright (C) 2026 The Kadupul project and contributors                 |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 |                                                                         |
 | This program is distributed in the hope that it will be useful,         |
 | but WITHOUT ANY WARRANTY; without even the implied warranty of          |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the           |
 | GNU General Public License for more details.                            |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
 | Cacti is designed, written and maintained by the Cacti Group.           |
 |                                                                         |
 | Please read the included docs/CONTRIBUTING.md file for more information.|
 +-------------------------------------------------------------------------+
 */

/**
 * Validate one raw remove_graphs.php option before getopt() can discard it.
 *
 * @param string $parameter The raw command-line argument.
 * @param string $shortopts The getopt() short-option declaration.
 * @param array  $longopts  The getopt() long-option declarations.
 *
 * @return bool True only when the argument matches a declared option and its
 *              required value shape.
 */
function cacti_remove_graphs_parameter_is_valid($parameter, $shortopts, $longopts) {
	if (strpos($parameter, '-') === 0 && strpos($parameter, '--') !== 0) {
		$letters = substr($parameter, 1);
		$allowed = str_replace(':', '', $shortopts);

		return $letters !== '' && strspn($letters, $allowed) === strlen($letters);
	}

	if (strpos($parameter, '--') !== 0) {
		return false;
	}

	$valid_longopts = array();

	foreach($longopts as $option) {
		$valid_longopts[rtrim($option, ':')] = substr($option, -1) === ':';
	}

	$parts      = explode('=', substr($parameter, 2), 2);
	$name       = $parts[0];
	$has_equals = count($parts) === 2;
	$has_value  = $has_equals && $parts[1] !== '';

	if (!array_key_exists($name, $valid_longopts)) {
		return false;
	}

	return ($valid_longopts[$name] && $has_value) || (!$valid_longopts[$name] && !$has_equals);
}

/**
 * Create a file for writing without following a symlink or reusing a name.
 *
 * The maintenance scripts keep their 1.2.31 names in the shared temporary
 * directory, where another local user can claim a name first. Mode 'x+b' opens
 * with O_CREAT|O_EXCL, which fails when anything, a symlink included, already
 * holds the name, so a claimed name stops the caller instead of redirecting
 * its write.
 *
 * @param string $path The file to create.
 *
 * @return resource|string An open handle, or a printable reason it was refused.
 */
function cacti_cli_create_file($path) {
	if (is_link($path)) {
		return sprintf("Refusing to write '%s' because it is a symbolic link", $path);
	}

	if (file_exists($path)) {
		return sprintf("Refusing to overwrite existing file '%s'", $path);
	}

	/* A new file gets 0666 less the process umask, which commonly leaves a
	 * dump or backup of an RRD readable by other local users. The umask is
	 * narrowed for the create itself so the file is 0600 from the moment it
	 * exists, and restored before anything else runs. */
	$umask  = umask(0077);
	$handle = @fopen($path, 'x+b');
	umask($umask);

	if ($handle === false) {
		return sprintf("Unable to create '%s'", $path);
	}

	/* The umask is the protection. PHP has no fchmod(), and a chmod() by name
	 * could reach a file swapped in after the create, so a mode that is still
	 * wide is refused rather than repaired. Windows reports 0666 for every file
	 * and relies on the per-user TEMP directory instead. */
	$stat = fstat($handle);

	if ($stat === false || (DIRECTORY_SEPARATOR == '/' && ($stat['mode'] & 0777) !== 0600)) {
		cacti_cli_remove_file($handle, $path);

		return sprintf("Refusing to use '%s' because it is not private to its owner", $path);
	}

	return $handle;
}

/**
 * Close a file made by cacti_cli_create_file() and remove it by name.
 *
 * PHP cannot unlink a descriptor, so the name is removed only while it still
 * refers to the open file. A name that now points elsewhere, a symlink
 * included, is left alone.
 *
 * @param resource $handle The handle returned by cacti_cli_create_file().
 * @param string   $path   The name the file was created under.
 *
 * @return bool True when the file was removed.
 */
function cacti_cli_remove_file($handle, $path) {
	$same = cacti_cli_path_is_handle($handle, $path);

	if (is_resource($handle)) {
		fclose($handle);
	}

	return $same && @unlink($path);
}

/**
 * Whether a name still refers to an open file made by cacti_cli_create_file().
 *
 * rrdtool restore can only take a file name, so the name is checked against
 * the open descriptor immediately before another program is given it.
 *
 * @param resource $handle The open file.
 * @param string   $path   The name it was created under.
 *
 * @return bool True when the name is a regular file with the same device and inode.
 */
function cacti_cli_path_is_handle($handle, $path) {
	$opened = is_resource($handle) ? fstat($handle) : false;
	$named  = @lstat($path);

	if ($opened === false || $named === false || ($named['mode'] & 0170000) !== 0100000) {
		return false;
	}

	return $opened['dev'] === $named['dev'] && $opened['ino'] === $named['ino'];
}

/**
 * Run a command and write its standard output through an open handle.
 *
 * A shell redirect reopens its target by name and follows a symlink swapped in
 * after the file was created. Copying the pipe into the descriptor keeps the
 * write on the file that was created. Standard error is inherited, as it was
 * with the redirect.
 *
 * @param string   $command The command line, already quoted by the caller.
 * @param resource $handle  Where standard output is written.
 *
 * @return bool True when the command exited 0 and all of its output was copied.
 */
function cacti_cli_run_to_handle($command, $handle) {
	$pipes   = array();
	$process = proc_open($command, array(1 => array('pipe', 'w')), $pipes);

	if (!is_resource($process)) {
		return false;
	}

	$copied = stream_copy_to_stream($pipes[1], $handle);

	fclose($pipes[1]);

	$status  = proc_close($process);
	$flushed = fflush($handle);

	return $copied !== false && $flushed && $status === 0;
}

/**
 * Read an open file from the start, one line per element as file() returns.
 *
 * @param resource $handle The open file.
 *
 * @return array The lines, each with its line ending.
 */
function cacti_cli_read_lines($handle) {
	$lines = array();

	rewind($handle);

	while (($line = fgets($handle)) !== false) {
		$lines[] = $line;
	}

	return $lines;
}

/**
 * The user id files this process creates belong to, or false if unknown.
 *
 * Without the POSIX extension this falls back, as get_running_user() does, to
 * the owner of a file the process creates; tmpfile() leaves no name behind to
 * swap. Windows reports owner 0 for every file, so ownership cannot be
 * verified there and the caller must refuse.
 *
 * @return int|false The user id, or false when it cannot be determined.
 */
function cacti_cli_current_uid() {
	if (function_exists('posix_geteuid')) {
		return posix_geteuid();
	}

	if (DIRECTORY_SEPARATOR != '/') {
		return false;
	}

	$probe = tmpfile();

	if ($probe === false) {
		return false;
	}

	$stat = fstat($probe);

	fclose($probe);

	return $stat === false ? false : $stat['uid'];
}

/**
 * Open a debug log for appending without following a symlink.
 *
 * A missing log is created exclusively. An existing log is appended only when
 * it is a regular file owned by this user and is still that file once opened,
 * so a name swapped for a symlink between the check and the open is closed
 * again before anything is written.
 *
 * @param string $path The log file.
 *
 * @return resource|string An open handle, or a printable reason it was refused.
 */
function cacti_cli_open_log($path) {
	if (!is_link($path) && !file_exists($path)) {
		$created = cacti_cli_create_file($path);

		/* The exclusive create only makes the log owner-only. It is reopened
		 * below in append mode, because another child may append to it first
		 * and a handle left at offset 0 would overwrite those lines. Losing the
		 * create race to that child is handled the same way. */
		if (is_resource($created)) {
			fclose($created);
		} elseif (is_link($path) || !file_exists($path)) {
			return $created;
		}
	}

	$before = @lstat($path);

	if ($before === false || ($before['mode'] & 0170000) !== 0100000) {
		return sprintf("Refusing to append to '%s' because it is not a regular file", $path);
	}

	$uid = cacti_cli_current_uid();

	if ($uid === false) {
		return sprintf("Refusing to append to '%s' because its owner cannot be verified", $path);
	}

	if ($before['uid'] !== $uid) {
		return sprintf("Refusing to append to '%s' because another user owns it", $path);
	}

	$handle = @fopen($path, 'ab');

	if ($handle === false) {
		return sprintf("Unable to open '%s'", $path);
	}

	$after = fstat($handle);

	if ($after === false || $after['dev'] !== $before['dev'] || $after['ino'] !== $before['ino']) {
		fclose($handle);

		return sprintf("Refusing to append to '%s' because it changed while it was opened", $path);
	}

	return $handle;
}

/**
 * Decide what remove_graphs.php does with an argument that fails validation.
 *
 * 1.2.31 let getopt() drop unknown options silently. An unknown long option is
 * reported and skipped, except when its name looks like a mistyped filter
 * option, because dropping a filter widens what the command removes. A name
 * looks mistyped when it is within two edits of a filter option, or when one
 * name is a prefix of the other and the shorter has at least four characters.
 * A declared option with the wrong value shape, a bare word (getopt() stops
 * parsing there), "--", and a short cluster naming a declared letter abort.
 *
 * @param string $parameter The raw command-line argument.
 * @param string $shortopts The getopt() short-option declaration.
 * @param array  $longopts  The getopt() long-option declarations.
 *
 * @return string 'ignore' for the retired --graph-type option, 'warn' for an
 *                unknown option that cannot be a filter, otherwise 'abort'.
 */
function cacti_remove_graphs_unknown_parameter_action($parameter, $shortopts, $longopts) {
	$filters = array('host-id', 'graph-template-id', 'host-template-id', 'graph-regex');

	if (strpos($parameter, '--') !== 0) {
		if (strpos($parameter, '-') === 0 && strlen($parameter) > 1 && strpbrk(substr($parameter, 1), str_replace(':', '', $shortopts)) === false) {
			return 'warn';
		}

		return 'abort';
	}

	$name = explode('=', substr($parameter, 2), 2)[0];

	if ($name === '') {
		return 'abort';
	}

	if ($name === 'graph-type') {
		return 'ignore';
	}

	foreach($longopts as $option) {
		if (rtrim($option, ':') === $name) {
			return 'abort';
		}
	}

	foreach($filters as $filter) {
		$shorter = strlen($name) < strlen($filter) ? $name : $filter;
		$longer  = $shorter === $name ? $filter : $name;

		if (levenshtein($name, $filter) <= 2 || (strlen($shorter) >= 4 && strpos($longer, $shorter) === 0)) {
			return 'abort';
		}
	}

	return 'warn';
}

/**
 * Return the validation error for a remove_graphs.php regular expression.
 *
 * @param string $regex The expression supplied by the operator.
 *
 * @return string|false False when valid, otherwise a printable error message.
 */
function cacti_remove_graphs_regex_error($regex) {
	$validation = validate_is_rlike_regex($regex);

	if ($validation === true) {
		return false;
	}

	return is_string($validation) && $validation !== '' ? $validation : 'Invalid regular expression.';
}

/**
 * Determine whether getopt() observed the remove_graphs.php quiet flag.
 *
 * @param array $options Parsed getopt() options.
 *
 * @return bool True when the quiet option key is present.
 */
function cacti_remove_graphs_quiet_enabled($options) {
	return array_key_exists('quiet', $options);
}

/**
 * Build the prepared host/filter predicate for graph-name reapplication.
 *
 * @param string $host_id A single id, comma-delimited ids, zero, or "all".
 * @param string $filter  Optional graph name/title filter.
 *
 * @return array|false A SQL fragment and parameter list, or false for an
 *                     invalid or missing host selector.
 */
function cacti_reapply_names_where($host_id, $filter) {
	$host_id = trim($host_id);
	$params = array();
	$where  = '';

	if ($filter != '') {
		$where  = 'AND (graph_templates_graph.title_cache LIKE ? OR graph_templates.name LIKE ?)';
		$params = array('%' . $filter . '%', '%' . $filter . '%');
	}

	if (strtolower($host_id) == 'all') {
		return array($where, $params);
	}

	if (substr_count($host_id, ',')) {
		$host_ids = array();

		foreach(explode(',', $host_id) as $host) {
			$host = trim($host);

			if (!ctype_digit($host)) {
				return false;
			}

			$host_ids[] = (int) $host;
		}

		$where  .= ' AND graph_local.host_id IN (' . implode(',', array_fill(0, count($host_ids), '?')) . ')';
		$params  = array_merge($params, $host_ids);

		return array($where, $params);
	}

	if (ctype_digit($host_id)) {
		$where   .= ' AND graph_local.host_id=?';
		$params[] = (int) $host_id;

		return array($where, $params);
	}

	return false;
}
