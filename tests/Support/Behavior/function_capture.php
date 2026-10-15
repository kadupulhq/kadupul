<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/*
 * Function-level capture. Calls the real function from the application under
 * test with the arguments from a case file and records what it did.
 *
 * Every case runs inside a SAVEPOINT that is rolled back afterwards, and the
 * $config overrides a case sets are restored, so one case cannot feed the next
 * and the database of a shared instance is left as it was found.
 *
 * Diagnostics are recorded by a handler that replaces CactiErrorHandler for
 * the duration of the call instead of chaining to it. The Cacti handler only
 * logs, and chaining would put its log lines and database writes into the
 * record of every function that raises a warning.
 */

const BEHAVIOR_SEVERITIES = [
    E_ERROR => 'E_ERROR', E_WARNING => 'E_WARNING', E_NOTICE => 'E_NOTICE',
    E_DEPRECATED => 'E_DEPRECATED', E_USER_ERROR => 'E_USER_ERROR',
    E_USER_WARNING => 'E_USER_WARNING', E_USER_NOTICE => 'E_USER_NOTICE',
    E_USER_DEPRECATED => 'E_USER_DEPRECATED', E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR',
];

const BEHAVIOR_EVENT_LIMIT = 100;
const BEHAVIOR_CASE_KEYS = ['id', 'args', 'ambient', 'prelude', 'between', 'isolated', 'timeout', 'note'];
const BEHAVIOR_AMBIENT_KEYS = ['settings', 'config', 'session'];

function behavior_relative($path)
{
    global $config;
    $base = rtrim($config['base_path'], '/') . '/';

    return str_starts_with($path, $base) ? substr($path, strlen($base)) : '<external>';
}

function behavior_scrub($text)
{
    global $config;

    // "passed in FILE on line N" and "called in FILE on line N" name the probe
    // that made the call, not the function under test, and differ per checkout
    // and per edit of the probe.
    $text = preg_replace('/ (passed|called) in .+? on line \d+/', ' $1 in <caller>', $text);

    return str_replace($config['base_path'], '<APP>', $text);
}

function behavior_log_path()
{
    global $config;

    return getenv('BEHAVIOR_LOG_PATH') ?: $config['base_path'] . '/log/cacti.log';
}

function behavior_log_size()
{
    clearstatcache(true, behavior_log_path());

    return is_file(behavior_log_path()) ? filesize(behavior_log_path()) : 0;
}

function behavior_log_since($offset)
{
    $lines = [];
    if (behavior_log_size() > $offset) {
        $new = substr((string) file_get_contents(behavior_log_path()), $offset);
        foreach (explode("\n", rtrim($new, "\n")) as $line) {
            // The stamp is wall clock; the message after it is the behavior.
            $lines[] = preg_replace('/^[0-9A-Za-z\/.-]+ \d{2}:\d{2}:\d{2} - /', '<TIMESTAMP> - ', behavior_scrub($line));
        }
    }

    return $lines;
}

function behavior_signature($name)
{
    $out = [];
    foreach ((new ReflectionFunction($name))->getParameters() as $param) {
        $row = ['name' => $param->getName(), 'by_ref' => $param->isPassedByReference(),
            'optional' => $param->isOptional(), 'variadic' => $param->isVariadic(),
            'type' => $param->hasType() ? (string) $param->getType() : null];
        if ($param->isDefaultValueAvailable()) {
            $row['default'] = behavior_encode($param->getDefaultValue());
        }
        $out[] = $row;
    }

    return $out;
}

/**
 * $forget drops the cached copy so the next read reaches the table. Setup wants
 * that; a write made between two calls must not, or the second call could never
 * show a stale cache.
 */
function behavior_setting_write($name, $value, $forget = true)
{
    if ($value === null) {
        db_execute_prepared('DELETE FROM settings WHERE name = ?', [$name]);
    } else {
        db_execute_prepared('INSERT INTO settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)', [$name, $value]);
    }
    // read_config_option() serves a cached value before it looks at the table.
    if ($forget) {
        unset($GLOBALS['config']['config_options_array'][$name]);
    }
}

class BehaviorTimeout extends RuntimeException {}

