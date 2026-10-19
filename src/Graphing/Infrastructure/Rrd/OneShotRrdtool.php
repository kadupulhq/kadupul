<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Kadupul\Graphing\Infrastructure\Rrd;

/** Exchange a POSIX process's input and output under one monotonic deadline. */
final class OneShotRrdtool
{
    /**
     * Own and close the process and both pipes, including after an I/O failure.
     *
     * @param resource $process
     * @param array<int, resource> $pipes
     * @return array{completed: bool, status: int, output: string}
     */
    public function run($process, array $pipes, string $input, int|float $deadline, bool $captureOutput): array
    {
        $output = '';
        $offset = 0;
        $length = strlen($input);
        $finished = false;
        try {
            $ready = stream_set_blocking($pipes[0], false) && stream_set_blocking($pipes[1], false);
            while ($ready) {
                $now = hrtime(true);
                if ($now === false || $now >= $deadline) {
                    break;
                }
                $read = isset($pipes[1]) ? [$pipes[1]] : [];
                $write = isset($pipes[0]) ? [$pipes[0]] : [];
                if (!$read && !$write) {
                    $state = proc_get_status($process);
                    if ($state === false) {
                        break;
                    }
                    if (!$state['running']) {
                        $finished = true;
                        break;
                    }
                    usleep(10000);
                    continue;
                }
                $except = null;
                $remaining = max(0, min(100000000, $deadline - $now));
                if (@stream_select($read, $write, $except, 0, (int) ($remaining / 1000)) === false) {
                    break;
                }
                if ($read) {
                    $chunk = fread($pipes[1], 8192);
                    if ($chunk === false) {
                        break;
                    }
                    if ($captureOutput) {
                        $output .= $chunk;
                    }
                    if (feof($pipes[1])) {
                        fclose($pipes[1]);
                        unset($pipes[1]);
                    }
                }
                if ($write) {
                    $written = @fwrite($pipes[0], substr($input, $offset, 8192));
                    if ($written === false) {
                        break;
                    }
                    $offset += $written;
                    if ($offset === $length) {
                        fclose($pipes[0]);
                        unset($pipes[0]);
                    }
                }
            }
        } finally {
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
            $state = proc_get_status($process);
            if ($state === false || $state['running']) {
                // SIGKILL cannot be ignored; proc_close must not wait for a
                // stalled child that ignores SIGTERM after the deadline.
                proc_terminate($process, 9);
            }
            $status = proc_close($process);
        }

        return ['completed' => $finished && $offset === $length, 'status' => $status, 'output' => $output];
    }
}
