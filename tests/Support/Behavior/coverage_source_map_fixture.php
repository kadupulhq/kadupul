<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use SebastianBergmann\CodeCoverage\CodeCoverage;

if ($argc !== 3) {
    throw new RuntimeException('Usage: coverage_source_map_fixture.php UNIT.php OUTPUT.php');
}

$root = dirname(__DIR__, 3);
require $root . '/tests/vendor/autoload.php';

$coverage = require $argv[1];
if (!$coverage instanceof CodeCoverage) {
    throw new RuntimeException('Invalid unit coverage artifact');
}

$source = $root . '/cli/poller_output_empty.php';
$copy = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . '/coverage-source-map-' . bin2hex(random_bytes(8))
    . '/cli/poller_output_empty.php';
$directory = dirname($copy);
if (!mkdir($directory, 0700, true) || !copy($source, $copy)) {
    throw new RuntimeException('Unable to create the temporary coverage source');
}

$coverage->filter()->includeFile($copy);
if (file_put_contents($argv[2], serialize($coverage)) === false) {
    throw new RuntimeException('Unable to write unit coverage fixture');
}

$manifest = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . '/kadupul-coverage-source-map-' . bin2hex(random_bytes(8)) . '.json';
$data = json_encode(array('copy' => $copy, 'source' => $source, 'sha256' => hash_file('sha256', $source)), JSON_THROW_ON_ERROR);
if (file_put_contents($manifest, $data, LOCK_EX) === false) {
    throw new RuntimeException('Unable to write coverage source mapping');
}

unlink($copy);
rmdir($directory);
rmdir(dirname($directory));
echo json_encode(array('copy' => $copy, 'manifest' => $manifest), JSON_THROW_ON_ERROR), PHP_EOL;
