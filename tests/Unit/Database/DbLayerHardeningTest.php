<?php

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests\ProductionFunctions;

require_once dirname(__DIR__, 2) . '/Helpers/PhpSource.php';

/** Capture database calls made by the production functions loaded below. */
function db_calls(): array
{
    static $calls = [];

    return $calls;
}

function reset_db_calls(): void
{
    $GLOBALS['production_function_db_calls'] = [];
}

function db_table_exists($table): bool
{
    return true;
}

function db_fetch_cell_prepared($sql, $params = [])
{
    $GLOBALS['production_function_db_calls'][] = [$sql, $params];

    if (str_contains($sql, 'settings_user')) {
        return (int) end($params) === 101 ? 17 : 0;
    }

    return count($GLOBALS['production_function_db_calls']) === 1 ? 17 : false;
}

function db_fetch_row_prepared($sql, $params = [])
{
    $GLOBALS['production_function_db_calls'][] = [$sql, $params];

    return ['graph_type_id' => 1, 'sequence' => 9, 'local_graph_id' => 42, 'graph_template_id' => 7];
}

function db_fetch_assoc_prepared($sql, $params = [])
{
    $GLOBALS['production_function_db_calls'][] = [$sql, $params];

    return [];
}

function db_execute_prepared($sql, $params = [])
{
    $GLOBALS['production_function_db_calls'][] = [$sql, $params];

    return true;
}

function cacti_log(...$args): void
{
    $GLOBALS['production_function_log_calls'][] = $args;
}

function cacti_debug_backtrace(...$args): string
{
    return '';
}

function isset_request_var($name): bool
{
    return array_key_exists($name, $GLOBALS['production_function_request'] ?? []);
}

function get_nfilter_request_var($name, $default = '')
{
    return $GLOBALS['production_function_request'][$name] ?? $default;
}

function set_request_var($name, $value): void
{
    $GLOBALS['production_function_request'][$name] = $value;
}

function cacti_sizeof($value): int
{
    return count($value);
}

/** Load a function's actual source from lib/functions.php into this test namespace. */
function load_production_function(string $name): void
{
    $source = file_get_contents(dirname(__DIR__, 3) . '/lib/functions.php');
    $functionSource = test_php_function_source($source, $name);
    eval('namespace Kadupul\\Tests\\ProductionFunctions; ' . $functionSource);
}

if (!defined('SQL_NO_CACHE')) {
    define('SQL_NO_CACHE', '');
}

if (!defined('POLLER_VERBOSITY_MEDIUM')) {
    define('POLLER_VERBOSITY_MEDIUM', 3);
}

foreach (['user_setting_exists', 'get_graph_group', 'build_where_from_array', 'get_item', 'set_config_option', 'get_associated_rras', 'set_user_setting', 'save_user_settings'] as $function) {
    load_production_function($function);
}

\test('production user-setting cache is isolated by user', function () {
    reset_db_calls();
    $setting = 'cache_scope_' . uniqid();
    expect(user_setting_exists($setting, 101))->toBeTrue();
    expect(user_setting_exists($setting, 102))->toBeFalse();
    expect(count($GLOBALS['production_function_db_calls']))->toBe(2);
});

\test('production graph grouping binds local graph ID instead of parent sequence', function () {
    reset_db_calls();
    $hadGraphItemTypes = array_key_exists('graph_item_types', $GLOBALS);
    $originalGraphItemTypes = $GLOBALS['graph_item_types'] ?? null;

    try {
        $GLOBALS['graph_item_types'] = [1 => 'LINE1'];
        get_graph_group(5);

        expect($GLOBALS['production_function_db_calls'][1][1])->toBe([9, 42]);
    } finally {
        if ($hadGraphItemTypes) {
            $GLOBALS['graph_item_types'] = $originalGraphItemTypes;
        } else {
            unset($GLOBALS['graph_item_types']);
        }
    }
});

\test('production structured filters fail closed and get_item carries only safe parameters', function () {
    reset_db_calls();
    expect(get_item('items', 'sequence', 4, ['bad-name' => 1], 'next'))->toBe(4);

    expect($GLOBALS['production_function_db_calls'][1][1])->toBe([17]);
    expect($GLOBALS['production_function_db_calls'][1][0])->toContain('AND 1=0');
});

\test('production structured filters retain empty and valid behavior', function () {
    $params = [];
    expect(build_where_from_array([], $params))->toBe('1=1');
    expect($params)->toBe([]);

    $params = [];
    expect(build_where_from_array(['host_id' => 7], $params))->toBe('`host_id` = ?');
    expect($params)->toBe([7]);

    $params = [];
    expect(build_where_from_array(['host_id' => 7, 'bad-name' => 8], $params))->toBe('`host_id` = ?');
    expect($params)->toBe([7]);
});

\test('production structured filters reject field names containing SQL syntax or whitespace', function () {
    foreach (['host_id;DROP', '`host_id`', 'host id', "host_id\n", "host_id\n\n"] as $field) {
        $params = [];
        expect(build_where_from_array([$field => 7], $params))->toBe('1=0');
        expect($params)->toBe([]);
    }
});

