<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Tests\Graphing;

use Kadupul\Graphing\Application\Port\StorageRejected;
use Kadupul\Graphing\Infrastructure\Rrd\InvalidRrdConfiguration;
use Kadupul\Graphing\Infrastructure\Rrd\RrdtoolProcess;
use Kadupul\Graphing\Infrastructure\Rrd\UnrepresentableArgument;
use Kadupul\Telemetry\Contract\RejectionReason;
use PHPUnit\Framework\TestCase;

/**
 * Runs stand-in executables written to a temporary directory, so argument
 * passing, exit status, output draining and the timeout are observed
 * directly rather than through rrdtool's own behaviour.
 */
final class RrdtoolProcessTest extends TestCase
{
    private TemporaryRrdRoot $root;

    protected function setUp(): void
    {
        $this->root = TemporaryRrdRoot::create();
    }

    protected function tearDown(): void
    {
        $this->root->remove();
    }

    public function testArgumentsReachTheProgramVerbatimWithoutAShell(): void
    {
        $marker = $this->root->path . '/pwned';
        $process = new RrdtoolProcess($this->script('args', "printf '%s\\n' \"\$@\"\n"));

        $result = $process->run(['info', '$(touch ' . $marker . ')', '; touch ' . $marker, 'a b']);

        self::assertSame(0, $result->exitCode);
        self::assertSame("info\n\$(touch {$marker})\n; touch {$marker}\na b\n", $result->stdout);
        self::assertFileDoesNotExist($marker);
    }

    public function testTheExitStatusAndStandardErrorAreReported(): void
    {
        $result = (new RrdtoolProcess($this->script('fail', "echo 'ERROR: boom' >&2\nexit 3\n")))->run(['update']);

        self::assertSame(3, $result->exitCode);
        self::assertSame("ERROR: boom\n", $result->stderr);
    }

    public function testLargeOutputOnBothStreamsIsDrainedWithoutDeadlock(): void
    {
        // Far beyond a pipe buffer on each stream; a sequential reader would block forever.
        $script = "i=0\nwhile [ \$i -lt 4000 ]; do\n  echo 'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx' >&2\n  echo 'yyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyy'\n  i=\$((i+1))\ndone\n";

        $result = (new RrdtoolProcess($this->script('chatty', $script), 20.0))->run(['info']);

        self::assertSame(0, $result->exitCode);
        self::assertSame(4000 * 80, strlen($result->stdout));
        self::assertSame(4000 * 80, strlen($result->stderr));
    }

    public function testAProgramThatOutlivesTheTimeoutIsStoppedAndReportedAsAStorageFailure(): void
    {
        $started = hrtime(true);
        try {
            (new RrdtoolProcess($this->script('slow', "/bin/sleep 30\n"), 0.5))->run(['update']);
            self::fail('The slow program was not stopped.');
        } catch (StorageRejected $e) {
            self::assertSame(RejectionReason::StorageFailure, $e->reason);
            self::assertStringContainsString('did not finish', $e->getMessage());
        }
        self::assertLessThan(10 * 1000000000, hrtime(true) - $started);
    }

    public function testTheBinaryMustBeAnAbsoluteExecutableFile(): void
    {
        $plain = $this->root->write('not-executable', "#!/bin/sh\n");
        foreach (['rrdtool', '', $this->root->path . '/absent', $this->root->path, $plain, "/bin/sh\0"] as $binary) {
            try {
                new RrdtoolProcess($binary);
                self::fail('Accepted ' . json_encode($binary));
            } catch (InvalidRrdConfiguration) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testTheTimeoutMustBePositive(): void
    {
        $this->expectException(InvalidRrdConfiguration::class);

        new RrdtoolProcess($this->script('ok', "exit 0\n"), 0.0);
    }

    public function testANulInAnArgumentIsRefusedBeforeStarting(): void
    {
        $this->expectException(UnrepresentableArgument::class);

        (new RrdtoolProcess($this->script('ok', "exit 0\n")))->run(["info\0"]);
    }

    private function script(string $name, string $body): string
    {
        $file = $this->root->write($name, "#!/bin/sh\n" . $body);
        chmod($file, 0700);

        return $file;
    }
}
