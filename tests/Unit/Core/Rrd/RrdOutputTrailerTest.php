<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace RrdOutputTrailer;

require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';
$source = file_get_contents(dirname(__DIR__, 4) . '/lib/rrd.php');
if ($source === false) {
	throw new \RuntimeException('Cannot read RRDtool output implementation');
}
eval('namespace ' . __NAMESPACE__ . ';' . \test_php_function_source($source, 'rrdtool_trim_output'));

test('RRDtool output removes only its complete final trailer without altering payload bytes', function ($os, $response, $expected) {
	$had_config = array_key_exists('config', $GLOBALS);
	$saved_config = $GLOBALS['config'] ?? null;
	$GLOBALS['config'] = array('cacti_server_os' => $os);
	try {
		rrdtool_trim_output($response);
		expect($response)->toBe($expected);
	} finally {
		if ($had_config) {
			$GLOBALS['config'] = $saved_config;
		} else {
			unset($GLOBALS['config']);
		}
	}
})->with(require dirname(__DIR__, 3) . '/fixtures/rrd-output-trailer-cases.php');