\test('production configuration writes initialize and preserve web and CLI cache maps', function () {
    reset_db_calls();
    $basePath = sys_get_temp_dir() . '/kadupul-helper-test-' . uniqid();
    mkdir($basePath . '/lib', 0777, true);
    file_put_contents($basePath . '/lib/poller.php', '<?php');
    $hadConfig = array_key_exists('config', $GLOBALS);
    $originalConfig = $GLOBALS['config'] ?? null;
    $hadSession = array_key_exists('_SESSION', $GLOBALS);
    $originalSession = $_SESSION ?? null;

    try {
        $GLOBALS['config'] = ['base_path' => $basePath, 'is_web' => false, 'config_options_array' => ['existing' => 'kept']];
        set_config_option('written', 'value');
        expect($GLOBALS['config']['config_options_array'])->toBe(['existing' => 'kept', 'written' => 'value']);

        $GLOBALS['config'] = ['base_path' => $basePath, 'is_web' => false, 'config_options_array' => 'invalid'];
        set_config_option('initialized', 'value');
        expect($GLOBALS['config']['config_options_array'])->toBe(['initialized' => 'value']);

        $GLOBALS['config'] = ['base_path' => $basePath, 'is_web' => true];
        unset($_SESSION['sess_config_array']);
        set_config_option('web_initialized', 'web value');
        expect($_SESSION['sess_config_array'])->toBe(['web_initialized' => 'web value']);

        $_SESSION['sess_config_array'] = 'invalid';
        set_config_option('web_reinitialized', 'web value');
        expect($_SESSION['sess_config_array'])->toBe(['web_reinitialized' => 'web value']);

        $_SESSION['sess_config_array'] = ['existing' => 'kept'];
        set_config_option('web_written', 'web value');
        expect($_SESSION['sess_config_array'])->toBe(['existing' => 'kept', 'web_written' => 'web value']);
        expect($GLOBALS['production_function_db_calls'])->toHaveCount(5);
    } finally {
        if ($hadConfig) {
            $GLOBALS['config'] = $originalConfig;
        } else {
            unset($GLOBALS['config']);
        }
        if ($hadSession) {
            $_SESSION = $originalSession;
        } else {
            unset($GLOBALS['_SESSION']);
        }

        @unlink($basePath . '/lib/poller.php');
        @rmdir($basePath . '/lib');
        @rmdir($basePath);
    }
});

\test('production RRA lookup binds the selected RRA id', function () {
    foreach (['7', 7, '1 OR 1=1'] as $rra) {
        reset_db_calls();
        get_associated_rras(42, '', $rra);

        [$sql, $params] = $GLOBALS['production_function_db_calls'][0];
        expect($params)->toBe([42, $rra]);
        expect($sql)->toContain('WHERE gti.local_graph_id = ?');
        expect($sql)->toContain('AND dspr.id = ?');
        expect($sql)->not->toContain('OR 1=1');
    }
});

\test('production RRA lookup keeps its default query and the fixed fragment', function () {
    reset_db_calls();
    get_associated_rras(42);
    [$sql, $params] = $GLOBALS['production_function_db_calls'][0];
    expect($params)->toBe([42]);
    expect($sql)->not->toContain('dspr.id = ?');

    reset_db_calls();
    get_associated_rras(42, ' AND dspr.id IS NOT NULL');
    [$sql, $params] = $GLOBALS['production_function_db_calls'][0];
    expect($params)->toBe([42]);
    expect($sql)->toContain('AND dspr.id IS NOT NULL');

    reset_db_calls();
    get_associated_rras(42, ' AND dspr.id IS NOT NULL', 5);
    [$sql, $params] = $GLOBALS['production_function_db_calls'][0];
    expect($params)->toBe([42, 5]);
    expect(strpos($sql, 'IS NOT NULL'))->toBeLessThan(strpos($sql, 'dspr.id = ?'));
});

/** Run a user-setting write with a known session and settings map, then restore both. */
function with_user_setting_state(callable $write): void
{
    $hadSession = array_key_exists('_SESSION', $GLOBALS);
    $originalSession = $_SESSION ?? null;
    $hadSettingsUser = array_key_exists('settings_user', $GLOBALS);
    $originalSettingsUser = $GLOBALS['settings_user'] ?? null;

    try {
        reset_db_calls();
        $GLOBALS['production_function_log_calls'] = [];
        $_SESSION = ['sess_user_id' => 5, 'sess_user_config_array' => ['cached' => 'kept']];
        $GLOBALS['settings_user'] = ['general' => ['pref' => ['method' => 'textbox']]];
        $GLOBALS['production_function_request'] = ['pref' => 'v'];
        $write();
    } finally {
        if ($hadSession) {
            $_SESSION = $originalSession;
        } else {
            unset($GLOBALS['_SESSION']);
        }
        if ($hadSettingsUser) {
            $GLOBALS['settings_user'] = $originalSettingsUser;
        } else {
            unset($GLOBALS['settings_user']);
        }
        unset($GLOBALS['production_function_request']);
    }
}

