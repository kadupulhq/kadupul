<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Legacy;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Starts one bin/ worker: the only way the command-line tools reach lib/ code
 * that needs include/global.php. The worker reads its command as JSON on
 * stdin and ends its stdout with one "<marker>={json}" line. What it printed
 * before that line is the original script's output and is returned as is.
 */
final readonly class LegacyWorkerProcess
{
    /**
     * @param string $php the interpreter. PHP_BINARY is right because these
     *     workers start only from bin/console commands and cli/ shims, which
     *     run under the CLI binary; under a web SAPI it would name the server.
     */
    public function __construct(private string $projectDir, private string $php = PHP_BINARY) {}

    /**
     * @param array<string, mixed> $command
     * @param ?float $timeout seconds, or null for none
     * @return array{output: string, errors: string, ok: bool} ok only when the worker exited 0 and its marker said "ok"
     * @throws \InvalidArgumentException when $script is not a bin/legacy-*.php worker name
     * @throws ProcessTimedOutException when the worker outlives $timeout; the worker is stopped first
     */
    public function run(string $script, string $marker, array $command, ?float $timeout): array
    {
        // Only a plain worker name, so no caller can reach another bin/ file
        // or climb out of bin/ with a path.
        if (preg_match('/^legacy-[a-z-]+\.php$/D', $script) !== 1) {
            throw new \InvalidArgumentException('Not a legacy worker: ' . $script);
        }
        $process = new Process([$this->php, $this->projectDir . '/bin/' . $script], $this->projectDir, null, json_encode($command, JSON_THROW_ON_ERROR), $timeout);
        $process->run();
        $output = $process->getOutput();
        $ok = false;
        $end = strrpos("\n" . $output, "\n" . $marker . '=');
        if ($end !== false) {
            $decoded = json_decode(trim(substr($output, $end + strlen($marker) + 1)), true);
            $ok = $process->isSuccessful() && is_array($decoded) && ($decoded['status'] ?? null) === 'ok';
            $output = substr($output, 0, $end);
        }

        return ['output' => $output, 'errors' => $process->getErrorOutput(), 'ok' => $ok];
    }
}
