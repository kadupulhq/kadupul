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
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * Tests for SSRF hardening in help.php.
 *
 * The fix adds basename() to prevent path traversal in the page parameter,
 * enables SSL verification (verify_peer, verify_peer_name), and limits
 * redirects to prevent SSRF via fetch.
 */

$helpPath = __DIR__ . '/../../../../help.php';

// --- help.php: path traversal and SSL verification ---

test('help.php uses basename for page parameter', function () use ($helpPath) {
	$contents = file_get_contents($helpPath);

	expect($contents)->toContain('basename(');
});

test('help.php fetches documentation through the TLS and redirect policy gateway', function () use ($helpPath) {
    $contents = file_get_contents($helpPath);
    expect($contents)->toContain("cacti_http('https://docs.cacti.net/' . \$page, 2, array('docs.cacti.net'), \$response_code)");
});
