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
const BEHAVIOR_SQL_LIMIT = 100;
const BEHAVIOR_STATE_AREAS = ['session', 'config', 'settings_user'];
const BEHAVIOR_WATCHED_TABLES = ['settings', 'settings_user'];
const BEHAVIOR_GENERATED_HASH = '<generated 32 hex>';
const BEHAVIOR_CASE_KEYS = ['id', 'args', 'ambient', 'prelude', 'between', 'isolated', 'timeout', 'note', 'record_sql', 'record_tables', 'record_state', 'mask_generated', 'unordered'];
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
 * Statement class installed on the application's PDO connections. It notes each
 * prepared statement that is executed while $GLOBALS['behavior_sql'] is an array,
 * which is the SQL the function under test caused without touching lib/database.php.
 */
class BehaviorStatement extends PDOStatement
{
    /**
     * Doctrine DBAL binds each value before an execute() without arguments,
     * where the db_* helpers pass them to execute(). Both are the same
     * statement, so both are written the same way.
     */
    private $bound = [];

    protected function __construct() {}

    #[\ReturnTypeWillChange]
    public function bindValue($param, $value, $type = PDO::PARAM_STR)
    {
        $this->bound[$param] = $value;

        return parent::bindValue($param, $value, $type);
    }

    #[\ReturnTypeWillChange]
    public function execute(?array $params = null)
    {
        $recorded = $params;
        if ($recorded === null && $this->bound !== []) {
            ksort($this->bound);
            $recorded = $this->bound;
        }
        $entry = ['sql' => preg_replace('/\s+/', ' ', trim($this->queryString)), 'params' => behavior_cell(array_values($recorded ?? []))];
        try {
            $ok = parent::execute($params);
        } catch (Throwable $e) {
            behavior_sql_note($entry + ['error' => get_class($e) . ': ' . behavior_scrub($e->getMessage())]);

            throw $e;
        }
        behavior_sql_note($ok ? $entry : $entry + ['error' => implode(' ', array_map('strval', $this->errorInfo()))]);

        return $ok;
    }
}

/**
 * One statement as one line: the text, then the bound values after a comment
 * marker, then a repeat count and any error. A golden stays one line per statement.
 */
function behavior_sql_line(array $entry)
{
    $line = $entry['sql'];
    if ($entry['params'] !== []) {
        $line .= ' -- ' . behavior_json($entry['params']);
    }
    if (($entry['count'] ?? 1) > 1) {
        $line .= ' x' . $entry['count'];
    }
    if (isset($entry['error'])) {
        $line .= ' !! ' . $entry['error'];
    }

    return $line;
}

function behavior_sql_note(array $entry)
{
    if (!is_array($GLOBALS['behavior_sql'] ?? null)) {
        return;
    }
    $last = count($GLOBALS['behavior_sql']) - 1;
    if ($last >= 0 && array_diff_key($GLOBALS['behavior_sql'][$last], ['count' => 1]) === $entry) {
        $GLOBALS['behavior_sql'][$last]['count'] = ($GLOBALS['behavior_sql'][$last]['count'] ?? 1) + 1;
    } else {
        $GLOBALS['behavior_sql'][] = $entry;
    }
}

/**
 * A stand-in application has no PDO connection, so there is nothing to hook and
 * the case records an empty statement list.
 */
function behavior_sql_install()
{
    foreach ($GLOBALS['database_sessions'] ?? [] as $connection) {
        if ($connection instanceof PDO) {
            $connection->setAttribute(PDO::ATTR_STATEMENT_CLASS, ['BehaviorStatement']);
        }
    }
}

/**
 * The request state a function can change without returning it: the session,
 * $config and the user settings definitions that set_user_setting() writes into.
 * Each entry is serialized so a nested change is noticed.
 */
function behavior_state_snapshot(array $areas)
{
    $out = [];
    $sources = ['session' => $_SESSION ?? [], 'config' => $GLOBALS['config'] ?? [], 'settings_user' => $GLOBALS['settings_user'] ?? []];
    foreach (array_intersect_key($sources, array_flip($areas)) as $area => $values) {
        $out[$area] = $values;
    }

    return $out;
}

