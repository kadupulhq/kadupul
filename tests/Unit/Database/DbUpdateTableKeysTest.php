<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2026 The Kadupul project and contributors                 |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 +-------------------------------------------------------------------------+
*/

// #1065, #1060: db_update_table() must reconcile unique_keys with the live
// indexes and accept a primary key or index columns given as a string. The
// production helpers run as written; only the server calls are replaced.

namespace DbUpdateTableKeys;

require_once dirname(__DIR__, 2) . '/Helpers/PhpSource.php';

$database = file_get_contents(dirname(__DIR__, 3) . '/lib/database.php');
$functions = file_get_contents(dirname(__DIR__, 3) . '/lib/functions.php');
if ($database === false || $functions === false) {
    throw new \RuntimeException('Cannot read the database library.');
}
foreach (array('db_update_table', 'db_format_index_create', 'db_is_safe_identifier', 'db_is_safe_table_option', 'db_is_safe_index_column', 'db_column_type_cache_reset', 'db_index_columns_to_array') as $function) {
    // Loaded when present so a run against the old library reaches the assertions.
    if (str_contains($database, 'function ' . $function . '(')) {
        eval('namespace ' . __NAMESPACE__ . ';' . \test_php_function_source($database, $function));
    }
}
eval('namespace ' . __NAMESPACE__ . ';' . \test_php_function_source($functions, 'cacti_has_control_chars'));

function db_table_exists($table, $log = true, $db_conn = false) {
    return true;
}

function db_column_exists($table, $column, $log = true, $db_conn = false) {
    return true;
}

function db_fetch_row($sql, $log = true, $db_conn = false) {
    if (str_starts_with($sql, 'SHOW columns FROM')) {
        return array('Field' => 'id', 'Type' => 'int(11)', 'Null' => 'NO', 'Default' => null, 'Extra' => '');
    }

    return array('ENGINE' => 'InnoDB', 'TABLE_COMMENT' => '');
}

function db_fetch_assoc($sql, $log = true, $db_conn = false) {
    return str_starts_with($sql, 'SHOW INDEX FROM') ? $GLOBALS['db_update_table_keys']['indexes'] : array();
}

function db_execute($sql, $log = true, $db_conn = false) {
    $GLOBALS['db_update_table_keys']['executed'][] = $sql;

    return true;
}

function db_qstr($value, $db_conn = false) {
    return "'" . addslashes((string) $value) . "'";
}

function db_get_global_variable($variable, $db_conn = false) {
    return 'barracuda';
}

function cacti_sizeof($value) {
    return is_countable($value) ? count($value) : 0;
}

test('db_update_table reconciles unique keys and accepts string key columns', function (array $indexes, array $definition, array $expected) {
    $GLOBALS['db_update_table_keys'] = array(
        'executed' => array(),
        'indexes'  => array_map(static fn(array $index): array => array('Key_name' => $index[0], 'Seq_in_index' => $index[1], 'Column_name' => $index[2], 'Non_unique' => $index[3]), $indexes),
    );

    $result = db_update_table('plugin_fixture', array('type' => 'InnoDB', 'columns' => array(array('name' => 'id', 'type' => 'int(11)', 'NULL' => false))) + $definition, false, true, new \stdClass());

    expect($result)->toBeTrue()
        ->and($GLOBALS['db_update_table_keys']['executed'])->toBe($expected === array() ? array() : array('ALTER TABLE `plugin_fixture` ' . implode(', ', $expected)));
})->with(array(
    // #1065: a unique key declared next to regular keys was dropped as undeclared.
    'declared unique key is kept' => array(
        array(array('PRIMARY', 1, 'id', 0), array('idx', 1, 'id', 1), array('u_name', 1, 'name', 0)),
        array('primary' => array('id'), 'keys' => array(array('name' => 'idx', 'columns' => array('id'))), 'unique_keys' => array(array('name' => 'u_name', 'columns' => array('name')))),
        array(),
    ),
    'missing unique key is added as unique' => array(
        array(array('PRIMARY', 1, 'id', 0)),
        array('primary' => array('id'), 'unique_keys' => array(array('name' => 'u_name', 'columns' => array('name', 'id')))),
        array('ADD UNIQUE INDEX `u_name` (`name`,`id`)'),
    ),
    'unique key that lost its uniqueness is rebuilt' => array(
        array(array('PRIMARY', 1, 'id', 0), array('u_name', 1, 'name', 1)),
        array('primary' => array('id'), 'unique_keys' => array(array('name' => 'u_name', 'columns' => array('name')))),
        array('DROP INDEX `u_name`', 'ADD UNIQUE INDEX `u_name` (`name`)'),
    ),
    'undeclared index is still removed when keys are declared' => array(
        array(array('PRIMARY', 1, 'id', 0), array('idx', 1, 'id', 1), array('u_name', 1, 'name', 0), array('stale', 1, 'name', 1)),
        array('primary' => array('id'), 'keys' => array(array('name' => 'idx', 'columns' => array('id'))), 'unique_keys' => array(array('name' => 'u_name', 'columns' => array('name')))),
        array('DROP INDEX `stale`'),
    ),
    'undeclared index is kept when only unique keys are declared' => array(
        array(array('PRIMARY', 1, 'id', 0), array('u_name', 1, 'name', 0), array('other', 1, 'name', 1)),
        array('primary' => array('id'), 'unique_keys' => array(array('name' => 'u_name', 'columns' => array('name')))),
        array(),
    ),
    'compound key given as one string matches' => array(
        array(array('PRIMARY', 1, 'id', 0), array('idx', 1, 'id', 1), array('idx', 2, 'name', 1)),
        array('primary' => array('id'), 'keys' => array(array('name' => 'idx', 'columns' => 'id`,`name'))),
        array(),
    ),
    // #1060: a primary key given as a string raised a TypeError in array_diff().
    'string primary key matches' => array(
        array(array('PRIMARY', 1, 'id', 0)),
        array('primary' => 'id'),
        array(),
    ),
    'changed string primary key is rebuilt' => array(
        array(array('PRIMARY', 1, 'id', 0)),
        array('primary' => 'id`,`name'),
        array('DROP PRIMARY KEY', 'ADD PRIMARY KEY (`id`,`name`)'),
    ),
));
