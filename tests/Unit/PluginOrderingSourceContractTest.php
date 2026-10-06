<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

/*
 * Source-contract assertions for two historical plugin ordering bugs:
 *
 * BUG 1 (moveup/movedown NULL->0 corruption):
 *   api_plugin_moveup() and api_plugin_movedown() swap plugin order by
 *   renaming rows via a three-step id rotation (current->temp, prior->current,
 *   temp->prior). When called at the boundary (first plugin moved up, last
 *   plugin moved down), the subquery MAX/MIN returns NULL. Kadupul strips
 *   STRICT_TRANS_TABLES from the session SQL mode on every connection; under
 *   non-strict mode, UPDATE SET id = NULL on a NOT NULL column silently stores
 *   0, leaving the plugin with a corrupted primary key.
 *
 * BUG 2 (plugins_load_temp_table 1062 on id=0 row):
 *   plugin_config.id is AUTO_INCREMENT. Kadupul also strips NO_AUTO_VALUE_ON_ZERO
 *   on connect. When an id=0 row exists in plugin_config (caused by bug 1 or a
 *   plugin upgrade script), the bulk INSERT INTO temp SELECT * FROM plugin_config
 *   reassigns the 0 to the next AUTO_INCREMENT sequence value, colliding with
 *   whatever row already holds that id and producing ERROR 1062.
 */

require_once dirname(__DIR__) . '/Helpers/PhpSource.php';

$libPluginsPath = __DIR__ . '/../../lib/plugins.php';
$pluginsPath    = __DIR__ . '/../../plugins.php';

function plugin_ordering_source(string $path): string
{
    $source = file_get_contents($path);
    if ($source === false) {
        throw new RuntimeException('Unable to read plugin ordering production source: ' . $path);
    }
    return $source;
}

// ---------------------------------------------------------------------------
// api_plugin_moveup
// ---------------------------------------------------------------------------

test('api_plugin_moveup has $prior_id !== null guard around the three-step swap', function () use ($libPluginsPath) {
    $source = test_php_function_source(plugin_ordering_source($libPluginsPath), 'api_plugin_moveup');

    $fn_pos    = strpos($source, 'function api_plugin_moveup(');
    $guard_pos = strpos($source, 'if ($prior_id !== null)', $fn_pos);

    expect($fn_pos)->not->toBeFalse('api_plugin_moveup not found');
    expect($guard_pos)->not->toBeFalse('$prior_id !== null guard missing from api_plugin_moveup');
});

test('api_plugin_moveup swap executes only after the prior_id guard, not before', function () use ($libPluginsPath) {
    $source = test_php_function_source(plugin_ordering_source($libPluginsPath), 'api_plugin_moveup');

    $fn_pos    = strpos($source, 'function api_plugin_moveup(');
    $guard_pos = strpos($source, 'if ($prior_id !== null)', $fn_pos);
    $swap_pos  = strpos($source, 'UPDATE plugin_config SET id = ? WHERE id = ?', $fn_pos);

    expect($guard_pos)->not->toBeFalse();
    expect($swap_pos)->not->toBeFalse();
    // The first UPDATE must come after the null guard.
    expect($swap_pos)->toBeGreaterThan($guard_pos);
});

test('api_plugin_moveup temp_id computation is inside the prior_id guard', function () use ($libPluginsPath) {
    $source = test_php_function_source(plugin_ordering_source($libPluginsPath), 'api_plugin_moveup');

    $fn_pos      = strpos($source, 'function api_plugin_moveup(');
    $guard_pos   = strpos($source, 'if ($prior_id !== null)', $fn_pos);
    $temp_id_pos = strpos($source, '$temp_id = db_fetch_cell(\'SELECT MAX(id) FROM plugin_config\')', $fn_pos);

    expect($guard_pos)->not->toBeFalse();
    expect($temp_id_pos)->not->toBeFalse('$temp_id not found in moveup');
    // temp_id must be computed after the guard, not before the NULL check.
    expect($temp_id_pos)->toBeGreaterThan($guard_pos);
});

test('api_plugin_moveup does not assign $prior_id to any id column before the null guard', function () use ($libPluginsPath) {
    $source = test_php_function_source(plugin_ordering_source($libPluginsPath), 'api_plugin_moveup');

    $fn_pos    = strpos($source, 'function api_plugin_moveup(');
    $guard_pos = strpos($source, 'if ($prior_id !== null)', $fn_pos);

    // Extract the slice between function start and the guard; it must contain
    // no UPDATE statement, because any UPDATE before the guard would set id
    // to a potentially NULL $prior_id value.
    $pre_guard = substr($source, $fn_pos, $guard_pos - $fn_pos);
    expect($pre_guard)->not->toContain('UPDATE plugin_config SET id');
});