/**
 * $timeout is a whole number of seconds, or null. A function that never
 * returns would otherwise take the whole capture down with it; the alarm turns
 * that into a recorded outcome. It needs pcntl, and the harness image has it.
 */
function behavior_call($name, array $encoded, $timeout = null)
{
    $ref = new ReflectionFunction($name);
    $params = $ref->getParameters();
    $slots = [];
    $call = [];
    foreach ($encoded as $i => $raw) {
        $slots[$i] = behavior_decode($raw);
        if (isset($params[$i]) && $params[$i]->isPassedByReference()) {
            $call[$i] = &$slots[$i];
        } else {
            $call[$i] = $slots[$i];
        }
    }

    // One entry per distinct diagnostic, with how often it fired. A loop that
    // warns on every pass would otherwise grow without bound.
    $events = [];
    $seen = [];
    $dropped = false;
    set_error_handler(function ($severity, $message, $file) use (&$events, &$seen, &$dropped) {
        $event = ['severity' => BEHAVIOR_SEVERITIES[$severity] ?? (string) $severity, 'message' => behavior_scrub($message),
            'file' => behavior_relative($file), 'suppressed' => !(error_reporting() & $severity)];
        $key = implode("\0", $event);
        if (isset($seen[$key])) {
            $events[$seen[$key]]['count']++;
        } elseif (count($events) < BEHAVIOR_EVENT_LIMIT) {
            $seen[$key] = count($events);
            $events[] = $event + ['count' => 1];
        } else {
            $dropped = true;
        }

        return true;
    });
    $log_offset = behavior_log_size();
    if ($timeout) {
        pcntl_async_signals(true);
        pcntl_signal(SIGALRM, function () use ($timeout) {
            throw new BehaviorTimeout('no return within ' . $timeout . 's');
        });
        pcntl_alarm($timeout);
    }
    ob_start();
    $record = [];
    try {
        $returned = $name(...$call);
        $record['returned'] = ['type' => get_debug_type($returned), 'value' => behavior_encode($returned)];
    } catch (Throwable $e) {
        $record['exception'] = ['class' => get_class($e), 'code' => $e->getCode(), 'message' => behavior_scrub($e->getMessage())];
    } finally {
        if ($timeout) {
            pcntl_alarm(0);
        }
        $output = ob_get_clean();
        restore_error_handler();
    }

    $by_ref = [];
    foreach ($params as $i => $param) {
        if ($param->isPassedByReference() && array_key_exists($i, $slots)) {
            $by_ref[$param->getName()] = ['type' => get_debug_type($slots[$i]), 'value' => behavior_encode($slots[$i])];
        }
    }
    if ($by_ref) {
        $record['by_ref'] = $by_ref;
    }
    if (isset($record['exception']) && $record['exception']['class'] === 'BehaviorTimeout') {
        // How far a runaway call got depends on the machine, so its counts say nothing.
        foreach ($events as &$event) {
            $event['count'] = null;
        }
        unset($event);
    }
    $record['diagnostics'] = $events;
    if ($dropped) {
        $record['diagnostics_truncated'] = true;
    }
    $record['log'] = behavior_log_since($log_offset);
    $record['output'] = behavior_encode(behavior_scrub($output));

    return $record;
}

function behavior_step($step, $forget = true)
{
    if (isset($step['setting'])) {
        foreach ($step['setting'] as $name => $value) {
            behavior_setting_write($name, $value, $forget);
        }

        return ['setting' => $step['setting']];
    }

    // A case file is data, not code: an internal function such as shell_exec
    // must not be reachable through it.
    if (!isset($step['fn']) || !is_string($step['fn']) || !function_exists($step['fn']) || !(new ReflectionFunction($step['fn']))->isUserDefined()) {
        throw new RuntimeException('Step needs "setting" or a user-defined "fn"');
    }

    return ['fn' => $step['fn']] + behavior_call($step['fn'], $step['args'] ?? []);
}

