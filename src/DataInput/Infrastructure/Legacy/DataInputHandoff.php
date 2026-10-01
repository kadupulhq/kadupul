<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Kadupul\DataInput\Infrastructure\Legacy;

use Symfony\Component\Process\Process;

/** Portable leaf processes share the original worker's post-commit deadline. */
final readonly class DataInputHandoff
{
    private const float DRAIN_RESERVE = 0.25;

    public function __construct(private string $binary, private string $projectDir, private int $actorId, private float $deadline, private float $phaseLimit = 30)
    {
        if (!is_finite($deadline) || !is_finite($phaseLimit) || $phaseLimit <= 0 || $phaseLimit > 30) {
            throw new \InvalidArgumentException('Invalid handoff deadline.');
        }
    }

    public function whitelist(int $id): bool
    {
        $process = new Process([$this->binary, $this->projectDir . '/cli/input_whitelist.php', '--update', '--id=' . $id], $this->projectDir);
        return $this->run($process);
    }

    public function propagate(int $id): bool
    {
        $nonce = bin2hex(random_bytes(16));
        $process = new Process([$this->binary, $this->projectDir . '/bin/legacy-data-input-handoff.php'], $this->projectDir);
        $process->setInput(json_encode(['actor' => $this->actorId, 'id' => $id, 'nonce' => $nonce], JSON_THROW_ON_ERROR));
        if (!$this->run($process) || !preg_match('/^KADUPUL_DATA_INPUT_HANDOFF_RESULT=(\{[^\r\n]+\})$/m', $process->getOutput(), $match)) {
            return false;
        }
        try {
            $result = json_decode($match[1], true, 16, JSON_THROW_ON_ERROR);
            return is_array($result) && ($result['actor'] ?? null) === $this->actorId && ($result['id'] ?? null) === $id && ($result['nonce'] ?? null) === $nonce && ($result['phase'] ?? null) === 'propagate' && ($result['status'] ?? null) === 'ok';
        } catch (\JsonException) {
            return false;
        }
    }

    private function run(Process $process): bool
    {
        $remaining = $this->deadline - hrtime(true) / 1e9 - self::DRAIN_RESERVE;
        if ($remaining <= 0) {
            return false;
        }
        $phaseDeadline = hrtime(true) / 1e9 + min($this->phaseLimit, $remaining);
        // Symfony's built-in timeout uses wall time. Supervise against the shared
        // monotonic clock so a clock adjustment cannot extend a committed handoff.
        $process->setTimeout(null);
        try {
            $process->start();
            while ($process->isRunning()) {
                if (hrtime(true) / 1e9 >= $phaseDeadline) {
                    return false;
                }
                usleep(10000);
            }
            return $process->isSuccessful();
        } catch (\Throwable) {
            return false;
        } finally {
            if ($process->isRunning()) {
                // Leaf children spawn no descendants. Immediate termination and
                // reaping leave the reserved time for the original result frame.
                $process->stop(0);
            }
        }
    }
}
