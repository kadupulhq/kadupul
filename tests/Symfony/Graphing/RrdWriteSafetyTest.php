<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Tests\Graphing;

use PHPUnit\Framework\TestCase;

/**
 * A source scan backing up the behaviour tests: the model-driven write path
 * must not know where legacy RRD files live or read legacy settings, and the
 * tests that create real files must keep them under the temporary directory.
 * It catches a later edit that reintroduces either; it proves nothing about
 * behaviour on its own.
 */
final class RrdWriteSafetyTest extends TestCase
{
    private const WRITE_PATH = [
        'src/Graphing/Application/Command/RecordSamples.php',
        'src/Graphing/Application/Command/WriteGate.php',
        'src/Graphing/Application/Port/Clock.php',
        'src/Graphing/Application/Port/PlannedUpdate.php',
        'src/Graphing/Application/Port/RrdFileInfo.php',
        'src/Graphing/Application/Port/RrdTarget.php',
        'src/Graphing/Application/Port/RrdTargetInspector.php',
        'src/Graphing/Application/Port/RrdTargetLocator.php',
        'src/Graphing/Application/Port/RrdUpdater.php',
        'src/Graphing/Application/Port/StorageRejected.php',
        'src/Graphing/Application/Port/UpdateEntry.php',
        'src/Graphing/Application/Port/UpdateFormatter.php',
        'src/Graphing/Application/Port/UpdatePlan.php',
        'src/Graphing/Infrastructure/Rrd/ConfiguredRootTargetLocator.php',
        'src/Graphing/Infrastructure/Rrd/InvalidRrdConfiguration.php',
        'src/Graphing/Infrastructure/Rrd/RrdtoolInspector.php',
        'src/Graphing/Infrastructure/Rrd/RrdtoolProcess.php',
        'src/Graphing/Infrastructure/Rrd/RrdtoolResult.php',
        'src/Graphing/Infrastructure/Rrd/RrdtoolUpdater.php',
        'src/Graphing/Infrastructure/Rrd/RrdUpdateFormatter.php',
    ];

    /** Functions that create or move filesystem entries. */
    private const CREATING = ['file_put_contents', 'fopen', 'mkdir', 'symlink', 'link', 'touch', 'tempnam', 'tmpfile', 'copy', 'rename'];

    public function testTheWritePathNeverNamesLegacyRrdLocationsOrSettings(): void
    {
        $root = dirname(__DIR__, 3);
        foreach (self::WRITE_PATH as $relative) {
            $source = file_get_contents($root . '/' . $relative);
            self::assertIsString($source, $relative . ' is missing; keep this list in step with the write path.');
            foreach (['/rra', 'path_rra', 'read_config_option'] as $forbidden) {
                self::assertStringNotContainsStringIgnoringCase($forbidden, $source, $relative);
            }
        }
    }

    public function testOnlyTheTemporaryRootCreatesFilesInTheseTests(): void
    {
        $creators = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'php' || $file->getPathname() === __FILE__) {
                continue;
            }
            $previous = null;
            foreach (token_get_all((string) file_get_contents($file->getPathname())) as $token) {
                if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                // Methods such as TemporaryRrdRoot::symlink() share these names; only the global functions count.
                $isMethod = is_array($previous) && in_array($previous[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION], true);
                if (is_array($token) && $token[0] === T_STRING && !$isMethod && in_array(strtolower($token[1]), self::CREATING, true)) {
                    $creators[substr($file->getPathname(), strlen(__DIR__) + 1)] = true;
                }
                $previous = $token;
            }
        }

        self::assertSame(['TemporaryRrdRoot.php'], array_keys($creators));
    }

    public function testTheTemporaryRootLivesUnderTheSystemTemporaryDirectoryAndRefusesToLeaveIt(): void
    {
        $root = TemporaryRrdRoot::create();
        try {
            self::assertStringStartsWith((string) realpath(sys_get_temp_dir()) . '/', $root->path);
            foreach (['../escape', '/etc/escape', 'a/../../escape'] as $relative) {
                try {
                    $root->write($relative, 'x');
                    self::fail('Wrote ' . $relative);
                } catch (\LogicException) {
                    $this->addToAssertionCount(1);
                }
            }
        } finally {
            $root->remove();
        }
        self::assertDirectoryDoesNotExist($root->path);
    }
}
