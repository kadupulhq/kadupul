<?php
// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-2.0-or-later
require_once dirname(__DIR__, 3) . '/Helpers/SpikekillPathFunctions.php';
require_once dirname(__DIR__, 4) . '/lib/spikekill.php';

test('XML and backup creation bind returned paths to the resolved directory', function ($operation, $collision) {
    $root = sys_get_temp_dir() . '/spike-canonical-' . bin2hex(random_bytes(6));
    mkdir($root . '/trusted/backup', 0700, true);
    mkdir($root . '/outside/backup', 0700, true);
    symlink($root . '/trusted', $root . '/alias');
    file_put_contents($root . '/source.rrd', 'original bytes');
    if ($collision) { file_put_contents($root . '/trusted/backup/copy.rrd', 'existing backup'); }
    $reflection = new ReflectionClass(spikekill::class);
    $instance = $reflection->newInstanceWithoutConstructor();
    $method = $reflection->getMethod($operation);
    $method->setAccessible(true);
    $dir = $root . '/alias/backup';
    $args = $operation === 'copyFileSafely' ? array($root . '/source.rrd', $dir . '/copy.rrd', $dir) : array($dir);
    try {
        $result = $method->invokeArgs($instance, $args);
        expect($result)->toBeArray()
            ->and(dirname($result['path']))->toBe(realpath($root . '/trusted/backup'));
        if (isset($result['handle'])) { fclose($result['handle']); }
        unlink($root . '/alias');
        symlink($root . '/outside', $root . '/alias');
        clearstatcache(true);
        expect(is_file($result['path']))->toBeTrue()
            ->and($method->invokeArgs($instance, $args))->toBeFalse()
            ->and(glob($root . '/outside/backup/*'))->toBe(array());
        if ($operation === 'copyFileSafely') {
            expect(file_get_contents($result['path']))->toBe('original bytes');
        }
        if ($collision) { expect(file_get_contents($root . '/trusted/backup/copy.rrd'))->toBe('existing backup'); }
    } finally {
        foreach (glob($root . '/trusted/backup/*') as $file) { unlink($file); }
        foreach (glob($root . '/outside/backup/*') as $file) { unlink($file); }
        unlink($root . '/source.rrd');
        unlink($root . '/alias');
        foreach (array('/trusted/backup', '/outside/backup', '/trusted', '/outside', '') as $suffix) { rmdir($root . $suffix); }
    }
})->with(array(array('createXmlFileExclusively', false), array('copyFileSafely', false), array('copyFileSafely', true)));
