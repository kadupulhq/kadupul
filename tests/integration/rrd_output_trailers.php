<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit(1);
}
if (($argv[1] ?? '') === '--native-windows' && PHP_OS_FAMILY !== 'Windows') {
	throw new RuntimeException('This CI mode requires a native Windows interpreter.');
}
function read_config_option($key) {
	return false;
}
require dirname(__DIR__, 2) . '/lib/rrd.php';
$cases = require dirname(__DIR__) . '/fixtures/rrd-output-trailer-cases.php';
if (!is_array($cases) || count($cases) === 0) {
	throw new RuntimeException('RRDtool response cases were not discovered.');
}
foreach ($cases as $name => [$os, $response, $expected]) {
	$config = array('cacti_server_os' => $os);
	rrdtool_trim_output($response);
	if ($response !== $expected) {
		throw new RuntimeException($name . ': expected ' . base64_encode($expected) . ', got ' . base64_encode($response));
	}
}
print 'Verified ' . count($cases) . ' RRDtool trailer cases on ' . PHP_OS_FAMILY . " with the complete production library.\n";
