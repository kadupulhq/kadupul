<?php

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests\ProductionFunctions;

require_once dirname(__DIR__, 2) . '/Helpers/PhpSource.php';
require_once dirname(__DIR__, 3) . '/src/Platform/Infrastructure/Legacy/LegacySettingsStorage.php';

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

    if (str_contains($sql, 'settings_user')) {
        return $GLOBALS['production_function_user_row'] ?? [];
    }

    return ['graph_type_id' => 1, 'sequence' => 9, 'local_graph_id' => 42, 'graph_template_id' => 7];
}

function db_fetch_cell($sql)
{
    $GLOBALS['production_function_db_calls'][] = [$sql, []];

    return $GLOBALS['production_function_guest_id'] ?? '';
}

function read_config_option($name, $force = false)
{
    return $GLOBALS['production_function_config'][$name] ?? '';
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

/**
 * What LegacySettingsStorage does with no legacy session: the same helper
 * call, here the stubs above. The user id rule is the real one.
 */
final class LegacySettingsStorage
{
    public static function acceptsUserId(mixed $id): bool
    {
        return \Kadupul\Platform\Infrastructure\Legacy\LegacySettingsStorage::acceptsUserId($id);
    }

    public static function instance(): object
    {
        return new class {
            public function userSettingExists(mixed $name, mixed $userId): bool
            {
                $count = db_fetch_cell_prepared('SELECT COUNT(*) FROM settings_user WHERE name = ? AND user_id = ?', [$name, $userId]);

                return $count !== false && $count > 0;
            }

            public function writeSetting(mixed $name, mixed $value): bool
            {
                return db_execute_prepared('INSERT INTO settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)', [$name, $value]);
            }

            public function writeUserSetting(mixed $userId, mixed $name, mixed $value): bool
            {
                return db_execute_prepared('INSERT INTO settings_user (user_id, name, value) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)', [$userId, $name, $value]);
            }

            public function removeUserSetting(mixed $name, mixed $userId): bool
            {
                return db_execute_prepared('DELETE FROM settings_user WHERE name = ? AND user_id = ?', [$name, $userId]);
            }

            public function userSettingRow(mixed $name, mixed $userId): array|false
            {
                return db_fetch_row_prepared('SELECT value FROM settings_user WHERE name = ? AND user_id = ?', [$name, $userId]);
            }
        };
    }
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

function settings_value_passes_filter($name, $value, $user = false): bool
{
    return true;
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

if (!defined('POLLER_VERBOSITY_MEDIUM')) {
    define('POLLER_VERBOSITY_MEDIUM', 3);
}

foreach (['user_setting_exists', 'get_graph_group', 'build_where_from_array', 'get_item', 'set_config_option', 'user_setting_value_allowed', 'set_user_setting', 'save_user_settings', 'clear_user_setting', 'read_default_user_setting', 'read_user_setting'] as $function) {
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
    $GLOBALS['graph_item_types'] = [1 => 'LINE1'];
    get_graph_group(5);

    expect($GLOBALS['production_function_db_calls'][1][1])->toBe([9, 42]);
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

/** Largest id settings_user.user_id can hold on this branch. */
const SETTINGS_USER_ID_MAX = 16777215;

function invalid_user_ids(): array
{
    return [
        'abc', '1abc', '1.5', ' 1', '1 ', "1\n", '-5', '+1', '0x1A', '1e3',
        '9223372036854775808', '99999999999999999999', str_repeat('9', 40),
        -5, -2, SETTINGS_USER_ID_MAX + 1, (string) (SETTINGS_USER_ID_MAX + 1), PHP_INT_MAX, (string) PHP_INT_MAX,
        [1], new \stdClass(), 1.5, 7.0,
    ];
}

\test('production set_user_setting writes valid integer and digit-string user ids', function () {
    $cases = [[7, 7], ['7', '7'], ['007', '007'], [0, 0], ['0', '0'], [SETTINGS_USER_ID_MAX, SETTINGS_USER_ID_MAX], [(string) SETTINGS_USER_ID_MAX, (string) SETTINGS_USER_ID_MAX], [str_repeat('0', 30) . '7', str_repeat('0', 30) . '7'], [-1, 5], ['-1', 5]];

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

\test('production set_user_setting rejects a non-integer or out-of-range user id without writing', function () {
    foreach (array_merge(invalid_user_ids(), ['', null, [], false]) as $user) {
        with_user_setting_state(function () use ($user) {
            // The existing -1 sentinel comparison warns for an object before validation runs.
            ignoring_warning('could not be converted to int', fn() => set_user_setting('pref', 'v', $user));

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
    $cases = [[7, 7], ['7', '7'], [SETTINGS_USER_ID_MAX, SETTINGS_USER_ID_MAX], [(string) SETTINGS_USER_ID_MAX, (string) SETTINGS_USER_ID_MAX], [-1, 5], ['', 5], [null, 5], [0, 5], ['0', 5], [false, 5]];

    foreach ($cases as [$user, $stored]) {
        with_user_setting_state(function () use ($user, $stored) {
            save_user_settings($user);

            expect($GLOBALS['production_function_db_calls'])->toHaveCount(1);
            expect($GLOBALS['production_function_db_calls'][0][1])->toBe([$stored, 'pref', 'v']);
            expect($GLOBALS['production_function_log_calls'])->toBe([]);
        });
    }
});

\test('production save_user_settings rejects a non-integer or out-of-range user id before any write', function () {
    foreach (invalid_user_ids() as $user) {
        with_user_setting_state(function () use ($user) {
            $GLOBALS['settings_user']['general']['other'] = ['method' => 'checkbox'];
            ignoring_warning('could not be converted to int', fn() => save_user_settings($user));

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
        ignoring_warning('Undefined array key "sess_user_id"', fn() => save_user_settings());

        expect($GLOBALS['production_function_db_calls'])->toBe([]);
        expect($GLOBALS['production_function_log_calls'][0][2])->toBe('SECURITY');
    });
});

\test('production clear_user_setting deletes for valid and fallback user ids', function () {
    $cases = [[7, 7], ['7', '7'], ['007', '007'], [0, 0], ['0', '0'], [SETTINGS_USER_ID_MAX, SETTINGS_USER_ID_MAX], [(string) SETTINGS_USER_ID_MAX, (string) SETTINGS_USER_ID_MAX], [-1, 5], ['-1', 5]];

    foreach ($cases as [$user, $stored]) {
        with_user_setting_state(function () use ($user, $stored) {
            clear_user_setting('pref', $user);

            expect($GLOBALS['production_function_db_calls'])->toHaveCount(1);
            [$sql, $params] = $GLOBALS['production_function_db_calls'][0];
            expect($sql)->toContain('DELETE FROM settings_user');
            expect($params)->toBe(['pref', $stored]);
            expect($_SESSION)->not->toHaveKey('sess_user_config_array');
            expect($GLOBALS['production_function_log_calls'])->toBe([]);
        });
    }
});

\test('production clear_user_setting rejects a non-integer or out-of-range user id without deleting', function () {
    foreach (array_merge(invalid_user_ids(), ['', null, [], false]) as $user) {
        with_user_setting_state(function () use ($user) {
            ignoring_warning('could not be converted to int', fn() => clear_user_setting('pref', $user));

            expect($GLOBALS['production_function_db_calls'])->toBe([]);
            expect($_SESSION['sess_user_config_array'])->toBe(['cached' => 'kept']);
            expect($GLOBALS['production_function_log_calls'])->toHaveCount(1);
            [$message, , $level] = $GLOBALS['production_function_log_calls'][0];
            expect($message)->toStartWith('ERROR: Invalid user id in clear_user_setting: ' . gettype($user));
            expect($message)->not->toContain('abc');
            expect($level)->toBe('SECURITY');
        });
    }
});

\test('production clear_user_setting rejects a missing session user', function () {
    with_user_setting_state(function () {
        unset($_SESSION['sess_user_id']);
        ignoring_warning('Undefined array key "sess_user_id"', fn() => clear_user_setting('pref'));

        expect($GLOBALS['production_function_db_calls'])->toBe([]);
        expect($GLOBALS['production_function_log_calls'][0][0])->toBe('ERROR: Invalid user id in clear_user_setting: NULL');
    });
});

\test('production clear_user_setting leaves auth_credential_generation alone before checking the user id', function () {
    foreach ([7, 'abc'] as $user) {
        with_user_setting_state(function () use ($user) {
            clear_user_setting('auth_credential_generation', $user);

            expect($GLOBALS['production_function_db_calls'])->toBe([]);
            expect($_SESSION['sess_user_config_array'])->toBe(['cached' => 'kept']);
            expect($GLOBALS['production_function_log_calls'])->toBe([]);
        });
    }
});

\test('production user_setting_exists rejects a non-integer or out-of-range user id without a query', function () {
    foreach (array_merge(invalid_user_ids(), ['', null, [], false]) as $user) {
        with_user_setting_state(function () use ($user) {
            expect(user_setting_exists('pref', $user))->toBeFalse();

            expect($GLOBALS['production_function_db_calls'])->toBe([]);
            expect($GLOBALS['production_function_log_calls'])->toHaveCount(1);
            [$message, , $level] = $GLOBALS['production_function_log_calls'][0];
            expect($message)->toStartWith('ERROR: Invalid user id in user_setting_exists: ' . gettype($user));
            expect($message)->not->toContain('abc');
            expect($level)->toBe('SECURITY');
        });
    }
});

\test('production user_setting_exists queries and caches valid user ids', function () {
    foreach ([[101, true], ['0101', true], [0, false], ['0', false], [SETTINGS_USER_ID_MAX, false]] as [$user, $exists]) {
        $setting = 'valid_scope_' . uniqid();
        with_user_setting_state(function () use ($setting, $user, $exists) {
            expect(user_setting_exists($setting, $user))->toBe($exists);
            expect(user_setting_exists($setting, $user))->toBe($exists);

            expect($GLOBALS['production_function_db_calls'])->toHaveCount(1);
            expect($GLOBALS['production_function_db_calls'][0][1])->toBe([$setting, $user]);
            expect($GLOBALS['production_function_log_calls'])->toBe([]);
        });
    }
});

/** Run read_user_setting against a known session, configuration and stored row. */
function with_user_setting_read(array $config, ?string $guestId, callable $read): void
{
    with_user_setting_state(function () use ($config, $guestId, $read) {
        $GLOBALS['settings_user'] = ['general' => ['pref' => ['method' => 'textbox', 'default' => 'factory']]];
        $GLOBALS['production_function_config'] = $config;
        $GLOBALS['production_function_guest_id'] = $guestId;
        $GLOBALS['production_function_user_row'] = ['value' => 'stored'];

        try {
            $read();
        } finally {
            unset($GLOBALS['production_function_config'], $GLOBALS['production_function_guest_id'], $GLOBALS['production_function_user_row']);
        }
    });
}

\test('production read_user_setting reads valid explicit, session and guest user ids', function () {
    foreach ([7, '7', '007', SETTINGS_USER_ID_MAX, (string) SETTINGS_USER_ID_MAX] as $user) {
        with_user_setting_read(['auth_method' => 1], null, function () use ($user) {
            expect(read_user_setting('pref', 'dflt', false, $user))->toBe('stored');
            expect($GLOBALS['production_function_db_calls'][0][1])->toBe(['pref', $user]);
            expect($_SESSION['sess_user_config_array'])->toBe(['cached' => 'kept']);
        });
    }

    with_user_setting_read(['auth_method' => 1], null, function () {
        expect(read_user_setting('pref', 'dflt'))->toBe('stored');
        expect($GLOBALS['production_function_db_calls'][0][1])->toBe(['pref', 5]);
        expect($_SESSION['sess_user_config_array'])->toBe(['cached' => 'kept', 'pref' => 'stored']);
    });

    foreach ([[null, 0], ['', 0], ['9', '9']] as [$guestId, $effective]) {
        with_user_setting_read(['auth_method' => 0], $guestId, function () use ($effective) {
            unset($_SESSION['sess_user_id']);
            expect(read_user_setting('pref', 'dflt'))->toBe('stored');
            expect($GLOBALS['production_function_db_calls'])->toHaveCount(2);
            expect($GLOBALS['production_function_db_calls'][1][1])->toBe(['pref', $effective]);
        });
    }

    with_user_setting_read(['auth_method' => 1], null, function () {
        unset($_SESSION['sess_user_id']);
        expect(read_user_setting('pref', 'dflt'))->toBe('stored');
        expect($GLOBALS['production_function_db_calls'][0][1])->toBe(['pref', 0]);
    });

    expect($GLOBALS['production_function_log_calls'])->toBe([]);
});

\test('production read_user_setting returns the default for an invalid explicit user id without a query', function () {
    foreach (invalid_user_ids() as $user) {
        foreach ([['dflt', 'dflt'], [false, 'factory']] as [$default, $expected]) {
            with_user_setting_read(['auth_method' => 0], null, function () use ($user, $default, $expected) {
                $value = null;
                ignoring_warning('could not be converted to int', function () use (&$value, $user, $default) {
                    $value = read_user_setting('pref', $default, false, $user);
                });

                expect($value)->toBe($expected);
                expect($GLOBALS['production_function_db_calls'])->toBe([]);
                expect($_SESSION['sess_user_config_array'])->toBe(['cached' => 'kept']);
                expect($GLOBALS['production_function_log_calls'])->toBe([]);
            });
        }
    }
});

\test('production read_user_setting returns the default for an invalid session or guest user id without caching it', function () {
    foreach (['abc', '1abc', -5, (string) (SETTINGS_USER_ID_MAX + 1), 7.0] as $sessionUser) {
        foreach ([false, true] as $force) {
            with_user_setting_read(['auth_method' => 1], null, function () use ($sessionUser, $force) {
                $_SESSION['sess_user_id'] = $sessionUser;

                expect(read_user_setting('pref', 'dflt', $force))->toBe('dflt');
                expect(read_user_setting('pref', false, $force))->toBe('factory');
                expect($GLOBALS['production_function_db_calls'])->toBe([]);
                expect($_SESSION['sess_user_config_array'])->toBe(['cached' => 'kept']);
                expect($GLOBALS['production_function_log_calls'])->toBe([]);
            });
        }
    }

    with_user_setting_read(['auth_method' => 0], (string) (SETTINGS_USER_ID_MAX + 1), function () {
        unset($_SESSION['sess_user_id']);

        expect(read_user_setting('pref', 'dflt'))->toBe('dflt');
        expect($GLOBALS['production_function_db_calls'])->toHaveCount(1);
        expect($GLOBALS['production_function_db_calls'][0][0])->not->toContain('settings_user');
    });
});
