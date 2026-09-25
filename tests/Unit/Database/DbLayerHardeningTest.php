<?php

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Kadupul\Tests\ProductionFunctions;

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

function cacti_log(...$args): void {}

function cacti_sizeof($value): int
{
    return count($value);
}

/** Load a function's actual source from lib/functions.php into this test namespace. */
function load_production_function(string $name): void
{
    $source = file_get_contents(dirname(__DIR__, 3) . '/lib/functions.php');
    $tokens = token_get_all($source);
    $length = count($tokens);

    for ($i = 0; $i < $length; $i++) {
        if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) {
            continue;
        }

        $functionName = '';
        for ($j = $i + 1; $j < $length; $j++) {
            if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                $functionName = $tokens[$j][1];
                break;
            }
        }

        if ($functionName !== $name) {
            continue;
        }

        $braceDepth = 0;
        $started = false;
        $functionSource = '';
        for ($j = $i; $j < $length; $j++) {
            $token = $tokens[$j];
            $part = is_array($token) ? $token[1] : $token;
            $functionSource .= $part;
            if ($part === '{') {
                $braceDepth++;
                $started = true;
            } elseif ($part === '}' && $started && --$braceDepth === 0) {
                eval('namespace Kadupul\\Tests\\ProductionFunctions; ' . $functionSource);
                return;
            }
        }
    }

    throw new \RuntimeException("Could not load production function $name");
}

foreach (['user_setting_exists', 'get_graph_group', 'build_where_from_array', 'get_item', 'set_config_option'] as $function) {
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

\test('production configuration writes initialize and preserve the cache map', function () {
    reset_db_calls();
    $basePath = sys_get_temp_dir() . '/kadupul-helper-test-' . uniqid();
    mkdir($basePath . '/lib', 0777, true);
    file_put_contents($basePath . '/lib/poller.php', '<?php');
    $GLOBALS['config'] = ['base_path' => $basePath, 'is_web' => false, 'config_options_array' => ['existing' => 'kept']];

    set_config_option('written', 'value');

    expect($GLOBALS['config']['config_options_array'])->toBe(['existing' => 'kept', 'written' => 'value']);
    expect($GLOBALS['production_function_db_calls'])->toHaveCount(1);
    unlink($basePath . '/lib/poller.php');
    rmdir($basePath . '/lib');
    rmdir($basePath);
});
