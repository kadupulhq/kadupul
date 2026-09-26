<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

final class LegacyResourceWritableTest extends TestCase
{
    public function testLegacyWritableProbePreservesItsCurrentFilesystemBehavior(): void
    {
        $root = sys_get_temp_dir() . '/kadupul-resource-writable-' . bin2hex(random_bytes(6));
        $filesystem = new Filesystem();
        $filesystem->mkdir($root);
        $filesystem->dumpFile($root . '/existing.txt', 'unchanged');

        try {
            $script = 'require ' . var_export(dirname(__DIR__, 2) . '/lib/functions.php', true) . ';'
                . '$root = ' . var_export($root, true) . ';'
                . '$existing = $root . \'/existing.txt\';'
                . '$newFile = $root . \'/new.txt\';'
                . '$directory = $root . \'/probe-directory\'; mkdir($directory);'
                . '$directoryEntriesBefore = scandir($directory);'
                . '$results = ['
                . 'is_resource_writable(\'\'),'
                . 'is_resource_writable(\'0\'),'
                . 'is_resource_writable($existing),'
                . 'file_get_contents($existing),'
                . 'is_resource_writable($newFile),'
                . 'file_exists($newFile),'
                . 'is_resource_writable($directory . \'/\'),'
                . 'scandir($directory) === $directoryEntriesBefore,'
                . 'is_resource_writable($directory),'
                . 'is_resource_writable($root . \'/absent/file.txt\')'
                . '];'
                . 'echo json_encode($results, JSON_THROW_ON_ERROR);';
            $process = new Process([PHP_BINARY, '-r', $script]);
            $process->run();

            self::assertTrue($process->isSuccessful(), $process->getErrorOutput());
            self::assertSame(
                [false, false, true, 'unchanged', true, false, true, true, false, false],
                json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR)
            );
        } finally {
            $filesystem->remove($root);
        }
    }
}
