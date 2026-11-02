<?php

declare(strict_types=1);

/* SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Legacy;

use Kadupul\Platform\Contract\LegacyWorkerRunner;
use Symfony\Component\Process\Process;

/** Bounded, private transport for the one read-only operational worker. */
final class OperationalVerificationProcess implements LegacyWorkerRunner
{
    public function __construct(private readonly string $projectDir, private readonly string $php = PHP_BINARY) {}

    /** @param array<string, mixed> $command
     *  @return array{output: string, errors: string, ok: bool, result: array<mixed>}
     */
    public function run(string $script, string $marker, array $command, ?float $timeout, ?\Closure $progress = null): array
    {
        $unconfirmed = ['output' => '', 'errors' => '', 'ok' => false, 'result' => []];
        if ($script !== 'legacy-installer-verification.php' || $marker !== 'KADUPUL_OPERATIONAL_RESULT'
            || $timeout === null || $timeout <= 0.0 || $timeout > 20.0 || $progress !== null) {
            throw new \InvalidArgumentException('Invalid bounded verification transport.');
        }
        $input = json_encode($command, JSON_THROW_ON_ERROR);
        if (strlen($input) > 16384) {
            throw new \InvalidArgumentException('Verification request is too large.');
        }
        $process = new Process(
            [$this->php, '-d', 'zend.exception_ignore_args=1', $this->projectDir . '/bin/' . $script],
            $this->projectDir,
            null,
            $input,
            $timeout
        );
        $process->disableOutput();
        $stdout = '';
        $stderr = '';
        $overflow = false;
        try {
            $exit = $process->run(static function (string $type, string $chunk) use (&$stdout, &$stderr, &$overflow, $process): void {
                $buffer = $type === Process::OUT ? $stdout : $stderr;
                $limit = $type === Process::OUT ? 131072 : 8192;
                if (strlen($buffer) + strlen($chunk) > $limit) {
                    $overflow = true;
                    $process->stop(0.01);
                    return;
                }
                if ($type === Process::OUT) {
                    $stdout .= $chunk;
                } else {
                    $stderr .= $chunk;
                }
            });
            if ($overflow || $exit !== 0 || $stderr !== ''
                || preg_match('/\AKADUPUL_OPERATIONAL_RESULT=(\{.*\})\n?\z/Ds', $stdout, $match) !== 1) {
                return $unconfirmed;
            }
            $result = json_decode($match[1], true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($result) || ($result['status'] ?? null) !== 'ok') {
                return $unconfirmed;
            }
            return ['output' => '', 'errors' => '', 'ok' => true, 'result' => $result];
        } catch (\Throwable) {
            // Process failures can contain stdin, paths or private diagnostics.
            return $unconfirmed;
        }
    }
}
