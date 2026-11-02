<?php

declare(strict_types=1);

/* SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Legacy;

use Symfony\Component\Process\Process;

/** Only `rrdtool last` on existing contained local files; never flushes a daemon. */
final class LocalRrdVerification
{
    /** @return array{status: string, reason: string, last_update?: int} */
    public function read(string $binary, string $storedPath, string $root, float $timeout): array
    {
        if ($storedPath === '') {
            return ['status' => 'not_assessed', 'reason' => 'path_not_persisted'];
        }
        // get_data_source_path() generates and SAVES a path when empty. Resolve
        // only its nonempty-path rules so this assessment cannot repair it.
        $path = str_contains($storedPath, '/') ? $storedPath : '<path_rra>/' . $storedPath;
        $path = str_replace('<path_rra>/', rtrim($root, '/\\') . '/', $path);
        if ($root === '' || strpbrk($path, "<>\0\r\n") !== false || !function_exists('rrd_path_is_contained')
            || !\rrd_path_is_contained($path, $root, false)) {
            return ['status' => 'failed', 'reason' => 'local_path_rejected'];
        }
        $resolved = realpath($path);
        if ($resolved === false || !is_file($resolved)) {
            return ['status' => 'absent', 'reason' => 'local_rrd_missing'];
        }
        if (!\rrd_path_is_contained($resolved, $root, false)) {
            return ['status' => 'failed', 'reason' => 'local_path_rejected'];
        }
        if (!is_readable($resolved)) {
            return ['status' => 'failed', 'reason' => 'local_rrd_unreadable'];
        }
        $absolute = str_starts_with($binary, '/') || preg_match('/\A[A-Za-z]:[\\\\\/]/D', $binary) === 1;
        if (!$absolute || str_contains($binary, "\0") || !is_file($binary) || !is_executable($binary)) {
            return ['status' => 'not_assessed', 'reason' => 'local_rrdtool_unavailable'];
        }
        $executable = realpath($binary);
        if ($executable === false || $timeout <= 0.0) {
            return ['status' => 'not_assessed', 'reason' => 'local_read_budget_exhausted'];
        }
        $environment = array_fill_keys(array_keys(getenv() + $_ENV), false);
        $process = new Process(
            [$executable, 'last', $resolved],
            null,
            ['PATH' => '/usr/bin:/bin', 'LC_ALL' => 'C', 'TZ' => 'UTC', 'RRDCACHED_ADDRESS' => false] + $environment,
            null,
            min(1.0, $timeout)
        );
        $process->disableOutput();
        $stdout = '';
        $bytes = 0;
        $truncated = false;
        $stderr = false;
        try {
            $exit = $process->run(static function (string $type, string $chunk) use (&$stdout, &$bytes, &$truncated, &$stderr): void {
                $bytes += strlen($chunk);
                if ($type === Process::OUT) {
                    $truncated = $truncated || strlen($stdout) + strlen($chunk) > 128;
                    $stdout .= substr($chunk, 0, max(0, 128 - strlen($stdout)));
                } else {
                    $stderr = true;
                }
            });
        } catch (\Throwable) {
            return ['status' => 'failed', 'reason' => 'local_rrdtool_failed_or_timed_out'];
        }
        if ($exit !== 0 || $bytes > 4096 || $truncated || $stderr || preg_match('/\A[0-9]{1,19}\s*\z/D', $stdout) !== 1) {
            return ['status' => 'failed', 'reason' => 'local_rrdtool_result_unconfirmed'];
        }
        $last = filter_var(trim($stdout), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        return is_int($last) ? ['status' => 'observed', 'reason' => 'local_rrd_timestamp_read', 'last_update' => $last]
            : ['status' => 'failed', 'reason' => 'local_rrdtool_result_unconfirmed'];
    }
}
