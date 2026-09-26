<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

require_once dirname(__DIR__) . '/Helpers/PhpSource.php';

final class LegacyResourceWritableTest extends TestCase
{
    public function testLegacyWritableProbePreservesItsCurrentFilesystemBehavior(): void
    {
        $root = sys_get_temp_dir() . '/kadupul-resource-writable-' . bin2hex(random_bytes(6));
        $filesystem = new Filesystem();
        $filesystem->mkdir($root);
        $filesystem->dumpFile($root . '/existing.txt', 'unchanged');

        try {
            $source = file_get_contents(dirname(__DIR__, 2) . '/lib/functions.php');
            self::assertIsString($source);
            $script = 'eval(' . var_export(test_php_function_source($source, 'is_resource_writable'), true) . ');'
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

    public function testLegacyWritableProbeReportsPermissionDeniedPathsAsNotWritable(): void
    {
        if (PHP_OS_FAMILY === 'Windows' || !function_exists('posix_geteuid')) {
            self::markTestSkipped('Permission bits and an unprivileged process are required.');
        }

        $root = '/tmp/kadupul-resource-permissions-' . bin2hex(random_bytes(6));
        mkdir($root, 0700);
        mkdir($root . '/locked', 0700);
        file_put_contents($root . '/locked/existing.txt', 'unchanged');
        chmod($root, 0755);
        chmod($root . '/locked', 0555);
        chmod($root . '/locked/existing.txt', 0444);

        try {
            $source = file_get_contents(dirname(__DIR__, 2) . '/lib/functions.php');
            self::assertIsString($source);
            $script = 'eval(' . var_export(test_php_function_source($source, 'is_resource_writable'), true) . ');'
                . '$root = ' . var_export($root, true) . ';'
                . 'echo json_encode([is_resource_writable($root . "/locked/existing.txt"),'
                . 'is_resource_writable($root . "/locked/new.txt"),'
                . 'is_resource_writable($root . "/locked/")], JSON_THROW_ON_ERROR);';
            $process = new Process([PHP_BINARY, '-r', $script]);

            if (posix_geteuid() === 0) {
                $nobody = posix_getpwnam('nobody');
                self::assertIsArray($nobody, 'A nobody account is required when the test runner is root.');
                $process->setUser('nobody');
            }

            $process->run();
            self::assertTrue($process->isSuccessful(), $process->getErrorOutput());
            self::assertSame([false, false, false], json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR));
        } finally {
            chmod($root . '/locked', 0700);
            chmod($root . '/locked/existing.txt', 0600);
            unlink($root . '/locked/existing.txt');
            rmdir($root . '/locked');
            rmdir($root);
        }
    }
}
