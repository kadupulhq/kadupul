<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

/**
 * Legacy pages stream their output, so their final timing cannot travel in a
 * response header. The comparison tool joins this record to its own
 * client-side measurement by the request's X-Kadupul-Profile-Id.
 *
 * Declarations only, so the file is inert when requested on its own;
 * include/global.php calls this after loading the autoloader.
 */
function kadupul_register_request_profile(): void
{
    if (getenv('KADUPUL_PROFILE') !== '1') {
        return;
    }

    register_shutdown_function(static function (): void {
        $id = $_SERVER['HTTP_X_KADUPUL_PROFILE_ID'] ?? '';
        if (!is_string($id) || $id === '') {
            return;
        }

        $started = $_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true);
        $log = getenv('KADUPUL_PROFILE_LOG');
        (new \Kadupul\Platform\Infrastructure\Profiling\RequestProfileLog(
            is_string($log) && $log !== '' ? $log : dirname(__DIR__) . '/var/profile/requests.jsonl'
        ))->append(
            $id,
            (string) ($_SERVER['SCRIPT_NAME'] ?? ''),
            (microtime(true) - (float) $started) * 1000,
            function_exists('db_statement_count') ? db_statement_count() : null,
            memory_get_peak_usage(true)
        );
    });
}
