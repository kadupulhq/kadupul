<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/*
 * #1067: api_plugin_db_table_create() brings an existing table the plugin
 * created up to its current definition, never removing columns, and leaves
 * alone a table the plugin did not create.
 */

require_once dirname(__DIR__, 2) . '/Helpers/PhpSource.php';

function plugin_table_refresh_run(bool $exists, int $owned): array
{
    $source = file_get_contents(dirname(__DIR__, 3) . '/lib/plugins.php');
    expect($source)->not->toBeFalse();
    $program = 'namespace PluginTableRefreshProbe;$calls=[];$config=["library_path"=>' . var_export(sys_get_temp_dir(), true) . '];'
        . 'if (!is_file($config["library_path"] . "/database.php")) {}'
        . 'function db_is_safe_identifier($t){return preg_match("/^[a-z_]+$/",$t)===1;}'
        . 'function db_table_exists($t,$l=true){$GLOBALS["calls"][]=["exists",$t];return ' . var_export($exists, true) . ';}'
        . 'function db_table_create($t,$d){$GLOBALS["calls"][]=["create",$t];return true;}'
        . 'function db_execute_prepared($s,$p=[]){$GLOBALS["calls"][]=["record",$p];return true;}'
        . 'function db_fetch_cell_prepared($s,$p=[]){$GLOBALS["calls"][]=["owned",$p];return "' . $owned . '";}'
        . 'function db_update_table($t,$d,$remove=false,$log=true){$GLOBALS["calls"][]=["update",$t,$remove];return true;}'
        . str_replace("include_once(\$config['library_path'] . '/database.php');", '', test_php_function_source($source, 'api_plugin_db_table_create'))
        . 'api_plugin_db_table_create("thold","plugin_thold_log",["columns"=>[]]);echo json_encode($calls);';
    $result = test_php_run($program);
    expect($result['status'])->toBe(0, $result['err'] . $result['out']);

    return json_decode($result['out'], true, 512, JSON_THROW_ON_ERROR);
}

test('a missing table is created and recorded as the plugin\'s', function () {
    expect(plugin_table_refresh_run(false, 0))->toBe([
        ['exists', 'plugin_thold_log'], ['create', 'plugin_thold_log'], ['record', ['thold', 'plugin_thold_log']],
    ]);
});

test('an existing table the plugin created is updated without removing columns', function () {
    expect(plugin_table_refresh_run(true, 1))->toBe([
        ['exists', 'plugin_thold_log'], ['owned', ['thold', 'plugin_thold_log']], ['update', 'plugin_thold_log', false],
    ]);
});

test('an existing table the plugin did not create is left alone', function () {
    expect(plugin_table_refresh_run(true, 0))->toBe([
        ['exists', 'plugin_thold_log'], ['owned', ['thold', 'plugin_thold_log']],
    ]);
});
