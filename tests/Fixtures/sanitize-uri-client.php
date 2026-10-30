<?php
declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

$root = $argv[1];
require $root . '/tests/Helpers/PhpSource.php';
$input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$source = file_get_contents($root . '/lib/functions.php');
foreach (array('sanitize_uri', 'is_urlencoded') as $function) {
	eval(test_php_function_source($source, $function));
}
function get_nfilter_request_var($name) {
	if ($name !== 'action') throw new RuntimeException('Unexpected request field');
	return 'tree';
}
echo json_encode(sanitize_uri($input), JSON_THROW_ON_ERROR);
