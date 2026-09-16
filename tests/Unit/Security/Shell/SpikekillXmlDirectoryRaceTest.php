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
    if ($collision) {
        file_put_contents($root . '/trusted/backup/copy.rrd', 'existing backup');
    }
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
        if (isset($result['handle'])) {
            fclose($result['handle']);
        }
        unlink($root . '/alias');
        symlink($root . '/outside', $root . '/alias');
        clearstatcache(true);
        expect(is_file($result['path']))->toBeTrue()
            ->and($method->invokeArgs($instance, $args))->toBeFalse()
            ->and(glob($root . '/outside/backup/*'))->toBe(array());
        if ($operation === 'copyFileSafely') {
            expect(file_get_contents($result['path']))->toBe('original bytes');
        }
        if ($collision) {
            expect(file_get_contents($root . '/trusted/backup/copy.rrd'))->toBe('existing backup');
        }
    } finally {
        foreach (glob($root . '/trusted/backup/*') as $file) {
            unlink($file);
        }
        foreach (glob($root . '/outside/backup/*') as $file) {
            unlink($file);
        }
        unlink($root . '/source.rrd');
        unlink($root . '/alias');
        foreach (array('/trusted/backup', '/outside/backup', '/trusted', '/outside', '') as $suffix) {
            rmdir($root . $suffix);
        }
    }
})->with(array(array('createXmlFileExclusively', false), array('copyFileSafely', false), array('copyFileSafely', true)));

class SpikeDirectorySwap extends spikekill
{
    public $swapDirectory;
    protected function randomFileSuffix()
    {
        rename($this->swapDirectory, $this->swapDirectory . '.original');
        mkdir($this->swapDirectory, 0700);
        return parent::randomFileSuffix();
    }
}

test('directory identity rejects replacement before and during exclusive creation', function ($operation, $during) {
    $root = sys_get_temp_dir() . '/spike-inode-' . bin2hex(random_bytes(6));
    mkdir($root . '/target', 0700, true);
    file_put_contents($root . '/source.rrd', 'original bytes');
    $class = new ReflectionClass($during ? SpikeDirectorySwap::class : spikekill::class);
    $instance = $class->newInstanceWithoutConstructor();
    if ($during) {
        $instance->swapDirectory = $root . '/target';
    }
    $method = new ReflectionMethod(spikekill::class, $operation);
    $method->setAccessible(true);
    $canonical = new ReflectionMethod(spikekill::class, 'canonicalDir');
    $canonical->setAccessible(true);
    $canonical->invoke($instance, $root . '/target');
    if ($operation === 'copyFileSafely') {
        file_put_contents($root . '/target/copy.rrd', 'collision');
    }
    if (!$during) {
        rename($root . '/target', $root . '/target.original');
        mkdir($root . '/target', 0700);
    }
    $args = $operation === 'copyFileSafely' ? array($root . '/source.rrd', $root . '/target/copy.rrd', $root . '/target') : array($root . '/target');
    try {
        expect($method->invokeArgs($instance, $args))->toBeFalse()
            ->and(glob($root . '/target/*'))->toBe(array());
    } finally {
        foreach (array('/target', '/target.original') as $dir) {
            if (is_dir($root . $dir)) {
                foreach (glob($root . $dir . '/*') as $file) {
                    unlink($file);
                }
                rmdir($root . $dir);
            }
        }
        unlink($root . '/source.rrd');
        rmdir($root);
    }
})->with(array(array('createXmlFileExclusively', false), array('copyFileSafely', false), array('createXmlFileExclusively', true), array('copyFileSafely', true)));
