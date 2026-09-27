<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Legacy;

use Symfony\Component\Process\Exception\ProcessStartFailedException;
use Symfony\Component\Process\Process;

/**
 * Runs a legacy shell command and exposes stdout as an array of lines.
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
            $process->setTimeout(null);
            $process->run(static function (string $type, string $data): void {
                if ($type === Process::ERR) {
                    fwrite(STDERR, $data);
                }
            });
            $output = $process->getOutput();
        } catch (ProcessStartFailedException|\Symfony\Component\Process\Exception\LogicException) {
            // Keep the native command path available when process creation is disabled.
            $lines = [];
            $status = 0;
            exec($commandLine, $lines, $status);

            return array_values($lines);
        }

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
