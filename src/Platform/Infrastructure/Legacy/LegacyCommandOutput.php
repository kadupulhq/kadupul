<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Legacy;

use Symfony\Component\Process\Exception\ProcessStartFailedException;
use Symfony\Component\Process\Process;

/**
 * Runs legacy commands through Symfony Process and exposes stdout as lines.
 *
 * Shell command strings remain available for existing callers. New commands
 * should use argument arrays so user-controlled values are never shell-parsed.
 */
final readonly class LegacyCommandOutput
{
    /**
     * @return list<string>
     */
    public function lines(string $commandLine): array
    {
        try {
            $process = Process::fromShellCommandline($commandLine);
            return $this->readLines($process);
        } catch (ProcessStartFailedException|\Symfony\Component\Process\Exception\LogicException) {
            // Keep the native command path available when process creation is disabled.
            $lines = [];
            $status = 0;
            exec($commandLine, $lines, $status);

            return array_values($lines);
        }
    }

    /**
     * Run a command from individual arguments, without invoking a shell.
     *
     * @param list<string> $arguments Executable path followed by its arguments.
     * @param int|null     $exitCode  Set to the exit code, or -1 when the command did not start.
     * @param-out int      $exitCode
     *
     * @return list<string> Standard output lines.
     */
    public function linesFromArguments(array $arguments, ?int &$exitCode = null): array
    {
        $exitCode = -1;

        try {
            return $this->readLines(new Process($arguments), $exitCode);
        } catch (ProcessStartFailedException|\Symfony\Component\Process\Exception\LogicException) {
            // An argument-array command must never fall back to a shell.
            return [];
        }
    }

    /**
     * Collect standard output while forwarding standard error to the parent.
     *
     * @param Process  $process  Process to run.
     * @param int|null $exitCode Set to the exit code; -1 if it is unknown.
     * @param-out int  $exitCode
     *
     * @return list<string>
     */
    private function readLines(Process $process, ?int &$exitCode = null): array
    {
        $process->setTimeout(null);
        // Web SAPIs do not define the CLI STDERR constant. Keep diagnostics
        // on the process error stream without mixing them into SNMP results.
        $stderr = fopen('php://stderr', 'wb');
        try {
            $process->run(static function (string $type, string $data) use ($stderr): void {
                if ($type !== Process::ERR || !is_resource($stderr)) {
                    return;
                }

                while ($data !== '') {
                    $written = fwrite($stderr, $data);
                    if ($written === false || $written === 0) {
                        break;
                    }
                    $data = substr($data, $written);
                }
            });
        } finally {
            if (is_resource($stderr)) {
                fclose($stderr);
            }
        }
        $exitCode = $process->getExitCode() ?? -1;
        $output = $process->getOutput();

        if ($output === '') {
            return [];
        }

        $lines = explode("\n", $output);
        if (str_ends_with($output, "\n")) {
            array_pop($lines);
        }

        return array_map(static fn(string $line): string => rtrim($line, " \t\n\r\v\f"), $lines);
    }
}
