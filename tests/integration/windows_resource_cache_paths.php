<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

if (PHP_OS_FAMILY !== 'Windows') {
    throw new RuntimeException('Resource stream aliases require native Windows validation.');
}
$directory = getenv('WINDOWS_STORAGE_TEST_ROOT');
if (!$directory || !is_dir($directory)) {
    throw new RuntimeException('The owned Windows fixture directory is missing.');
}
$root = dirname(__DIR__, 2);
require $root . '/tests/Helpers/PhpSource.php';
foreach (['lib/functions.php' => ['cacti_path_is_within', 'cacti_normalize_windows_path'], 'lib/poller.php' => ['should_ignore_from_replication', 'poller_resource_cache_destination']] as $file => $helpers) {
    $source = file_get_contents($root . '/' . $file);
    if (!is_string($source)) throw new RuntimeException('Production path helpers are unreadable.');
    foreach ($helpers as $helper) eval(test_php_function_source($source, $helper));
}
foreach (['include/config.php::$DATA', 'include/config.php:stream', 'plugins/x/file:stream', 'a/../b', '/etc/passwd', 'C:x', 'a\\b', "a\0b", 'a//b', 'a/', 'plugins/x/.git/config'] as $path) {
    if (!should_ignore_from_replication($path)) throw new RuntimeException('Unsafe Windows resource path was admitted.');
}
foreach (['plugins/thold/config.php', 'include/fa/x.css', '.github'] as $path) {
    if (should_ignore_from_replication($path)) throw new RuntimeException('A legitimate resource path was rejected.');
}
$install = $directory . '/resource-install';
if (!mkdir($install) || !mkdir($install . '/include')) throw new RuntimeException('Unable to create owned resource fixture.');
$config = $install . '/include/config.php';
try {
    if (file_put_contents($config, 'protected-configuration') !== 23) throw new RuntimeException('Configuration fixture write failed.');
    foreach (['include/config.php', 'include/CONFIG.php', 'include/config.php::$DATA', 'include/config.php:stream'] as $path) {
        if (poller_resource_cache_destination($path, realpath($install), realpath($config)) !== false) {
            throw new RuntimeException('Protected configuration alias was admitted.');
        }
    }
    if (file_get_contents($config) !== 'protected-configuration') throw new RuntimeException('Protected fixture changed.');
    if (poller_resource_cache_destination('plugins/thold/config.php', realpath($install), realpath($config)) === false) {
        throw new RuntimeException('Plugin configuration replication was blocked.');
    }
} finally {
    unlink($config);
    rmdir($install . '/include');
    rmdir($install);
}
echo "Windows resource cache rejects NTFS stream aliases and preserves plugin configuration paths.\n";
