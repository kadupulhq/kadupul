<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Legacy;

use Kadupul\Graphing\Application\Port\PollerCacheOutcome;
use Kadupul\Graphing\Application\Port\PollerCacheRebuilder;
use Kadupul\Graphing\Application\Port\PollerCacheRequest;
use Kadupul\IdentityAccess\Contract\OperatorDatabase;
use Kadupul\Platform\Contract\LegacyWorkerRunner;

/**
 * Runs the body of cli/rebuild_poller_cache.php in bin/legacy-poller-cache-rebuild.php
 * and relays what it prints as it prints it. The worker announces its process
 * id first, so a signal the command receives reaches the script's own handler.
 *
 * Not readonly: it remembers the running worker for interrupt().
 */
final class LegacyPollerCacheRebuilder implements PollerCacheRebuilder
{
    public const string MARKER = 'KADUPUL_POLLER_CACHE_RESULT';
    public const string PID = 'KADUPUL_POLLER_CACHE_WORKER=';

    private bool $running = false;
    private ?int $pid = null;
    private ?int $pending = null;

    /** @param LegacyWorkerRunner $worker starts bin/legacy-poller-cache-rebuild.php */
    public function __construct(private readonly LegacyWorkerRunner $worker) {}

    /**
     * @param ?\Closure(string, string): void $progress
     * @throws \RuntimeException when the worker failed or reported an outcome it may not
     * @throws \JsonException when the request cannot be sent as JSON
     */
    #[\Override]
    public function rebuild(OperatorDatabase $database, PollerCacheRequest $request, ?\Closure $progress = null): PollerCacheOutcome
    {
        // Each stdout line is held until the next one arrives, because the
        // worker adds a newline before its result line when the script's
        // output did not end with one, and only the result says so.
        $held = null;
        $relay = function (string $type, string $chunk) use ($progress, &$held): void {
            if ($type !== 'out') {
                if ($progress !== null) {
                    $progress($type, $chunk);
                }

                return;
            }
            if ($this->pid === null && $held === null && preg_match('/^' . self::PID . '([1-9][0-9]*)\n$/D', $chunk, $match) === 1) {
                $this->pid = (int) $match[1];
                if ($this->pending !== null) {
                    $this->signal($this->pending);
                }

                return;
            }
            if ($held !== null && $progress !== null) {
                $progress('out', $held);
            }
            $held = $chunk;
        };
        $this->running = true;
        try {
            // No timeout: the original warned never to interrupt a rebuild, and the master waits for its children.
            $run = $this->worker->run('legacy-poller-cache-rebuild.php', self::MARKER, [
                'database' => $database === OperatorDatabase::Main ? 'main' : 'local',
                'host_id' => $request->hostId,
                'host_template_id' => $request->hostTemplateId,
                'data_template_id' => $request->dataTemplateId,
                'threads' => $request->threads,
                'type' => $request->type,
                'child' => $request->child,
                'force' => $request->force,
                'debug' => $request->debug,
                'ini' => self::errorSettings(),
            ], null, $relay);
        } finally {
            $this->running = false;
            $this->pid = null;
            $this->pending = null;
        }
        $result = $run['result'];
        if ($held !== null) {
            if (($result['newline'] ?? true) === false && str_ends_with($held, "\n")) {
                $held = substr($held, 0, -1);
            }
            if ($held !== '' && $progress !== null) {
                $progress('out', $held);
            }
        }

        return $this->outcome($run['ok'], $result);
    }

    /**
     * Sends the signal to the worker, or holds it until the worker has
     * announced its process id and installed the script's handler.
     *
     * @return bool false when no worker is running
     */
    #[\Override]
    public function interrupt(int $signal): bool
    {
        if (!$this->running) {
            return false;
        }
        if ($this->pid === null) {
            // The worker has not installed its handler yet; it gets the signal once it has.
            $this->pending = $signal;
        } else {
            $this->signal($signal);
        }

        return true;
    }

    /**
     * The settings that decided where the script's PHP warnings went, as this
     * process runs under them: a shim started with -d display_errors=0 hid
     * them, so the worker must too.
     *
     * @return array{display_errors: string, log_errors: string, error_reporting: string}
     */
    private static function errorSettings(): array
    {
        return [
            'display_errors' => (string) ini_get('display_errors'),
            'log_errors' => (string) ini_get('log_errors'),
            'error_reporting' => (string) ini_get('error_reporting'),
        ];
    }

    /** posix_kill() to the announced worker; without ext-posix the signal cannot be passed on. */
    private function signal(int $signal): void
    {
        if ($this->pid !== null && function_exists('posix_kill')) {
            posix_kill($this->pid, $signal);
        }
    }

    /**
     * @param array<mixed> $result the worker's result line
     * @throws \RuntimeException for a failed run or an outcome the worker may not report
     */
    private function outcome(bool $ok, array $result): PollerCacheOutcome
    {
        if (($result['status'] ?? null) === 'interrupted') {
            return new PollerCacheOutcome(PollerCacheOutcome::INTERRUPTED);
        }
        $status = $result['outcome'] ?? null;
        $hosts = $result['hosts'] ?? null;
        if (!$ok || ($result['status'] ?? null) !== 'ok' || !is_string($status) || $status === PollerCacheOutcome::INTERRUPTED || !in_array($status, PollerCacheOutcome::STATUSES, true) || !is_int($hosts) || $hosts < 0) {
            throw new \RuntimeException('The poller cache rebuild failed.');
        }

        return new PollerCacheOutcome($status, $hosts);
    }
}
