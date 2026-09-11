<?php
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
    error_reporting(E_ALL);

    $previous = set_error_handler(function ($severity, $message, $file, $line) use (&$previous) {
        behavior_error_record([
            'severity'   => $severity,
            'message'    => $message,
            'file'       => $file,
            'line'       => $line,
            // A diagnostic suppressed by @ or error_reporting still reaches the
            // handler; record that it was suppressed rather than dropping it.
            'suppressed' => !(error_reporting() & $severity),
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