/** Swallow only the given pre-existing warning; delegate anything else to the previous handler. */
function ignoring_warning(string $expected, callable $call): void
{
    $previous = set_error_handler(function (...$args) use ($expected, &$previous) {
        if (str_contains($args[1], $expected)) {
            return true;
        }

        return $previous === null ? false : $previous(...$args);
    });

    try {
        $call();
    } finally {
        restore_error_handler();
    }
}

function invalid_user_ids(): array
{
    return [
        'abc', '1abc', '1.5', ' 1', '1 ', "1\n", '-5', '+1', '0x1A', '1e3',
        '9223372036854775808', '99999999999999999999',
        [1], new \stdClass(), 1.5, 7.0,
    ];
}

\test('production set_user_setting writes valid integer and digit-string user ids', function () {
    $cases = [[7, 7], ['7', '7'], ['007', '007'], [0, 0], ['0', '0'], [PHP_INT_MAX, PHP_INT_MAX], [(string) PHP_INT_MAX, (string) PHP_INT_MAX], [-1, 5], ['-1', 5]];

    foreach ($cases as [$user, $stored]) {
        with_user_setting_state(function () use ($user, $stored) {
            set_user_setting('pref', 'v', $user);

            expect($GLOBALS['production_function_db_calls'])->toHaveCount(1);
            [$sql, $params] = $GLOBALS['production_function_db_calls'][0];
            expect($sql)->toContain('INSERT INTO settings_user');
            expect($params)->toBe([$stored, 'pref', 'v']);
            expect($_SESSION)->not->toHaveKey('sess_user_config_array');
            expect($GLOBALS['settings_user']['pref']['value'])->toBe('v');
            expect($GLOBALS['production_function_log_calls'])->toBe([]);
        });
    }
});

\test('production set_user_setting keeps the no-session -1 notice', function () {
    with_user_setting_state(function () {
        unset($_SESSION['sess_user_id']);
        set_user_setting('pref', 'v', -1);

        expect($GLOBALS['production_function_db_calls'])->toBe([]);
        expect($GLOBALS['production_function_log_calls'])->toHaveCount(1);
        expect($GLOBALS['production_function_log_calls'][0][0])->toStartWith('NOTE: Attempt to set user setting');
    });
});

\test('production set_user_setting rejects a non-integer user id without writing', function () {
    foreach (array_merge(invalid_user_ids(), ['', null, [], false]) as $user) {
        with_user_setting_state(function () use ($user) {
            // The existing -1 sentinel comparison warns for an object before validation runs.
            ignoring_warning('could not be converted to int', fn () => set_user_setting('pref', 'v', $user));

            expect($GLOBALS['production_function_db_calls'])->toBe([]);
            expect($_SESSION['sess_user_config_array'])->toBe(['cached' => 'kept']);
            expect($GLOBALS['settings_user'])->not->toHaveKey('pref');
            expect($GLOBALS['production_function_log_calls'])->toHaveCount(1);
            [$message, , $level] = $GLOBALS['production_function_log_calls'][0];
            expect($message)->toStartWith('ERROR: Invalid user id in set_user_setting: ' . gettype($user));
            expect($message)->not->toContain('abc');
            expect($level)->toBe('SECURITY');
        });
    }
});

\test('production save_user_settings writes for valid and fallback user ids', function () {
    $cases = [[7, 7], ['7', '7'], [-1, 5], ['', 5], [null, 5], [0, 5], ['0', 5], [false, 5]];

    foreach ($cases as [$user, $stored]) {
        with_user_setting_state(function () use ($user, $stored) {
            save_user_settings($user);

            expect($GLOBALS['production_function_db_calls'])->toHaveCount(1);
            expect($GLOBALS['production_function_db_calls'][0][1])->toBe([$stored, 'pref', 'v']);
            expect($GLOBALS['production_function_log_calls'])->toBe([]);
        });
    }
});

\test('production save_user_settings rejects a non-integer user id before any write', function () {
    foreach (invalid_user_ids() as $user) {
        with_user_setting_state(function () use ($user) {
            $GLOBALS['settings_user']['general']['other'] = ['method' => 'checkbox'];
            ignoring_warning('could not be converted to int', fn () => save_user_settings($user));

            expect($GLOBALS['production_function_db_calls'])->toBe([]);
            expect($_SESSION['sess_user_config_array'])->toBe(['cached' => 'kept']);
            expect($GLOBALS['production_function_log_calls'])->toHaveCount(1);
            [$message, , $level] = $GLOBALS['production_function_log_calls'][0];
            expect($message)->toStartWith('ERROR: Invalid user id in save_user_settings: ' . gettype($user));
            expect($level)->toBe('SECURITY');
        });
    }
});

\test('production save_user_settings rejects a missing session user', function () {
    with_user_setting_state(function () {
        unset($_SESSION['sess_user_id']);
        ignoring_warning('Undefined array key "sess_user_id"', fn () => save_user_settings());

        expect($GLOBALS['production_function_db_calls'])->toBe([]);
        expect($GLOBALS['production_function_log_calls'][0][2])->toBe('SECURITY');
    });
});
