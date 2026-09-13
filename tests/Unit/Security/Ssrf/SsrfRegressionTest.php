<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
*/

/*
 * Consolidated SSRF / outbound HTTP regression tests.
 *
 * Combines the previously separate per-advisory test files for:
 * GHSA-23p9-hfcc-w864 and GHSA-9mf9-v3mh-89cv.
 *
 * Each test below keeps its original GHSA identifier in its description
 * so the advisory it guards against remains traceable.
 */

$functionsSource  = file_get_contents(__DIR__ . '/../../../../lib/functions.php');
$graphImageSource = file_get_contents(__DIR__ . '/../../../../graph_image.php');
$graphJsonSource  = file_get_contents(__DIR__ . '/../../../../graph_json.php');

// GHSA-23p9: outbound HTTP context must verify_peer, reject self-signed certs, and
// not auto-follow redirects.
test('GHSA-23p9: lib/functions.php contains the 23p9 fix', function () use ($functionsSource) {
	expect($functionsSource)->not->toBeFalse();
	// Fix-specific assertion anchors below:
	// Peer verification stays on unless the admin opts into allow_unsafe_https.
	expect($functionsSource)->toMatch('/\'verify_peer\'\s*=>\s*read_config_option\(\'allow_unsafe_https\'\)\s*!=\s*\'on\'\s*\?\s*true\s*:\s*false,/');
	expect($functionsSource)->toMatch('/\'verify_peer_name\'\s*=>\s*read_config_option\(\'allow_unsafe_https\'\)\s*!=\s*\'on\'\s*\?\s*true\s*:\s*false,/');
	expect($functionsSource)->toMatch('/\'allow_self_signed\'\s*=>\s*read_config_option\(\'allow_unsafe_https\'\)\s*==\s*\'on\'\s*\?\s*true\s*:\s*false,/');
	expect($functionsSource)->not->toMatch('/\'verify_peer(_name)?\'\s*=>\s*false/');
	expect($functionsSource)->toMatch('/\'follow_location\'\s*=>\s*0,/');
});

// GHSA-9mf9: remote agent URL must rawurlencode() keys and values.
test('GHSA-9mf9: graph_image.php contains the 9mf9 fix', function () use ($graphImageSource) {
	expect($graphImageSource)->not->toBeFalse();
	// Fix-specific assertion anchors below:
	expect($graphImageSource)->toContain('rawurlencode((string)$variable)');
	expect($graphImageSource)->toContain('rawurlencode((string)$value)');
});

test('GHSA-9mf9: graph_json.php contains the 9mf9 fix', function () use ($graphJsonSource) {
	expect($graphJsonSource)->not->toBeFalse();
	// Fix-specific assertion anchors below:
	expect($graphJsonSource)->toContain('rawurlencode((string)$variable)');
	expect($graphJsonSource)->toContain('rawurlencode((string)$value)');
});
