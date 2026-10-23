<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Graphing\Infrastructure\Rrd;

use Kadupul\Graphing\Application\Port\StorageRejected;
use Kadupul\Telemetry\Contract\RejectionReason;

/**
 * Runs one rrdtool command as an argument vector, never through a shell,
 * with a deadline. The binary is configured explicitly rather than read
 * from legacy settings, so the write path does not depend on the legacy
 * bootstrap and cannot be redirected by a stored setting.
 */
final class RrdtoolProcess
{
    private const CHUNK = 65536;
    private const MAX_CAPTURE = 1048576;

    public function __construct(
        private readonly string $binary,
        private readonly float $timeoutSeconds = 10.0,
    ) {
        if (!str_starts_with($binary, '/') || str_contains($binary, "\0") || !is_file($binary) || !is_executable($binary)) {
            throw new InvalidRrdConfiguration('The rrdtool binary must be an absolute path to an executable file.');
        }
        if ($timeoutSeconds <= 0.0) {
            throw new InvalidRrdConfiguration('The rrdtool timeout must be a positive number of seconds.');
        }
    }

    /**
     * @param list<string> $arguments
     * @throws StorageRejected when rrdtool cannot be started or overruns its deadline
     */
    public function run(array $arguments): RrdtoolResult
    {
        foreach ($arguments as $argument) {
            if (str_contains($argument, "\0")) {
                throw new UnrepresentableArgument('An rrdtool argument cannot contain NUL.');
            }
        }
        $deadline = hrtime(true) + (int) ($this->timeoutSeconds * 1e9);
        // A fixed environment keeps rrdtool's output format and time zone independent of the caller's.
        $process = proc_open(
            [$this->binary, ...$arguments],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            ['PATH' => '/usr/bin:/bin', 'LC_ALL' => 'C', 'TZ' => 'UTC'],
        );
        if (!is_resource($process)) {
            throw new StorageRejected(RejectionReason::StorageFailure, 'rrdtool could not be started.');
        }
        fclose($pipes[0]);
        $captured = [1 => '', 2 => ''];
        $open = [1 => $pipes[1], 2 => $pipes[2]];
        foreach ($open as $stream) {
            stream_set_blocking($stream, false);
        }
        try {
            // Both streams are drained together; reading one to the end first can deadlock once the other fills its pipe.
            while ($open !== []) {
                $remaining = $deadline - hrtime(true);
                if ($remaining <= 0) {
                    throw $this->timedOut();
                }
                $read = array_values($open);
                $write = null;
                $except = null;
                if (stream_select($read, $write, $except, intdiv($remaining, 1000000000), intdiv($remaining % 1000000000, 1000)) === false) {
                    throw new StorageRejected(RejectionReason::StorageFailure, 'Waiting for rrdtool output failed.');
                }
                foreach ($read as $stream) {
                    $index = array_search($stream, $open, true);
                    $chunk = fread($stream, self::CHUNK);
                    if ($chunk !== false && $chunk !== '') {
                        if (strlen($captured[$index]) < self::MAX_CAPTURE) {
                            $captured[$index] .= substr($chunk, 0, self::MAX_CAPTURE - strlen($captured[$index]));
                        }
                    } elseif (feof($stream)) {
                        fclose($stream);
                        unset($open[$index]);
                    }
                }
            }
            $exitCode = $this->waitForExit($process, $deadline);
        } catch (StorageRejected $e) {
            proc_terminate($process, 9);
            foreach ($open as $stream) {
                fclose($stream);
            }
            proc_close($process);
            throw $e;
        }
        proc_close($process);

        return new RrdtoolResult($exitCode, $captured[1], $captured[2]);
    }

    /** @param resource $process */
    private function waitForExit($process, int $deadline): int
    {
        // proc_get_status reports the exit code only the first time it sees the process stopped, so it is read here once.
        while (true) {
            $status = proc_get_status($process);
            if (!$status['running']) {
                return $status['signaled'] ? 128 + $status['termsig'] : $status['exitcode'];
            }
            if (hrtime(true) >= $deadline) {
                throw $this->timedOut();
            }
            usleep(1000);
        }
    }

    private function timedOut(): StorageRejected
    {
        return new StorageRejected(RejectionReason::StorageFailure, sprintf('rrdtool did not finish within %s seconds and was stopped.', rtrim(rtrim(sprintf('%.3f', $this->timeoutSeconds), '0'), '.')));
    }
}
