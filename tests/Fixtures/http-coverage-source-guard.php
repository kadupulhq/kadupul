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
$source = file_get_contents($argv[3] ?? dirname(__DIR__) . '/Symfony/merge_coverage.php');
if (!is_string($source)) {
    throw new RuntimeException('Coverage merger source is unavailable.');
}
eval(test_php_function_source($source, 'symfony_http_coverage_sources'));
eval(test_php_function_source($source, 'require_symfony_integration_sources'));
$root = ($argv[1] ?? '') === 'required' ? dirname(__DIR__, 2) : ($argv[2] ?? dirname(__DIR__, 2));
$paths = symfony_http_coverage_sources($root);
eval(test_php_function_source($source, 'symfony_integration_required_paths'));
if (($argv[1] ?? '') === 'required') {
    print json_encode(symfony_integration_required_paths($argv[2] ?? 'files', []), JSON_THROW_ON_ERROR);
} elseif (($argv[1] ?? '') === 'paths') {
    print json_encode($paths, JSON_THROW_ON_ERROR);
} elseif (($argv[1] ?? '') === 'verify') {
    $manifest = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
    require_symfony_integration_sources($manifest, $paths, $root);
    print 'Verified HTTP source identities';
} elseif (($argv[1] ?? '') === 'import') {
    $input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
    $handler = $input['handler'];
    $legacyCallerPaths = $input['legacy'] ?? [];
    $requiredPaths = symfony_integration_required_paths($handler, $legacyCallerPaths);
    $reports = $input['reports'];
    $observed = $mapped = [];
    $wrapperObserved = false;
    $wrapperLine = 1;
    eval(test_php_block_source($source, 'foreach ($reports as $report)'));
    eval(test_php_block_source($source, 'foreach ($requiredPaths as $required)'));
    print json_encode(array_keys($observed), JSON_THROW_ON_ERROR);
} elseif (($argv[1] ?? '') === 'import-paths') {
    $input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
    $legacyCallerPaths = ['graphs.php'];
    $requiredPaths = symfony_integration_required_paths('files', $legacyCallerPaths);
    $accepted = [];
    $guard = test_php_block_source($source, "if (!str_starts_with(\$relative, 'src/')");
    // Execute the merger's actual continue guard, not a second allowlist.
    eval('foreach ($input as $relative) {' . $guard . '$accepted[] = $relative;}');
    print json_encode($accepted, JSON_THROW_ON_ERROR);
} else {
    throw new InvalidArgumentException('Unknown source-guard fixture operation.');
}
