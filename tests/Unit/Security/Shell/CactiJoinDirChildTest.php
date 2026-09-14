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
 * cacti_join_dir_child() (lib/functions.php) appends a child name to a
 * directory that already went through cacti_trim_dir_separator(). A plain
 * '$dir . $separator . $name' concatenation corrupts a bare Windows
 * drive-relative directory ('C:'): 'C:' means the current directory on
 * drive C, but 'C:/child' means the root of that drive instead. The join
 * sites in lib/spikekill.php (the requested-backup and temp-XML paths) and
 * poller_spikekill.php's purge_spike_backups() all consume a value that
 * passed through cacti_trim_dir_separator(), so this one pure function is
 * what each of them delegates to instead of concatenating locally.
 *
 * Extracted by source and eval()'d, the same technique
 * CactiTrimDirSeparatorTest.php uses for a plain (non-class) function.
 */

if (!function_exists('cacti_join_dir_child')) {
	$source = file_get_contents(dirname(__DIR__, 4) . '/lib/functions.php');

	$start = strpos($source, 'function cacti_join_dir_child(');
	expect($start)->not->toBeFalse();

	$end = strpos($source, "\n}\n", $start);
	$body = substr($source, $start, $end - $start + 2);

	eval($body); // nosemgrep: php.lang.security.eval-use.eval-use
}

test('a child name is joined without corrupting a bare Windows drive-relative directory or doubling a root separator', function (string $dir, string $name, string $separator, string $expected) {
	expect(cacti_join_dir_child($dir, $name, $separator))->toBe($expected);
})->with([
	'Windows: a bare drive-relative dir gets no separator'    => ['C:', 'dir', '\\', 'C:dir'],
	'Windows: a backslash drive root is not doubled'          => ['C:\\', 'dir', '\\', 'C:\\dir'],
	'Windows: a forward-slash drive root is not doubled'      => ['C:/', 'dir', '\\', 'C:/dir'],
	'Windows: a backslash-style dir gets one separator'       => ['C:\\dir', 'child', '\\', 'C:\\dir\\child'],
	'Windows: a forward-slash-style dir gets one separator'   => ['C:/dir', 'child', '\\', 'C:/dir\\child'],
	'Windows: a bare forward-slash root is not doubled'       => ['/', 'dir', '\\', '/dir'],
	'Windows: a bare backslash root is not doubled'           => ['\\', 'dir', '\\', '\\dir'],
	'Windows: a relative dir gets one separator'               => ['dir', 'child', '\\', 'dir\\child'],
	'Windows: a backslash UNC root gets one separator'         => ['\\\\server\\share', 'child', '\\', '\\\\server\\share\\child'],
	'Windows: a forward-slash UNC root gets one separator'     => ['//server/share', 'child', '\\', '//server/share\\child'],
	'POSIX: a bare root is not doubled'                        => ['/', 'dir', '/', '/dir'],
	'POSIX: a relative dir gets one separator'                 => ['dir', 'child', '/', 'dir/child'],
	'POSIX: a plain dir gets one separator'                    => ['/dir', 'child', '/', '/dir/child'],
	'POSIX: a trailing-slash dir is not doubled'               => ['/dir/', 'child', '/', '/dir/child'],
	'Empty dir returns the bare name'                          => ['', 'child', '/', 'child'],
]);
