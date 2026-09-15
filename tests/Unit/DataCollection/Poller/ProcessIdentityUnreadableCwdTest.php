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

/*
 * cacti_process_identity_matches() resolves a relative script argument
 * against the working directory of the process that was launched with it.
 * When that directory cannot be read, resolving the argument against the
 * checker's own directory could match an unrelated process. The real function
 * runs here in a namespace with procfs stubbed, so it runs on any platform.
 */

namespace ProcessIdentityUnreadableCwdTest;

$source = \file_get_contents(dirname(__DIR__, 4) . '/lib/poller.php');
preg_match('/^function cacti_process_identity_matches\(.*?^}\R/ms', $source, $matches);

// test-only eval of source read from this repository, not external input
eval('namespace ProcessIdentityUnreadableCwdTest;' . $matches[0]);

function getmypid() {
	return 100;
}

function file_get_contents($filename) {
	return $GLOBALS['identity_cmdline'][$filename] ?? false;
}

function readlink($path) {
	return $GLOBALS['identity_links'][$path] ?? false;
}

/* The checker runs from /srv/cacti, which is where a relative path falls
 * back to when nothing else anchors it. */
function realpath($path) {
	return $path[0] === '/' ? $path : '/srv/cacti/' . $path;
}

beforeEach(function () {
	$GLOBALS['identity_cmdline'] = array(
		'/proc/100/cmdline' => "php\0/srv/cacti/poller.php\0",
		'/proc/200/cmdline' => "php\0poller.php\0",
	);
	$GLOBALS['identity_links'] = array();
});

test('a relative script whose working directory cannot be read is inconclusive, not a match', function () {
	expect(cacti_process_identity_matches(200))->toBeNull();
});

test('a relative script is still resolved against a readable working directory', function () {
	$GLOBALS['identity_links']['/proc/200/cwd'] = '/srv/cacti';

	expect(cacti_process_identity_matches(200))->toBeTrue();

	$GLOBALS['identity_links']['/proc/200/cwd'] = '/opt/other';

	expect(cacti_process_identity_matches(200))->toBeFalse();
});

test('absolute script paths are compared without reading a working directory', function () {
	$GLOBALS['identity_cmdline']['/proc/200/cmdline'] = "php\0/srv/cacti/poller.php\0";

	expect(cacti_process_identity_matches(200))->toBeTrue();

	$GLOBALS['identity_cmdline']['/proc/200/cmdline'] = "php\0/opt/other/poller.php\0";

	expect(cacti_process_identity_matches(200))->toBeFalse();
});