function behavior_state_diff(array $before, array $after)
{
    $lines = [];
    foreach ($after as $area => $values) {
        foreach ($values as $key => $value) {
            $old = $before[$area][$key] ?? null;
            if (array_key_exists($key, $before[$area]) && serialize($old) === serialize($value)) {
                continue;
            }
            // One level down for arrays, so a cache that gains one entry is
            // reported as that entry and not as the whole cache again.
            if (is_array($old) && is_array($value)) {
                foreach ($value as $sub => $item) {
                    if (!array_key_exists($sub, $old) || serialize($old[$sub]) !== serialize($item)) {
                        $lines[] = 'set ' . $area . '.' . $key . '.' . $sub . ' = ' . behavior_json(behavior_cell($item));
                    }
                }
                foreach (array_keys(array_diff_key($old, $value)) as $sub) {
                    $lines[] = 'unset ' . $area . '.' . $key . '.' . $sub;
                }
            } else {
                $lines[] = 'set ' . $area . '.' . $key . ' = ' . behavior_json(behavior_cell($value));
            }
        }
        foreach (array_keys(array_diff_key($before[$area], $values)) as $key) {
            $lines[] = 'unset ' . $area . '.' . $key;
        }
    }

    return $lines;
}

/**
 * State and table values longer than a short line are spelled by digest, as long
 * return values are, so one oversized value does not fill the golden.
 */
const BEHAVIOR_EFFECT_STRING = 120;
const BEHAVIOR_EFFECT_KEYS = ['sql', 'sql_truncated', 'table_diff', 'state'];

function behavior_cell($value)
{
    return behavior_encode($value, BEHAVIOR_EFFECT_STRING);
}

function behavior_table_rows($table)
{
    $saved = $GLOBALS['behavior_sql'] ?? null;
    $GLOBALS['behavior_sql'] = null;
    $rows = [];
    foreach (db_fetch_assoc('SELECT * FROM `' . $table . '`') ?: [] as $row) {
        $rows[serialize($row)] = $row;
    }
    $GLOBALS['behavior_sql'] = $saved;
    ksort($rows, SORT_STRING);

    return $rows;
}

function behavior_table_snapshot()
{
    $out = [];
    foreach (BEHAVIOR_WATCHED_TABLES as $table) {
        $out[$table] = behavior_table_rows($table);
    }

    return $out;
}

function behavior_table_diff(array $before, array $after)
{
    $lines = [];
    foreach ($before as $table => $rows) {
        foreach (['-' => array_diff_key($rows, $after[$table]), '+' => array_diff_key($after[$table], $rows)] as $sign => $changed) {
            foreach ($changed as $row) {
                $lines[] = $sign . ' ' . $table . ' ' . behavior_json(array_map('behavior_cell', $row));
            }
        }
    }

    return $lines;
}

/**
 * $timeout is a whole number of seconds, or null. A function that never
 * returns would otherwise take the whole capture down with it; the alarm turns
 * that into a recorded outcome. It needs pcntl, and the harness image has it.
 */
function behavior_call($name, array $encoded, $timeout = null, array $options = [])
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
    $record_sql = !empty($options['record_sql']);
    // true watches the session and $config; a list names the areas to watch.
    $watch = $options['record_state'] ?? false;
    $state_areas = $watch === true ? ['session', 'config'] : ($watch === false ? [] : $watch);
    $before_state = $state_areas ? behavior_state_snapshot($state_areas) : null;
    $before_tables = !empty($options['record_tables']) ? behavior_table_snapshot() : null;
    if ($record_sql) {
        $GLOBALS['behavior_sql'] = [];
    }
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
        $value = behavior_encode($returned);
        // A result whose order the SQL leaves to the engine (ties on the ORDER BY key)
        // is compared as a set, so the golden does not depend on which tie comes first.
        if (!empty($options['unordered']) && is_array($value) && array_is_list($value)) {
            usort($value, fn($a, $b) => strcmp(behavior_json($a), behavior_json($b)));
        }
        $record['returned'] = ['type' => get_debug_type($returned), 'value' => $value];
    } catch (Throwable $e) {
        $record['exception'] = ['class' => get_class($e), 'code' => $e->getCode(), 'message' => behavior_scrub($e->getMessage())];
    } finally {
        if ($timeout) {
            pcntl_alarm(0);
        }
        $output = ob_get_clean();
        restore_error_handler();
        if ($record_sql) {
            $statements = $GLOBALS['behavior_sql'];
            $GLOBALS['behavior_sql'] = null;
        }
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
    if ($before_state !== null) {
        $state = behavior_state_diff($before_state, behavior_state_snapshot($state_areas));
        if ($state) {
            $record['state'] = $state;
        }
    }
    if ($record_sql) {
        $record['sql'] = array_map('behavior_sql_line', array_slice($statements, 0, BEHAVIOR_SQL_LIMIT));
        if (count($statements) > BEHAVIOR_SQL_LIMIT) {
            $record['sql_truncated'] = count($statements);
        }
    }
    if ($before_tables !== null) {
        $tables = behavior_table_diff($before_tables, behavior_table_snapshot());
        if ($tables) {
            $record['table_diff'] = $tables;
        }
    }

    return $record;
}

