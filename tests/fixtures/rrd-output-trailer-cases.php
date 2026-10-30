<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

$cases = array();
$payloads = array(
	'empty' => '',
	'O' => 'PNGDATAO',
	'K' => 'PNGDATAK',
	'space' => 'PNGDATA ',
	'CR' => "PNGDATA\r",
	'LF' => "PNGDATA\n",
	'CRLF' => "PNGDATA\r\n",
	'binary' => "\x89PNG\r\n\x1a\n\x00\xffOK \r\n",
	'earlier status' => "first\nOK u:0.01\nsecond K",
);
$trailers = array(
	'bare LF' => "OK\n",
	'bare CRLF' => "OK\r\n",
	'timed LF' => "OK u:0.01 s:0.02 r:0.03\n",
	'timed CRLF' => "OK u:0.01 s:0.02 r:0.03\r\n",
	'user time only' => "OK u:0.01\n",
);
foreach ($payloads as $name => $payload) {
	foreach ($trailers as $format => $trailer) {
		$cases[$name . ', ' . $format] = array('win32', $payload . $trailer, $payload);
	}
}
foreach (array('', 'PNGDATAK', 'PNGDATAO ', "payload\r\n", "ERROR: broken\n", 'payloadOK',
	'payloadOK u:0.01', "payloadOK\nextra", "payloadOK\n\n", "payloadOK u:0.01\nextra") as $index => $response) {
	$cases['no complete trailer ' . $index] = array('win32', $response, $response);
}
$cases['Unix timed trailer'] = array('unix', "payload K\r\nOK u:0.01 s:0.02 r:0.03\n", "payload K\r\n");
$cases['Unix bare response unchanged'] = array('unix', "payload K\r\nOK\n", "payload K\r\nOK\n");
$cases['Unix payload unchanged'] = array('unix', "payload K\r\n", "payload K\r\n");

return $cases;