// ---------------------------------------------------------------------------
// api_plugin_movedown
// ---------------------------------------------------------------------------

test('api_plugin_movedown has outer $id !== false guard', function () use ($libPluginsPath) {
    $source = test_php_function_source(plugin_ordering_source($libPluginsPath), 'api_plugin_movedown');

    $fn_pos   = strpos($source, 'function api_plugin_movedown(');
    $id_guard = strpos($source, 'if ($id !== false)', $fn_pos);

    expect($fn_pos)->not->toBeFalse();
    expect($id_guard)->not->toBeFalse('$id !== false guard missing from api_plugin_movedown');
});

test('api_plugin_movedown has $next_id !== null guard around the three-step swap', function () use ($libPluginsPath) {
    $source = test_php_function_source(plugin_ordering_source($libPluginsPath), 'api_plugin_movedown');

    $fn_pos    = strpos($source, 'function api_plugin_movedown(');
    $guard_pos = strpos($source, 'if ($next_id !== null)', $fn_pos);

    expect($fn_pos)->not->toBeFalse();
    expect($guard_pos)->not->toBeFalse('$next_id !== null guard missing from api_plugin_movedown');
});

test('api_plugin_movedown swap executes only after the next_id guard, not before', function () use ($libPluginsPath) {
    $source = test_php_function_source(plugin_ordering_source($libPluginsPath), 'api_plugin_movedown');

    $fn_pos    = strpos($source, 'function api_plugin_movedown(');
    $guard_pos = strpos($source, 'if ($next_id !== null)', $fn_pos);
    $swap_pos  = strpos($source, 'UPDATE plugin_config SET id = ? WHERE id = ?', $fn_pos);

    expect($guard_pos)->not->toBeFalse();
    expect($swap_pos)->not->toBeFalse();
    expect($swap_pos)->toBeGreaterThan($guard_pos);
});

test('api_plugin_movedown does not assign $next_id to any id column before the null guard', function () use ($libPluginsPath) {
    $source = test_php_function_source(plugin_ordering_source($libPluginsPath), 'api_plugin_movedown');

    $fn_pos    = strpos($source, 'function api_plugin_movedown(');
    $guard_pos = strpos($source, 'if ($next_id !== null)', $fn_pos);

    $pre_guard = substr($source, $fn_pos, $guard_pos - $fn_pos);
    expect($pre_guard)->not->toContain('UPDATE plugin_config SET id');
});

// ---------------------------------------------------------------------------
// plugins_load_temp_table sql_mode save/restore
// ---------------------------------------------------------------------------

// These ports record the actual extracted production function's DB call order.
// They do not model MySQL temporary-table storage or plugin discovery.
eval(<<<'PORTS'
namespace PluginOrderingCopyContract;
function plugins_temp_table_exists($table) { return false; }
function db_column_exists($table, $column) {
    if ($column !== 'requires') throw new \RuntimeException('Unexpected column lookup');
    return true;
}
function db_fetch_cell($sql) {
    if ($sql !== 'SELECT @@SESSION.sql_mode') throw new \RuntimeException('Unexpected cell query');
    $GLOBALS['plugin_ordering_copy']['calls'][] = ['read', $sql, []];
    return $GLOBALS['plugin_ordering_copy']['mode'];
}
function db_execute_prepared($sql, $params) {
    if ($sql !== 'SET SESSION sql_mode = ?' || count($params) !== 1) throw new \RuntimeException('Unexpected prepared write');
    $GLOBALS['plugin_ordering_copy']['calls'][] = ['set', $sql, $params];
    $GLOBALS['plugin_ordering_copy']['mode'] = $params[0];
    return true;
}
function db_execute($sql) {
    if (!preg_match('/^(?:CREATE TEMPORARY TABLE IF NOT EXISTS plugin_temp_table_[0-9]+ LIKE plugin_config|TRUNCATE plugin_temp_table_[0-9]+|INSERT INTO plugin_temp_table_[0-9]+ SELECT \* FROM plugin_config)$/D', $sql)) throw new \RuntimeException('Unexpected unprepared write');
    $GLOBALS['plugin_ordering_copy']['calls'][] = ['execute', $sql, []];
    if (str_starts_with($sql, 'INSERT INTO')) $GLOBALS['plugin_ordering_copy']['insert_mode'] = $GLOBALS['plugin_ordering_copy']['mode'];
    return true;
}
function db_fetch_assoc($sql) {
    if ($sql !== 'SELECT id, directory, status FROM plugin_config') throw new \RuntimeException('Unexpected list query');
    return [];
}
PORTS);
eval('namespace PluginOrderingCopyContract; ' . test_php_function_source(plugin_ordering_source($pluginsPath), 'plugins_load_temp_table'));

