<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Rrd;

/** Own the lifetime of long-lived local RRDtool pipe processes. */
final class LocalRrdtool
{
    /** @var array<int, array{process: resource, write: resource, read: resource|null, acknowledged: bool}> */
    private array $processes = array();

    /** Start a persistent RRDtool process without invoking a shell.
     *
     * @param array<int, array<int, string>|array{0: string}> $descriptors Process descriptors.
     * @param array<int, resource> $streams Process streams populated during startup.
     * @param bool $acknowledged Whether this process has a response stream.
     *
     * @return resource|false The process input stream, or false on launch failure.
     */
    public function open(array $descriptors, array &$streams, bool $acknowledged = false)
    {
        $process = proc_open(array(read_config_option('path_rrdtool'), '-'), $descriptors, $streams);
        if (!is_resource($process)) {
            return false;
        }
        if (!isset($streams[0]) || !is_resource($streams[0])) {
            foreach ($streams as $stream) {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
            proc_close($process);

            return false;
        }

        $write = $streams[0];
        $this->processes[(int) $write] = array(
            'process' => $process,
            'write' => $write,
            'read' => $acknowledged && isset($streams[1]) && is_resource($streams[1]) ? $streams[1] : null,
            'acknowledged' => $acknowledged,
        );

        return $write;
    }

    /** Close one owned pipe and wait for its RRDtool process to exit.
     *
     * Acknowledged pipes are drained by their caller before close. If RRDtool
     * does not exit after EOF, terminate it before closing the response stream.
     *
     * @param resource $write RRDtool process input stream.
     *
     * @return bool Whether this adapter owned and closed the pipe.
     */
    public function close($write): bool
    {
        $key = (int) $write;
        if (!isset($this->processes[$key])) {
            return false;
        }

        $state = $this->processes[$key];
        unset($this->processes[$key]);
        if (is_resource($state['write'])) {
            fclose($state['write']);
        }

        if ($state['acknowledged']) {
            $deadline = hrtime(true) + 1000000000;
            do {
                $status = proc_get_status($state['process']);
                if (!$status['running']) {
                    break;
                }
                usleep(10000);
            } while (hrtime(true) < $deadline);
            if ($status['running']) {
                proc_terminate($state['process'], 9);
            }
            if (is_resource($state['read'])) {
                fclose($state['read']);
            }
        }

        proc_close($state['process']);

        return true;
    }

    /** Close every persistent child during process shutdown. */
    public function closeAll(): void
    {
        foreach ($this->processes as $state) {
            $this->close($state['write']);
        }
    }
}
