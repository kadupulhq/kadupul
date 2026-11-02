<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Legacy;

/** Evidence already observed by status(), without network, device or poller activity. */
final class InstallerHealthAssessment
{
    /** @param array{name: string, status: string, observed: string, remedy: string}|null $activity
     *  @return list<array{name: string, status: string, observed: string, remedy: string}>
     */
    public static function assess(string $schema, string $target, string $state, int $pendingCollectors, ?array $activity = null): array
    {
        $complete = in_array($state, ['succeeded', 'partial'], true) && $schema === $target;
        $successful = $state === 'succeeded' && $schema === $target && $pendingCollectors === 0;
        return [
            ['name' => 'Database schema', 'status' => $schema === $target ? 'verified' : ($state === 'failed' ? 'action_required' : 'not_assessed'),
                'observed' => $schema === $target ? 'Stored schema matches the application target.' : 'Target schema completion has not been confirmed.',
                'remedy' => 'Review the installation outcome and resolve failures before considering the schema upgrade complete.'],
            ['name' => 'Installation outcome', 'status' => $successful ? 'verified' : (in_array($state, ['failed', 'partial', 'succeeded'], true) ? 'action_required' : 'not_assessed'),
                'observed' => $successful ? 'A successful receipt matches the current target schema.' : 'A complete successful installation outcome has not been confirmed.',
                'remedy' => 'Preserve the installer log and use the explicit recovery or retry procedure when required.'],
            ['name' => 'Collector synchronization', 'status' => $complete ? ($pendingCollectors === 0 && $state === 'succeeded' ? 'verified' : 'action_required') : 'not_assessed',
                'observed' => $complete ? ($pendingCollectors > 0 ? 'The completed installer reports pending collector synchronization.' : ($state === 'succeeded' ? 'The completed installer reports no pending collectors.' : 'Collector completion remains unconfirmed by this partial receipt.')) : 'Collector completion is not established by this installation outcome.',
                'remedy' => 'Review collector status and retry finishing tasks for partial outcomes. This does not verify later collection freshness.'],
            $activity ?? ['name' => 'Scheduled collection', 'status' => 'not_assessed', 'observed' => 'No poller or device was contacted by this assessment.',
                'remedy' => 'Confirm the configured scheduler runs the poller as the intended service account and review its next normal cycle.'],
            ['name' => 'Graph data', 'status' => 'not_assessed', 'observed' => 'Live samples and RRD updates were not exercised.',
                'remedy' => 'After a normal collection cycle, confirm expected graphs receive fresh samples and review poller errors.'],
            ['name' => 'Recovery readiness', 'status' => 'not_assessed', 'observed' => 'Backup restoration was not exercised.',
                'remedy' => 'Verify an isolated restore of database, RRD files, configuration and key material before relying on recovery.'],
            self::databasePrivileges(),
        ];
    }

    /** @return array{name: string, status: string, observed: string, remedy: string} */
    public static function databasePrivileges(): array
    {
        return ['name' => 'Database privileges', 'status' => 'not_assessed',
            'observed' => 'Effective migration privileges, including active roles and object-specific grants, were not verified.',
            'remedy' => 'Have the database administrator verify required table writes, schema changes, triggers and routines for the installation account and schema. Do not grant global administrator privileges.'];
    }

    /** @return array{name: string, status: string, observed: string, remedy: string} */
    public static function pollerActivity(?string $lastStarted, string $cronInterval, string $pollerInterval, int $now): array
    {
        $result = ['name' => 'Scheduled collection', 'status' => 'not_assessed',
            'observed' => 'No persisted local poller start has been recorded.',
            'remedy' => 'Confirm the configured scheduler runs the poller as the intended service account and review its next normal cycle.'];
        if ($lastStarted === null || $lastStarted === '' || $lastStarted === '0') {
            return $result;
        }
        $started = self::positiveInteger($lastStarted, PHP_INT_MAX);
        $cron = self::positiveInteger($cronInterval, 300);
        $interval = self::positiveInteger($pollerInterval, 300);
        if ($started === null || !in_array($cron, [60, 300], true) || !in_array($interval, [10, 15, 20, 30, 60, 300], true) || $cron < $interval) {
            $result['status'] = 'action_required';
            $result['observed'] = 'Persisted poller timing is invalid and cannot establish scheduler health.';
            return $result;
        }
        // Match poller.php's MAX_POLLER_RUNTIME and out-of-sync warning:
        // (start - last_run - 10) > (int(cron / poller_interval) * poller_interval - 2).
        $maximumRuntime = intdiv($cron, $interval) * $interval - 2;
        if ($started > $now) {
            $result['status'] = 'action_required';
            $result['observed'] = 'The recorded poller start is in the future. Check clock synchronization.';
        } elseif ($now - $started - 10 > $maximumRuntime) {
            $result['status'] = 'action_required';
            $result['observed'] = 'The recorded poller start is older than the native scheduling tolerance.';
        } else {
            $result['status'] = 'verified';
            $result['observed'] = 'A local poller start was recorded within the native scheduling tolerance. Completion and device health are not verified.';
        }
        return $result;
    }

    private static function positiveInteger(string $value, int $maximum): ?int
    {
        if (preg_match('/\A[1-9][0-9]*\z/D', $value) !== 1) {
            return null;
        }
        $integer = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => $maximum]]);
        return is_int($integer) ? $integer : null;
    }
}