/** @return array{calls: list<array>, mode: string, insert_mode: string} */
function plugin_ordering_copy_calls(string $mode): array
{
    $directory = sys_get_temp_dir() . '/plugin-ordering-copy-' . bin2hex(random_bytes(8));
    if (!mkdir($directory, 0700)) {
        throw new RuntimeException('Unable to create owned plugin directory');
    }
    $names = ['config', 'plugins', 'plugins_integrated', 'local_db_cnn_id', 'plugin_ordering_copy'];
    $prior = [];
    foreach ($names as $name) {
        $prior[$name] = [array_key_exists($name, $GLOBALS), $GLOBALS[$name] ?? null];
    }
    $hadSession = array_key_exists('_SESSION', $GLOBALS);
    $session = $_SESSION ?? null;
    try {
        if (!mkdir($directory . '/plugins', 0700)) {
            throw new RuntimeException('Unable to create owned plugin subdirectory');
        }
        $GLOBALS['config'] = ['base_path' => $directory, 'poller_id' => 1];
        $GLOBALS['plugins_integrated'] = [];
        $GLOBALS['plugin_ordering_copy'] = ['calls' => [], 'mode' => $mode];
        $_SESSION = [];
        PluginOrderingCopyContract\plugins_load_temp_table();
        return $GLOBALS['plugin_ordering_copy'];
    } finally {
        foreach ($prior as $name => [$exists, $value]) {
            if ($exists) {
                $GLOBALS[$name] = $value;
            } else {
                unset($GLOBALS[$name]);
            }
        }
        if ($hadSession) {
            $_SESSION = $session;
        } else {
            unset($_SESSION);
        }
        if (is_dir($directory . '/plugins') && !rmdir($directory . '/plugins')) {
            throw new RuntimeException('Unable to remove owned plugin subdirectory');
        }
        if (!rmdir($directory)) {
            throw new RuntimeException('Unable to remove owned plugin directory');
        }
    }
}

function plugin_ordering_mode_samples(): array
{
    return ['', 'STRICT_ALL_TABLES,ANSI_QUOTES', ' ANSI_QUOTES, NO_AUTO_VALUE_ON_ZERO '];
}

test('plugins_load_temp_table saves @@SESSION.sql_mode before adding NO_AUTO_VALUE_ON_ZERO', function () {
    foreach (plugin_ordering_mode_samples() as $mode) {
        $calls = plugin_ordering_copy_calls($mode)['calls'];
        expect($calls[2])->toBe(['read', 'SELECT @@SESSION.sql_mode', []]);
        expect($calls[3][0])->toBe('set');
        expect($calls[3][2][0])->toContain('NO_AUTO_VALUE_ON_ZERO');
    }
});

test('plugins_load_temp_table inserts into temp table while NO_AUTO_VALUE_ON_ZERO is active', function () {
    foreach (plugin_ordering_mode_samples() as $mode) {
        $result = plugin_ordering_copy_calls($mode);
        expect($result['calls'][4][1])->toMatch('/^INSERT INTO plugin_temp_table_[0-9]+ SELECT \* FROM plugin_config$/D');
        expect(explode(',', $result['insert_mode']))->toContain('NO_AUTO_VALUE_ON_ZERO');
        expect(array_count_values(explode(',', $result['insert_mode']))['NO_AUTO_VALUE_ON_ZERO'])->toBe(1);
    }
});

test('plugins_load_temp_table restores original sql_mode after the bulk INSERT', function () {
    foreach (plugin_ordering_mode_samples() as $mode) {
        $calls = plugin_ordering_copy_calls($mode)['calls'];
        expect($calls)->toHaveCount(6);
        // Two identical SQL strings have distinct bindings and distinct order.
        expect($calls[3][1])->toBe('SET SESSION sql_mode = ?');
        expect($calls[4][0])->toBe('execute');
        expect($calls[5])->toBe(['set', 'SET SESSION sql_mode = ?', [$mode]]);
    }
});

test('plugins_load_temp_table restore uses db_execute_prepared with $orig_sql_mode', function () {
    foreach (plugin_ordering_mode_samples() as $mode) {
        $result = plugin_ordering_copy_calls($mode);
        expect($result['mode'])->toBe($mode);
        expect($result['calls'][5][2])->toBe([$mode]);
        // Retain whitespace and empty modes exactly; the enabling call normalizes them.
        if ($mode !== 'ANSI_QUOTES,NO_AUTO_VALUE_ON_ZERO') {
            expect($result['calls'][3][2])->not->toBe($result['calls'][5][2]);
        }
    }
});
