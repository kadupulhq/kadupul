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

    public function testShellSyntaxQuotesAndLeadingDashesReachTheProgramAsSeparateArguments(): void
    {
        $marker = $this->root->path . '/pwned';
        $arguments = ['--daemon', '-', "it's \"quoted\"", 'a;touch ' . $marker, '$(touch ' . $marker . ')', '`touch ' . $marker . '`', '$HOME', '*', '', "two\nlines", 'a|b&c>d'];
        $process = new RrdtoolProcess($this->script('args', "for a in \"\$@\"; do printf '[%s]' \"\$a\"; done\n"));

        $result = $process->run($arguments);

        self::assertSame(0, $result->exitCode);
        self::assertSame(implode('', array_map(static fn(string $a): string => '[' . $a . ']', $arguments)), $result->stdout);
        self::assertFileDoesNotExist($marker);
    }

    public function testTheProgramSeesOnlyTheFixedEnvironment(): void
    {
        putenv('KADUPUL_RRDTOOL_PROCESS_CANARY=inherited');
        $_ENV['KADUPUL_RRDTOOL_PROCESS_CANARY'] = 'inherited';
        try {
            $result = (new RrdtoolProcess($this->script('env', "printf '%s|%s|%s|%s' \"\$PATH\" \"\$LC_ALL\" \"\$TZ\" \"\${KADUPUL_RRDTOOL_PROCESS_CANARY-unset}\"\n")))->run([]);
        } finally {
            putenv('KADUPUL_RRDTOOL_PROCESS_CANARY');
            unset($_ENV['KADUPUL_RRDTOOL_PROCESS_CANARY']);
        }

        self::assertSame('/usr/bin:/bin|C|UTC|unset', $result->stdout);
    }

    public function testStandardInputIsClosedSoAProgramReadingItDoesNotWait(): void
    {
        $result = (new RrdtoolProcess($this->script('reader', "exec /bin/cat\n"), 5.0))->run(['-']);

        self::assertSame(0, $result->exitCode);
        self::assertSame('', $result->stdout);
    }

    public function testEachStreamIsCappedAtOneMebibyte(): void
    {
        $script = "/usr/bin/head -c 1572864 /dev/zero\n/usr/bin/head -c 1572864 /dev/zero >&2\n";

        $result = (new RrdtoolProcess($this->script('flood', $script), 20.0))->run(['info']);

        self::assertSame(0, $result->exitCode);
        self::assertSame(1048576, strlen($result->stdout));
        self::assertSame(1048576, strlen($result->stderr));
    }

    public function testAProgramKilledByASignalReportsTheShellExitConvention(): void
    {
        $result = (new RrdtoolProcess($this->script('crash', "kill -KILL \$\$\n")))->run(['update']);

        self::assertSame(137, $result->exitCode);
    }

    public function testABinaryRemovedAfterConfigurationIsAStorageFailureNotAShellError(): void
    {
        $binary = $this->script('gone', "exit 0\n");
        $process = new RrdtoolProcess($binary);
        unlink($binary);

        $this->expectExceptionObject(new StorageRejected(RejectionReason::StorageFailure, 'rrdtool could not be started.'));

        $process->run(['info']);
    }

    public function testABinaryThatStopsBeingExecutableIsAStorageFailure(): void
    {
        $binary = $this->script('locked', "exit 0\n");
        $process = new RrdtoolProcess($binary);
        chmod($binary, 0600);

        try {
            $process->run(['info']);
            self::fail('A non-executable binary was run.');
        } catch (StorageRejected $e) {
            self::assertSame(RejectionReason::StorageFailure, $e->reason);
            self::assertSame('rrdtool could not be started.', $e->getMessage());
        }
    }

    public function testATimedOutProgramIsKilledAndReaped(): void
    {
        $pidFile = $this->root->path . '/pid';
        $process = new RrdtoolProcess($this->script('hang', "echo \$\$ > '{$pidFile}'\nexec /bin/sleep 30\n"), 0.5);

        try {
            $process->run(['update']);
            self::fail('The hung program was not stopped.');
        } catch (StorageRejected $e) {
            self::assertSame(RejectionReason::StorageFailure, $e->reason);
            self::assertSame('rrdtool did not finish within 0.5 seconds and was stopped.', $e->getMessage());
        }

        $pid = (int) file_get_contents($pidFile);
        self::assertGreaterThan(1, $pid);
        // A zombie still answers signal 0, so this also proves the child was reaped.
        self::assertFalse(posix_kill($pid, 0), 'The child process ' . $pid . ' is still present after the timeout.');
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