function behavior_case($name, array $case)
{
    global $config;

    if (!isset($case['id']) || !is_string($case['id']) || !isset($case['args']) || !is_array($case['args']) || !array_is_list($case['args'])) {
        throw new RuntimeException('Every case needs a string id and a list of args');
    }

    $unknown = array_diff(array_keys($case), BEHAVIOR_CASE_KEYS);
    $unknown_ambient = array_diff(array_keys($case['ambient'] ?? []), BEHAVIOR_AMBIENT_KEYS);
    if ($unknown || $unknown_ambient) {
        throw new RuntimeException('Case ' . ($case['id'] ?? '?') . ' has unknown keys: ' . implode(',', array_merge($unknown, $unknown_ambient)));
    }

    $saved = $config;
    $saved_session = $_SESSION ?? null;
    // A failed SAVEPOINT would leave this case's writes in the ambient transaction.
    if (!db_execute('SAVEPOINT fnwt_case')) {
        throw new RuntimeException('SAVEPOINT fnwt_case failed; refusing to run the case');
    }
    try {
        foreach ($case['ambient']['settings'] ?? [] as $setting => $value) {
            behavior_setting_write($setting, $value);
        }
        foreach ($case['ambient']['config'] ?? [] as $key => $value) {
            $config[$key] = behavior_decode($value);
        }
        foreach ($case['ambient']['session'] ?? [] as $key => $value) {
            $_SESSION[$key] = behavior_decode($value);
        }

        $record = ['id' => $case['id'], 'note' => $case['note'] ?? '', 'args' => $case['args']];
        if (isset($case['ambient'])) {
            $record['ambient'] = $case['ambient'];
        }
        if (!empty($case['prelude'])) {
            $record['prelude'] = array_map('behavior_step', $case['prelude']);
        }

        $record['result'] = behavior_call($name, $case['args'], $case['timeout'] ?? null);
        if (!empty($case['between'])) {
            $record['between'] = array_map(fn($step) => behavior_step($step, false), $case['between']);
        }

        // Same arguments, same process, no reset in between: this is what
        // exposes a static or global cache. Arguments are decoded afresh so a
        // by-reference output from the first call cannot leak into the second.
        $warm = behavior_call($name, $case['args'], $case['timeout'] ?? null);
        $record['warm'] = behavior_json($warm) === behavior_json($record['result']) ? ['same' => true] : ['same' => false, 'result' => $warm];
    } finally {
        $rolled_back = db_execute('ROLLBACK TO SAVEPOINT fnwt_case');
        $config = $saved;
        if ($saved_session === null) {
            unset($_SESSION);
        } else {
            $_SESSION = $saved_session;
        }
        if (!$rolled_back) {
            throw new RuntimeException('ROLLBACK TO SAVEPOINT fnwt_case failed; the instance may hold rows from case ' . $case['id']);
        }
    }

    return $record;
}

/**
 * $select is a list of case ids, or null for the cases that share a process.
 * A case marked "isolated" gets a process of its own so a cache filled by an
 * earlier case cannot answer for it; the driver selects those by id.
 */
function behavior_function_run($name, $case_file, $select)
{
    if (!function_exists($name)) {
        throw new RuntimeException('Unknown function ' . $name);
    }

    // A user handler is called whatever the level, but error_reporting() is how
    // it tells an @-suppressed call apart. Pin the level so that tells the same
    // story on a php.ini-production image and on one with no php.ini at all.
    error_reporting(E_ALL);

    // A run against a long-lived instance must not append to its log. The
    // setting is read through the same cache read_config_option() fills.
    if (getenv('BEHAVIOR_LOG_PATH')) {
        $GLOBALS['config']['config_options_array']['path_cactilog'] = getenv('BEHAVIOR_LOG_PATH');
    }

    $spec = json_decode((string) file_get_contents($case_file), true, 512, JSON_THROW_ON_ERROR);
    if (($spec['function'] ?? null) !== $name || empty($spec['cases']) || !is_array($spec['cases'])) {
        throw new RuntimeException('Case file does not name ' . $name . ' or has no cases');
    }

    $records = [];
    if (!db_begin_transaction()) {
        throw new RuntimeException('Cannot begin the capture transaction');
    }
    try {
        foreach ($spec['cases'] as $case) {
            if ($select !== null ? !in_array($case['id'], $select, true) : !empty($case['isolated'])) {
                continue;
            }
            $records[] = behavior_case($name, $case);
        }
    } finally {
        db_rollback_transaction();
    }

    return ['cases' => $records, 'function' => $name, 'signature' => behavior_signature($name)];
}
