<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Legacy;

use Kadupul\Platform\Contract\LegacyWorkerRunner;

/** The CLI uses the same workflow in a globally bootstrapped native worker. */
final class LegacyInstallerClient
{
    public function __construct(private readonly LegacyWorkerRunner $worker) {}

    /** @param array<string, mixed> $request
     *  @return array{ok: bool, data: array<array-key, mixed>}
     */
    public function request(#[\SensitiveParameter] array $request, string $expectedTarget): array
    {
        $unconfirmed = ['ok' => false, 'data' => ['error' => 'Installer worker did not confirm an outcome. Check status before retrying.']];
        try {
            $result = $this->worker->run('legacy-installer.php', 'KADUPUL_INSTALLER_RESULT', $request, null);
        } catch (\Throwable) {
            // Process exceptions can carry the credential-bearing stdin. An
            // unknown outcome must be reconciled, never automatically retried.
            return $unconfirmed;
        }
        $data = $result['result']['data'] ?? null;
        $action = $request['action'] ?? null;
        if (!is_array($data) || $data === [] || array_is_list($data)
            || !is_string($action) || !$this->validReceipt($action, $data, $result['ok'], $expectedTarget)) {
            return $unconfirmed;
        }
        $ok = $result['ok'];
        if (in_array($action, ['start', 'status'], true) && in_array($data['state'] ?? '', ['failed', 'partial'], true)
            || $action === 'start' && ($data['state'] ?? '') !== 'succeeded') {
            $ok = false;
        }
        return ['ok' => $ok, 'data' => $data];
    }

    /** @param array<array-key, mixed> $data */
    private function validReceipt(string $action, array $data, bool $ok, string $expectedTarget): bool
    {
        if (!$ok && is_string($data['error'] ?? null)
            && !array_key_exists('state', $data) && !array_key_exists('mode', $data)) {
            return true;
        }
        $states = ['not_started', 'waiting', 'running', 'failed', 'partial', 'succeeded'];
        if (in_array($action, ['check', 'configure'], true)) {
            if (!in_array($data['mode'] ?? null, ['install', 'collector', 'upgrade', 'complete', 'finish'], true)
                || !is_string($data['schema'] ?? null) || $data['schema'] === ''
                || !is_string($data['target'] ?? null) || $data['target'] === ''
                || !is_bool($data['ready'] ?? null) || !is_bool($data['discovery'] ?? null)
                || ($data['discovery'] === true && !is_string($data['range'] ?? null))
                || !is_array($data['checks'] ?? null) || $data['checks'] === []
                || !is_array($data['job'] ?? null) || !in_array($data['job']['state'] ?? null, $states, true)) {
                return false;
            }
            foreach ($data['checks'] as $check) {
                if (!is_array($check) || !is_bool($check['ok'] ?? null)
                    || !is_string($check['name'] ?? null) || !is_string($check['observed'] ?? null)
                    || !is_string($check['remedy'] ?? null)) {
                    return false;
                }
            }
            return $data['target'] === $expectedTarget
                && match ($data['mode']) {
                    'complete', 'finish' => $data['schema'] === $expectedTarget,
                    'install', 'collector' => $data['schema'] === 'new_install',
                    default => true,
                };
        }
        if (($data['state'] ?? null) === 'succeeded'
            && (($data['progress'] ?? null) !== 100 || ($data['pending_collectors'] ?? null) !== 0
                || ($data['schema'] ?? null) !== $expectedTarget || ($data['error'] ?? null) !== null)) {
            return false;
        }
        return in_array($action, ['start', 'status', 'recover'], true)
            && in_array($data['state'] ?? null, $states, true)
            && is_string($data['schema'] ?? null) && $data['schema'] !== '' && is_string($data['phase'] ?? null)
            && is_int($data['progress'] ?? null) && $data['progress'] >= 0 && $data['progress'] <= 100
            && is_int($data['attempt'] ?? null) && $data['attempt'] >= 0
            && is_int($data['pending_collectors'] ?? null) && $data['pending_collectors'] >= 0
            && array_key_exists('error', $data) && ($data['error'] === null || is_string($data['error']));
    }
}
