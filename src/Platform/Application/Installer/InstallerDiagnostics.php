<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Application\Installer;

use Kadupul\Platform\Domain\Installer\InstallationPhase;

/** Export allowlisted evidence, never configuration, logs or exception text. */
final class InstallerDiagnostics
{
    /** @param array<string, mixed> $plan
     *  @return array<string, mixed>
     */
    public static function export(array $plan): array
    {
        $job = is_array($plan['job'] ?? null) ? $plan['job'] : [];
        $progress = self::integer($job['progress'] ?? null, 100);
        $checks = [];
        $number = 0;
        foreach (is_array($plan['checks'] ?? null) ? $plan['checks'] : [] as $check) {
            ++$number;
            if (is_array($check) && is_bool($check['ok'] ?? null)) {
                // Position matches the on-screen requirement table. Native
                // names/observations can contain paths or deployment data.
                $checks[] = ['number' => $number, 'passed' => $check['ok']];
            }
        }
        return [
            'format_version' => 1,
            'runtime' => ['php' => PHP_VERSION],
            'operation' => self::choice($plan['mode'] ?? null, ['install', 'collector', 'upgrade', 'complete', 'finish']),
            'schema' => self::version($plan['schema'] ?? null),
            'target' => self::version($plan['target'] ?? null),
            'execution' => [
                'state' => self::choice($job['state'] ?? null, ['not_started', 'waiting', 'running', 'failed', 'partial', 'succeeded']),
                'progress' => $progress,
                'phase' => $progress === null ? null : InstallationPhase::label($progress),
                'attempt' => self::integer($job['attempt'] ?? null, PHP_INT_MAX),
                'pending_collectors' => self::integer($job['pending_collectors'] ?? null, PHP_INT_MAX),
                'error_code' => is_string($job['error'] ?? null) && $job['error'] !== '' ? 'installation_requires_attention' : null,
            ],
            'requirements' => $checks,
            'health' => self::statuses($job['health'] ?? null),
            'advisories' => self::statuses($plan['advisories'] ?? null),
        ];
    }

    /** @return list<array{number: int, status: ?string}> */
    private static function statuses(mixed $checks): array
    {
        $result = [];
        $number = 0;
        foreach (is_array($checks) ? $checks : [] as $check) {
            ++$number;
            if (is_array($check)) {
                $result[] = ['number' => $number, 'status' => self::choice($check['status'] ?? null, ['verified', 'action_required', 'not_assessed'])];
            }
        }
        return $result;
    }

    private static function integer(mixed $value, int $maximum): ?int
    {
        return is_int($value) && $value >= 0 && $value <= $maximum ? $value : null;
    }

    /** @param list<string> $choices */
    private static function choice(mixed $value, array $choices): ?string
    {
        return is_string($value) && in_array($value, $choices, true) ? $value : null;
    }

    private static function version(mixed $value): ?string
    {
        return is_string($value) && ($value === 'new_install'
            || preg_match('/\A[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}(?:\.[0-9]{1,3})?\z/D', $value) === 1) ? $value : null;
    }
}
