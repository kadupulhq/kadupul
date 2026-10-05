<?php

declare(strict_types=1);

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

namespace PrepareMultiDelimiterTest;

require_once dirname(__DIR__, 3).'/Helpers/PhpSource.php';
$source = file_get_contents(dirname(__DIR__, 4).'/lib/functions.php');
if ($source === false) { throw new \RuntimeException('Unable to read production result helpers'); }
if (!defined('POLLER_VERBOSITY_MEDIUM')) { define('POLLER_VERBOSITY_MEDIUM', 2); }
function dsv_log(...$arguments) {}
foreach (['cacti_sizeof', 'is_hexadecimal', 'strip_alpha', 'normalize_poller_multi_value_result', 'prepare_validate_result'] as $function) {
    eval('namespace '.__NAMESPACE__.';'.test_php_function_source($source, $function));
}

test('real validator normalizes complete field lists and preserves scalar contracts', function ($input, $expected, $valid) {
    $result = $input;
    expect(prepare_validate_result($result))->toBe($valid)->and($result)->toBe($expected);
})->with([
    ['users!14 load!0.42', 'users:14 load:0.42', true],
    ['users:14 load!0.42', 'users:14 load:0.42', true],
    ["users!14\tload!-0.42", 'users:14 load:-0.42', true],
    ['users!U load!1e-3', 'users:U load:1e-3', true],
    ['users!14 load', 'users!14 load', false],
    ['a!b:c 1', 'a!b:c 1', false],
    ['Hello!', 'Hello!', true],
    ['0a!1b', '0a!1b', true],
    ['00!00', '00!00', true],
    ['42', '42', true],
    ['U', 'U', true],
    ['00:00', '00:00', 0],
    ['unknown', 'U', false],
]);
