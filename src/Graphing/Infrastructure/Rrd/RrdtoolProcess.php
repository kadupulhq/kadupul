<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Graphing\Infrastructure\Rrd;

use Kadupul\Graphing\Application\Port\StorageRejected;
use Kadupul\Telemetry\Contract\RejectionReason;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Exception\ProcessStartFailedException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Runs one rrdtool command as an argument vector, not through a shell, with
 * a deadline. The binary is configured explicitly rather than read
 * from legacy settings, so the write path does not depend on the legacy
 * bootstrap and cannot be redirected by a stored setting.
 */
final class RrdtoolProcess
{
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
        // When the spawn itself fails, Symfony retries it through /bin/sh with
        // every argument single-quoted. Checking the binary first keeps a
        // removed or unexecutable rrdtool a start failure rather than a
        // shell's exit 126; only removal between here and the spawn reaches
        // that quoted fallback.
        if (!is_file($this->binary) || !is_executable($this->binary)) {
            throw $this->notStarted();
        }
        // Keys set to false are removed, so rrdtool's output format and time
        // zone do not depend on the caller's environment.
        $environment = array_map(static fn(): bool => false, getenv() + $_ENV);
        $process = new Process(
            [$this->binary, ...$arguments],
            null,
            ['PATH' => '/usr/bin:/bin', 'LC_ALL' => 'C', 'TZ' => 'UTC'] + $environment,
            null,
            $this->timeoutSeconds,
        );
        $process->disableOutput();
        $captured = [Process::OUT => '', Process::ERR => ''];
        $capture = static function (string $type, string $chunk) use (&$captured): void {
            $captured[$type] .= substr($chunk, 0, max(0, self::MAX_CAPTURE - strlen($captured[$type])));
        };
        try {
            $exitCode = $process->run($capture);
        } catch (ProcessTimedOutException) {
            throw $this->timedOut();
        } catch (ProcessStartFailedException) {
            throw $this->notStarted();
        } catch (ProcessSignaledException $e) {
            $exitCode = 128 + $e->getSignal();
        }

        return new RrdtoolResult($exitCode, $captured[Process::OUT], $captured[Process::ERR]);
    }

    private function notStarted(): StorageRejected
    {
        return new StorageRejected(RejectionReason::StorageFailure, 'rrdtool could not be started.');
    }

    private function timedOut(): StorageRejected
    {
        return new StorageRejected(RejectionReason::StorageFailure, sprintf('rrdtool did not finish within %s seconds and was stopped.', rtrim(rtrim(sprintf('%.3f', $this->timeoutSeconds), '0'), '.')));
    }
}
