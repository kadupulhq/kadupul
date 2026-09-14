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
 * cacti_trim_dir_separator() (lib/functions.php) strips a trailing
 * directory separator from a configured or derived directory before
 * is_link() ever sees it: is_link('dir/') follows the final symlink to
 * stat what it points at instead of the link itself, so a symlinked
 * backup or temp directory configured with a trailing separator would
 * otherwise pass an is_link() check it is meant to fail.
 *
 * spikekill::normalizeDir() (lib/spikekill.php) and
 * poller_spikekill.php's purge_spike_backups() both delegate to this one
 * pure function instead of each trimming locally, so a fix here reaches
 * both call sites and the Windows separator table below covers both.
 * $separator is a parameter, not DIRECTORY_SEPARATOR read internally,
 * specifically so the Windows rows can run on this (POSIX) CI host.
 *
 * Extracted by source and eval()'d, the same technique
 * StructureRraPathsDestDirSafetyTest.php uses for a plain (non-class)
 * function.
 */

/* guarded because SpikekillBackupSymlinkSafetyTest.php,
   SpikekillRemoveSpikesEndToEndTest.php, PurgeSpikeBackupsWritableCheckTest.php
   and PurgeSpikeBackupsRootBackupDirTest.php extract the same function and
   all of these files run in the same Pest process */
if (!function_exists('cacti_trim_dir_separator')) {
	$source = file_get_contents(dirname(__DIR__, 4) . '/lib/functions.php');

	$start = strpos($source, 'function cacti_trim_dir_separator(');
	expect($start)->not->toBeFalse();

	$end = strpos($source, "\n}\n", $start);
	$body = substr($source, $start, $end - $start + 2);

	eval($body); // nosemgrep: php.lang.security.eval-use.eval-use
}

test('POSIX and Windows trailing separators are trimmed the same way a plain path is expected to', function (string $dir, string $separator, string $expected) {
	expect(cacti_trim_dir_separator($dir, $separator))->toBe($expected);
})->with([
	'POSIX: no trailing slash is left alone'        => ['/var/lib/cacti/backups', '/', '/var/lib/cacti/backups'],
	'POSIX: one trailing slash is trimmed'           => ['/var/lib/cacti/backups/', '/', '/var/lib/cacti/backups'],
	'POSIX: bare root stays /'                       => ['/', '/', '/'],
	'POSIX: repeated trailing slashes collapse to /' => ['///', '/', '/'],
	'POSIX: empty string is left alone'              => ['', '/', ''],
	'Windows: no trailing backslash is left alone'   => ['C:\\cacti\\backups', '\\', 'C:\\cacti\\backups'],
	'Windows: one trailing backslash is trimmed'     => ['C:\\cacti\\backups\\', '\\', 'C:\\cacti\\backups'],
	'Windows: a drive root keeps its backslash'      => ['C:\\', '\\', 'C:\\'],
	'Windows: repeated trailing backslashes collapse to the drive root' => ['C:\\\\\\', '\\', 'C:\\'],
	'Windows: a bare backslash stays \\'             => ['\\', '\\', '\\'],
	'Windows: a UNC root keeps its leading \\\\'     => ['\\\\server\\share\\', '\\', '\\\\server\\share'],
	'Windows: empty string is left alone'            => ['', '\\', ''],
	'Windows: a drive-relative path with no separator stays as-is' => ['C:', '\\', 'C:'],
	'Windows: a forward-slash drive root keeps its forward slash'  => ['C:/', '\\', 'C:/'],
	'Windows: a backslash drive root keeps its backslash'          => ['C:\\', '\\', 'C:\\'],
	'Windows: a backslash-terminated dir trims to no separator'    => ['C:\\dir\\', '\\', 'C:\\dir'],
	'Windows: a forward-slash-terminated dir trims to no separator' => ['C:/dir/', '\\', 'C:/dir'],
	'Windows: a mixed-slash dir trims the trailing forward slash'  => ['C:\\dir/', '\\', 'C:\\dir'],
	'Windows: a bare forward slash stays /'                        => ['/', '\\', '/'],
	'Windows: a bare backslash stays \\ (mixed-separator table)'   => ['\\', '\\', '\\'],
	'Windows: a forward-slash UNC root is trimmed'                 => ['//server/share/', '\\', '//server/share'],
	'Windows: a backslash UNC root keeps its leading \\\\ (mixed-separator table)' => ['\\\\server\\share\\', '\\', '\\\\server\\share'],
	'Windows: a forward-slash-terminated dir is trimmed'           => ['dir/', '\\', 'dir'],
	'Windows: a backslash-terminated dir is trimmed'               => ['dir\\', '\\', 'dir'],
]);
