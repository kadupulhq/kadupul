<?php
/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Behavioral error capture.
 *
 * Observation only. Every handler chains to the one it replaced, so the
 * application's own error behavior is recorded rather than altered.
 *
 * include/global.php calls set_error_handler('CactiErrorHandler'), which
 * displaces a handler installed via auto_prepend_file. Callers must therefore
 * re-arm after bootstrapping; require_once cannot do that, because the prepend
 * has already marked this file included.
 */

const BEHAVIOR_ERROR_LOG = '/artifacts/php-errors.jsonl';

function behavior_error_record(array $event) {
    // No pid or timestamp: both vary per run and would read as a regression.
    file_put_contents(BEHAVIOR_ERROR_LOG, json_encode($event) . "\n", FILE_APPEND | LOCK_EX);
}

function behavior_install_error_handler() {
    // A user handler receives every diagnostic whatever error_reporting says,
    // so the level stays as configured. Raising it made PHP print deprecations
    // that php.ini-production hides, which recorded the recorder, not Cacti.
    if (!isset($GLOBALS['behavior_configured_error_level'])) {
        $GLOBALS['behavior_configured_error_level'] = error_reporting();
    }

    $previous = set_error_handler(function ($severity, $message, $file, $line) use (&$previous) {
        behavior_error_record([
            'severity'   => $severity,
            'message'    => $message,
            'file'       => $file,
            'line'       => $line,
            // A diagnostic suppressed by @ or by error_reporting still reaches
            // the handler. Judge suppression against the configuration the
            // application ships with; suppressed_here reflects any level Cacti
            // set after bootstrapping.
            'suppressed' => !($GLOBALS['behavior_configured_error_level'] & $severity),
            'suppressed_here' => !(error_reporting() & $severity),
            'context'    => array_map(static fn($f) => $f['function'] ?? '{main}',
                debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 8)),
        ]);

        if ($previous !== null) {
            return $previous($severity, $message, $file, $line);
        }

        return false;
    });

    return $previous;
}

behavior_install_error_handler();

register_shutdown_function(function () {
    $last = error_get_last();
    if ($last && in_array($last['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        behavior_error_record(['fatal' => $last]);
    }
});