/**
 * A function that returns a freshly generated hash answers differently each
 * time. When both calls return a 32 digit hex string and the strings differ, the
 * value is random, not stored, and both are replaced by a fixed marker. A stored
 * hash is the same on both calls and is kept, so a case cannot hide a wrong row.
 */
function behavior_mask_generated(array &$first, array &$second)
{
    $a = $first['returned']['value'] ?? null;
    $b = $second['returned']['value'] ?? null;
    if (is_string($a) && is_string($b) && $a !== $b && preg_match('/^[0-9a-f]{32}$/', $a) && preg_match('/^[0-9a-f]{32}$/', $b)) {
        $first['returned']['value'] = BEHAVIOR_GENERATED_HASH;
        $second['returned']['value'] = BEHAVIOR_GENERATED_HASH;
    }
}

function behavior_step($step, $forget = true)
{
    if (isset($step['setting'])) {
        foreach ($step['setting'] as $name => $value) {
            behavior_setting_write($name, $value, $forget);
        }

        return ['setting' => $step['setting']];
    }

    // Request variables for functions that read them. set_request_var() is the
    // application's own setter, so the superglobals and the request cache agree.
    if (isset($step['request'])) {
        if (!is_array($step['request']) || !$step['request']) {
            throw new RuntimeException('Request step needs a non-empty object');
        }
        foreach ($step['request'] as $name => $value) {
            set_request_var((string) $name, behavior_decode($value));
        }

        return ['request' => $step['request']];
    }

    // Fixture rows for functions that read other tables. The step is part of the
    // case, rolled back with it, and fails loudly so a typo cannot leave a case
    // reading rows that were never written.
    if (isset($step['sql'])) {
        if (!is_string($step['sql']) || $step['sql'] === '' || db_execute_prepared($step['sql'], behavior_decode($step['params'] ?? [])) === false) {
            throw new RuntimeException('SQL step failed: ' . (is_string($step['sql']) ? $step['sql'] : '?'));
        }

        return behavior_sql_line(['sql' => $step['sql'], 'params' => $step['params'] ?? []]);
    }

    // A case file is data, not code: an internal function such as shell_exec
    // must not be reachable through it.
    if (!isset($step['fn']) || !is_string($step['fn']) || !function_exists($step['fn']) || !(new ReflectionFunction($step['fn']))->isUserDefined()) {
        throw new RuntimeException('Step needs "setting", "sql", "request" or a user-defined "fn"');
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

        $options = ['unordered' => !empty($case['unordered']), 'record_sql' => !empty($case['record_sql']), 'record_tables' => !empty($case['record_tables']), 'record_state' => $case['record_state'] ?? false];
        $record['result'] = behavior_call($name, $case['args'], $case['timeout'] ?? null, $options);
        if (!empty($case['between'])) {
            $record['between'] = array_map(fn($step) => behavior_step($step, false), $case['between']);
        }

        // Same arguments, same process, no reset in between: this is what
        // exposes a static or global cache. Arguments are decoded afresh so a
        // by-reference output from the first call cannot leak into the second.
        // Effects are recorded for the first call only; the repeat is compared on its answer.
        $warm = behavior_call($name, $case['args'], $case['timeout'] ?? null, ['unordered' => !empty($case['unordered'])]);
        if (!empty($case['mask_generated'])) {
            behavior_mask_generated($record['result'], $warm);
        }
        $record['warm'] = behavior_json($warm) === behavior_json(array_diff_key($record['result'], array_flip(BEHAVIOR_EFFECT_KEYS))) ? ['same' => true] : ['same' => false, 'result' => $warm];
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
    // Only a case that asks for SQL pays for it, and an instance that opened its
    // connection persistently, which cannot take a statement class, is left alone.
    if (array_filter($spec['cases'], fn($case) => !empty($case['record_sql']))) {
        behavior_sql_install();
    }
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
