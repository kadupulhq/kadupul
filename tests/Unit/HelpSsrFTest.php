<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 +-------------------------------------------------------------------------+
 +-------------------------------------------------------------------------+
*/

/*
 * Tests for SSRF hardening in help.php.
 *
 * Local document paths are reduced to a basename. Online help returns a
 * fixed destination without fetching a user-influenced URL.
 */

$helpPath = __DIR__ . '/../../help.php';

// --- help.php: local paths and the fixed online destination ---

test('help.php uses basename for page parameter', function () use ($helpPath) {
	$contents = file_get_contents($helpPath);

	expect($contents)->toContain('basename(');
});

test('help.php uses a fixed online destination without fetching a URL', function () use ($helpPath) {
	$contents = file_get_contents($helpPath);

	expect($contents)->toContain("'location' => 'https://kadupul.org/map/'");
	expect($contents)->not->toContain('cacti_http(');
	expect($contents)->not->toContain('file_get_contents(');
});
