<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

if (PHP_SAPI !== 'cli') {
    throw new RuntimeException('CLI fixture only.');
}
require dirname(__DIR__) . '/Helpers/PhpSource.php';
$source = file_get_contents(dirname(__DIR__) . '/Symfony/merge_coverage.php');
if (!is_string($source)) {
    throw new RuntimeException('Coverage merger source is unavailable.');
}
eval(test_php_function_source($source, 'symfony_http_coverage_sources'));
eval(test_php_function_source($source, 'require_symfony_integration_sources'));
$root = $argv[2] ?? dirname(__DIR__, 2);
$paths = symfony_http_coverage_sources($root);
if (($argv[1] ?? '') === 'paths') {
    print json_encode($paths, JSON_THROW_ON_ERROR);
} elseif (($argv[1] ?? '') === 'verify') {
    $manifest = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
    require_symfony_integration_sources($manifest, $paths, $root);
    print 'Verified HTTP source identities';
} else {
    throw new InvalidArgumentException('Unknown source-guard fixture operation.');
}
