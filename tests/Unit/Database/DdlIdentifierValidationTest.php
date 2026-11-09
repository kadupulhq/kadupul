<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests\DdlIdentifierValidation;

require_once dirname(__DIR__, 2) . '/Helpers/PhpSource.php';
require_once dirname(__DIR__, 3) . '/src/Platform/Infrastructure/Legacy/LegacyConnectionRegistry.php';

/*
 * The DDL helpers from lib/database.php and the plugin wrappers from
 * lib/plugins.php run as written; only the calls that reach the server are
 * replaced. A real SQLite PDO handle supplies db_qstr()'s quoting.
 */

function ddl_state(): array
{
    return $GLOBALS['ddl_validation'];
}

function db_execute($sql, $log = true, $db_conn = false)
{
    $GLOBALS['ddl_validation']['executed'][] = $sql;

    return true;
}

function db_execute_prepared($sql, $params = array(), $log = true, $db_conn = false)
{
    $GLOBALS['ddl_validation']['prepared'][] = array($sql, $params);

    return true;
}

function db_fetch_assoc($sql, $log = true, $db_conn = false)
{
    $GLOBALS['ddl_validation']['read'][] = $sql;

    if (stripos($sql, 'SHOW columns FROM') === 0) {
        return $GLOBALS['ddl_validation']['columns'];
    }

    if (str_starts_with($sql, 'SHOW INDEX FROM')) {
        return $GLOBALS['ddl_validation']['indexes'];
    }

    if (str_contains($sql, 'FROM poller')) {
        return array(array('id' => 2, 'last_polled' => 0));
    }

    return array();
}

function db_fetch_row($sql, $log = true, $db_conn = false)
{
    $GLOBALS['ddl_validation']['read'][] = $sql;

    return $GLOBALS['ddl_validation']['row'];
}

function db_fetch_cell($sql, $col_name = '', $log = true, $db_conn = false)
{
    $GLOBALS['ddl_validation']['read'][] = $sql;

    return $GLOBALS['ddl_validation']['cell'];
}

function db_table_exists($table, $log = true, $db_conn = false)
{
    return $GLOBALS['ddl_validation']['table_exists'];
}

function db_get_global_variable($variable, $db_conn = false)
{
    return 'barracuda';
}

function cacti_sizeof($array)
{
    return is_array($array) ? count($array) : 0;
}

function cacti_log(...$arguments) {}

function clean_up_lines($string)
{
    return $string;
}

function array_rekey($rows, $key, $value)
{
    return array_column($rows, $value, $key);
}

function read_config_option($name, $force = false)
{
    return 300;
}

function poller_connect_to_remote($poller_id)
{
    return new \stdClass();
}

$root     = dirname(__DIR__, 3);
$database = file_get_contents($root . '/lib/database.php');
$plugins  = file_get_contents($root . '/lib/plugins.php');

if ($database === false || $plugins === false) {
    throw new \RuntimeException('Unable to read the production libraries');
}

foreach (array(
    'db_has_control_chars', 'db_is_safe_identifier', 'db_format_qualified_identifier', 'db_is_safe_column_type',
    'db_is_safe_table_option', 'db_is_safe_index_column', 'db_is_safe_column_definition',
    'db_build_column_definition_sql', 'db_is_safe_table_definition', 'db_index_columns_to_array',
    'db_index_columns_equal', 'db_column_type_normalize',
) as $helper) {
    // Loaded when present so a run against the unhardened library reaches the
    // assertions below instead of stopping here.
    if (str_contains($database, 'function ' . $helper . '(')) {
        eval('namespace ' . __NAMESPACE__ . ';' . \test_php_function_source($database, $helper)); // nosemgrep: php.lang.security.eval-use.eval-use
    }
}

foreach (array(
    'db_qstr', 'db_add_column', 'db_remove_column', 'db_add_index', 'db_index_exists',
    'db_index_matches', 'db_column_exists', 'db_get_table_column_types', 'db_update_table',
    'db_format_index_create', 'db_table_create',
) as $function) {
    eval('namespace ' . __NAMESPACE__ . ';' . \test_php_function_source($database, $function)); // nosemgrep: php.lang.security.eval-use.eval-use
}

foreach (array(
    'api_plugin_db_table_create', 'api_plugin_drop_table', 'api_plugin_db_changes_remove',
    'api_plugin_db_add_column', 'api_plugin_drop_remote_table',
) as $function) {
    eval('namespace ' . __NAMESPACE__ . ';' . \test_php_function_source($plugins, $function)); // nosemgrep: php.lang.security.eval-use.eval-use
}


function ddl_reset(array $overrides = array()): void
{
    $GLOBALS['database_hostname'] = 'localhost';
    $GLOBALS['database_port']     = '3306';
    $GLOBALS['database_default']  = 'cacti';
    $GLOBALS['database_sessions'] = array('localhost:3306:cacti' => new \PDO('sqlite::memory:'));

    $owned = sys_get_temp_dir() . '/kadupul-ddl-validation-' . bin2hex(random_bytes(8));
    if (!mkdir($owned, 0700)) {
        throw new \RuntimeException('Unable to create owned DDL fixture directory');
    }
    $GLOBALS['ddl_validation_owned_stubs'][] = $owned;
    $stubs = $owned . '/lib';
    if (!mkdir($stubs, 0700) || file_put_contents($stubs . '/database.php', '<?php') !== 5 || file_put_contents($stubs . '/poller.php', '<?php') !== 5) {
        throw new \RuntimeException('Unable to write DDL fixture bootstrap');
    }
    $GLOBALS['config'] = array('library_path' => $stubs, 'base_path' => dirname($stubs));

    $GLOBALS['ddl_validation'] = array_merge(array(
        'executed'     => array(),
        'prepared'     => array(),
        'read'         => array(),
        'columns'      => array(array('Field' => 'id', 'Type' => 'int', 'Null' => 'NO', 'Default' => null, 'Extra' => '')),
        'indexes'      => array(),
        'row'          => array(),
        'cell'         => '',
        'table_exists' => false,
    ), $overrides);
}

function ddl_thold_style_table(): array
{
    return array(
        'type'        => 'InnoDB',
        'charset'     => 'utf8mb4',
        'collate'     => 'utf8mb4_unicode_ci',
        'row_format'  => 'Dynamic',
        'comment'     => "Plugin's threshold data",
        'columns'     => array(
            array('name' => 'id', 'type' => 'int(12)', 'unsigned' => true, 'NULL' => false, 'auto_increment' => true),
            array('name' => 'name', 'type' => 'varchar(255)', 'NULL' => false, 'default' => ''),
            array('name' => 'thold_type', 'type' => 'enum("hi","low")', 'NULL' => false, 'default' => 'hi'),
            array('name' => 'state', 'type' => "set('a','b')", 'NULL' => true),
            array('name' => 'ratio', 'type' => 'decimal(7,5)', 'NULL' => false, 'default' => '0.00000'),
            array('name' => 'total', 'type' => 'BIGINT UNSIGNED', 'NULL' => false, 'default' => 0),
            array('name' => 'lastchanged', 'type' => 'timestamp', 'NULL' => false, 'default' => 'CURRENT_TIMESTAMP', 'on_update' => 'CURRENT_TIMESTAMP'),
        ),
        'primary'     => 'id',
        'keys'        => array(
            array('name' => 'name_state', 'columns' => 'name`,`state'),
            array('name' => 'name_prefix', 'columns' => array('name(10)')),
        ),
        'unique_keys' => array(array('name' => 'uniq_name', 'columns' => array('name'))),
    );
}

$saved_globals = array();

beforeEach(function () use (&$saved_globals) {
    foreach (array('config', 'database_hostname', 'database_port', 'database_default', 'database_sessions', 'ddl_validation', 'ddl_validation_owned_stubs') as $name) {
        $saved_globals[$name] = array_key_exists($name, $GLOBALS) ? array($GLOBALS[$name]) : null;
    }
    $GLOBALS['ddl_validation_owned_stubs'] = array();
});

// Restore what other files in the same run may read.
afterEach(function () use (&$saved_globals) {
    foreach ($GLOBALS['ddl_validation_owned_stubs'] ?? array() as $owned) {
        foreach (array('database.php', 'poller.php') as $file) {
            $path = $owned . '/lib/' . $file;
            if (file_exists($path) || is_link($path)) {
                unlink($path);
            }
        }
        if (is_dir($owned . '/lib')) {
            rmdir($owned . '/lib');
        }
        rmdir($owned);
    }
    foreach ($saved_globals as $name => $value) {
        if ($value === null) {
            unset($GLOBALS[$name]);
        } else {
            $GLOBALS[$name] = $value[0];
        }
    }
});

test('db_table_create builds a plugin table definition with quoted values', function () {
    ddl_reset();

    expect(db_table_create('plugin_thold_data', ddl_thold_style_table()))->toBeTrue();

    expect(ddl_state()['executed'])->toBe(array(
        "CREATE TABLE `plugin_thold_data` (\n"
        . "`id` int(12) unsigned NOT NULL auto_increment,\n"
        . "`name` varchar(255) NOT NULL default '',\n"
        . "`thold_type` enum(\"hi\",\"low\") NOT NULL default 'hi',\n"
        . "`state` set('a','b') default NULL,\n"
        . "`ratio` decimal(7,5) NOT NULL default 0.00000,\n"
        . "`total` BIGINT UNSIGNED NOT NULL default 0,\n"
        . "`lastchanged` timestamp NOT NULL default CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,\n"
        . " PRIMARY KEY (`id`),\n"
        . " KEY `name_state` (`name`,`state`),\n"
        . " KEY `name_prefix` (`name`(10)),\n"
        . " UNIQUE KEY `uniq_name` (`name`)) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci COMMENT = 'Plugin''s threshold data' ROW_FORMAT = Dynamic",
    ));
});

test('db_table_create refuses an unsafe definition before any SQL runs', function (string $table, callable $mutate) {
    ddl_reset();

    $data = ddl_thold_style_table();
    $mutate($data);

    expect(db_table_create($table, $data))->toBeFalse()
        ->and(ddl_state()['executed'])->toBe(array());
})->with(array(
    'table name'       => array('plugin_x` (id int); DROP TABLE user_auth; --', fn(&$d) => null),
    'engine'           => array('plugin_x', function (&$d) {
        $d['type'] = 'InnoDB; DROP TABLE user_auth';
    }),
    'charset'          => array('plugin_x', function (&$d) {
        $d['charset'] = 'utf8mb4, ENGINE = MyISAM';
    }),
    'column name'      => array('plugin_x', function (&$d) {
        $d['columns'][1]['name'] = 'name` int, `x';
    }),
    'column type'      => array('plugin_x', function (&$d) {
        $d['columns'][1]['type'] = 'varchar(255), ADD COLUMN x int';
    }),
    'missing type'     => array('plugin_x', function (&$d) {
        unset($d['columns'][1]['type']);
    }),
    'on update'        => array('plugin_x', function (&$d) {
        $d['columns'][6]['on_update'] = 'NOW(), x = 1';
    }),
    'default newline'  => array('plugin_x', function (&$d) {
        $d['columns'][1]['default'] = "a\nb";
    }),
    'comment newline'  => array('plugin_x', function (&$d) {
        $d['comment'] = "a\rb";
    }),
    'key name'         => array('plugin_x', function (&$d) {
        $d['keys'][0]['name'] = 'k` (id), DROP PRIMARY KEY, ADD KEY `k';
    }),
    'key column'       => array('plugin_x', function (&$d) {
        $d['keys'][1]['columns'] = array('name); DROP TABLE user_auth; --');
    }),
    'primary column'   => array('plugin_x', function (&$d) {
        $d['primary'] = 'id`), KEY `x';
    }),
));

test('db_add_column composes a validated, quoted column', function () {
    ddl_reset();

    expect(db_add_column('plugin_config', array('name' => 'notes', 'type' => 'varchar(64)', 'NULL' => false, 'default' => "it's", 'comment' => 'free text', 'after' => 'id')))->toBeTruthy();

    expect(ddl_state()['executed'])->toBe(array(
        "ALTER TABLE `plugin_config` ADD `notes` varchar(64) NOT NULL default 'it''s' COMMENT 'free text' AFTER `id`",
    ));
});

test('db_add_column refuses unsafe names and clauses before reading the table', function (string $table, array $column) {
    ddl_reset();

    expect(db_add_column($table, $column))->toBeFalse()
        ->and(ddl_state()['executed'])->toBe(array())
        ->and(ddl_state()['read'])->toBe(array());
})->with(array(
    'table'  => array('plugin_config` ADD x int; --', array('name' => 'x', 'type' => 'int')),
    'name'   => array('plugin_config', array('name' => 'x` int, ADD `y', 'type' => 'int')),
    'type'   => array('plugin_config', array('name' => 'x', 'type' => 'int; DROP TABLE user_auth')),
    'after'  => array('plugin_config', array('name' => 'x', 'type' => 'int', 'after' => 'id, ADD y int')),
));

test('index, column and table metadata helpers refuse unsafe identifiers', function () {
    ddl_reset();

    expect(db_remove_column('plugin_config', 'id` , DROP `name'))->toBeFalse()
        ->and(db_add_index('plugin_config', 'INDEX', 'idx', array('name); DROP TABLE user_auth; --')))->toBeFalse()
        ->and(db_add_index('plugin_config', 'INDEX `x`(id), DROP INDEX', 'idx', array('name')))->toBeFalse()
        ->and(db_add_index('plugin_config', 'INDEX', 'idx` (id), ADD KEY `x', array('name')))->toBeFalse()
        ->and(db_index_exists("plugin_config` WHERE 1; --", 'idx'))->toBeFalse()
        ->and(db_index_matches('plugin_config', 'idx', array("name' OR 1=1")))->toBeFalse()
        ->and(db_column_exists('plugin_config', "x' OR 1=1 OR '"))->toBeFalse()
        ->and(db_get_table_column_types('plugin_config; DROP TABLE user_auth'))->toBeFalse();

    expect(ddl_state()['executed'])->toBe(array())
        ->and(ddl_state()['read'])->toBe(array());
});

test('lookups accept the hyphenated column names the shipped schema uses', function () {
    ddl_reset(array('cell' => 'max-access'));

    expect(db_column_exists('snmpagent_cache', 'max-access'))->toBeTrue()
        ->and(ddl_state()['read'])->toBe(array("SHOW columns FROM `snmpagent_cache` LIKE 'max-access'"));
});

test('db_add_index writes the index through quoted identifiers', function () {
    ddl_reset();

    expect(db_add_index('plugin_config', 'UNIQUE INDEX', 'idx_name', array('name', 'host_id')))->toBeTrue();

    expect(ddl_state()['executed'])->toBe(array('ALTER TABLE `plugin_config` ADD UNIQUE INDEX `idx_name`(`name`,`host_id`)'));
});

test('db_get_table_column_types quotes a database-qualified table', function () {
    ddl_reset();

    db_get_table_column_types('`cacti`.user_auth');

    expect(ddl_state()['read'])->toBe(array('SHOW COLUMNS FROM `cacti`.`user_auth`'));
});

test('db_update_table refuses unsafe table options before altering', function (array $data) {
    ddl_reset(array('table_exists' => true));

    expect(db_update_table('plugin_config', $data))->toBeFalse()
        ->and(ddl_state()['executed'])->toBe(array());
})->with(array(
    'engine'     => array(array('type' => 'InnoDB, DROP COLUMN id', 'columns' => array())),
    'collate'    => array(array('collate' => 'utf8mb4_bin; DROP TABLE user_auth', 'columns' => array())),
    'row format' => array(array('row_format' => 'Dynamic, DROP PRIMARY KEY', 'columns' => array())),
    'comment'    => array(array('comment' => "x\0y", 'columns' => array())),
));

test('db_update_table rebuilds a changed index with valid SQL', function () {
    ddl_reset(array(
        'table_exists' => true,
        'cell'         => '1',
        'row'          => array('ENGINE' => 'InnoDB', 'TABLE_COMMENT' => '', 'Type' => 'int', 'Null' => 'NO', 'Extra' => ''),
        'indexes'      => array(array('Key_name' => 'idx', 'Seq_in_index' => 1, 'Column_name' => 'id', 'Non_unique' => 1)),
    ));

    expect(db_update_table('plugin_config', array(
        'type'    => 'InnoDB',
        'columns' => array(array('name' => 'id', 'type' => 'int', 'NULL' => false)),
        'keys'    => array(array('name' => 'idx', 'columns' => array('id', 'name(10)'))),
    )))->toBeTrue();

    expect(ddl_state()['executed'])->toBe(array(
        'ALTER TABLE `plugin_config` DROP INDEX `idx`, ADD INDEX `idx` (`id`,`name`(10))',
    ));
});

test('db_update_table reconciles unique keys and accepts string key columns', function (array $indexes, array $definition, array $expected) {
    ddl_reset(array(
        'table_exists' => true,
        'cell'         => '1',
        'row'          => array('ENGINE' => 'InnoDB', 'TABLE_COMMENT' => '', 'Type' => 'int', 'Null' => 'NO', 'Extra' => ''),
        // SHOW INDEX reports a prefix length in Sub_part and NULL otherwise.
        'indexes'      => array_map(static fn(array $index): array => array('Key_name' => $index[0], 'Seq_in_index' => $index[1], 'Column_name' => $index[2], 'Non_unique' => $index[3], 'Sub_part' => $index[4] ?? null), $indexes),
    ));

    expect(db_update_table('plugin_config', array('type' => 'InnoDB', 'columns' => array(array('name' => 'id', 'type' => 'int', 'NULL' => false))) + $definition))->toBeTrue()
        ->and(ddl_state()['executed'])->toBe($expected);
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
        array('ALTER TABLE `plugin_config` ADD UNIQUE INDEX `u_name` (`name`,`id`)'),
    ),
    'unique key that lost its uniqueness is rebuilt' => array(
        array(array('PRIMARY', 1, 'id', 0), array('u_name', 1, 'name', 1)),
        array('primary' => array('id'), 'unique_keys' => array(array('name' => 'u_name', 'columns' => array('name')))),
        array('ALTER TABLE `plugin_config` DROP INDEX `u_name`, ADD UNIQUE INDEX `u_name` (`name`)'),
    ),
    'undeclared index is still removed when keys are declared' => array(
        array(array('PRIMARY', 1, 'id', 0), array('idx', 1, 'id', 1), array('u_name', 1, 'name', 0), array('stale', 1, 'name', 1)),
        array('primary' => array('id'), 'keys' => array(array('name' => 'idx', 'columns' => array('id'))), 'unique_keys' => array(array('name' => 'u_name', 'columns' => array('name')))),
        array('ALTER TABLE `plugin_config` DROP INDEX `stale`'),
    ),
    'undeclared index is kept when only unique keys are declared' => array(
        array(array('PRIMARY', 1, 'id', 0), array('u_name', 1, 'name', 0), array('other', 1, 'name', 1)),
        array('primary' => array('id'), 'unique_keys' => array(array('name' => 'u_name', 'columns' => array('name')))),
        array(),
    ),
    // cli/sqltable_to_php.php lists every secondary index under keys, unique or not.
    'unique index declared under keys keeps its uniqueness' => array(
        array(array('PRIMARY', 1, 'id', 0), array('u_name', 1, 'name', 0)),
        array('primary' => array('id'), 'keys' => array(array('name' => 'u_name', 'columns' => array('name')))),
        array(),
    ),
    'empty keys still removes undeclared indexes' => array(
        array(array('PRIMARY', 1, 'id', 0), array('stale', 1, 'name', 1)),
        array('primary' => array('id'), 'keys' => array()),
        array('ALTER TABLE `plugin_config` DROP INDEX `stale`'),
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
        array('ALTER TABLE `plugin_config` DROP PRIMARY KEY, ADD PRIMARY KEY(`id`,`name`)'),
    ),
    'reordered primary key is rebuilt' => array(
        array(array('PRIMARY', 1, 'name', 0), array('PRIMARY', 2, 'id', 0)),
        array('primary' => array('id', 'name')),
        array('ALTER TABLE `plugin_config` DROP PRIMARY KEY, ADD PRIMARY KEY(`id`,`name`)'),
    ),
    // SHOW INDEX lists a prefix key as the bare column plus Sub_part.
    'prefix key matches its live index' => array(
        array(array('PRIMARY', 1, 'id', 0), array('idx', 1, 'name', 1, '10')),
        array('primary' => array('id'), 'keys' => array(array('name' => 'idx', 'columns' => array('name(10)')))),
        array(),
    ),
    'prefix key given with spacing matches its live index' => array(
        array(array('PRIMARY', 1, 'id', 0), array('idx', 1, 'id', 1), array('idx', 2, 'name', 1, 10)),
        array('primary' => array('id'), 'keys' => array(array('name' => 'idx', 'columns' => 'id`,`name` (10)'))),
        array(),
    ),
    'changed prefix length is rebuilt' => array(
        array(array('PRIMARY', 1, 'id', 0), array('idx', 1, 'name', 1, '20')),
        array('primary' => array('id'), 'keys' => array(array('name' => 'idx', 'columns' => array('name(10)')))),
        array('ALTER TABLE `plugin_config` DROP INDEX `idx`, ADD INDEX `idx` (`name`(10))'),
    ),
    'full column declared over a live prefix is rebuilt' => array(
        array(array('PRIMARY', 1, 'id', 0), array('idx', 1, 'name', 1, '10')),
        array('primary' => array('id'), 'keys' => array(array('name' => 'idx', 'columns' => array('name')))),
        array('ALTER TABLE `plugin_config` DROP INDEX `idx`, ADD INDEX `idx` (`name`)'),
    ),
    'reordered index columns are rebuilt' => array(
        array(array('PRIMARY', 1, 'id', 0), array('idx', 1, 'name', 1), array('idx', 2, 'id', 1)),
        array('primary' => array('id'), 'keys' => array(array('name' => 'idx', 'columns' => array('id', 'name')))),
        array('ALTER TABLE `plugin_config` DROP INDEX `idx`, ADD INDEX `idx` (`id`,`name`)'),
    ),
    'rows listed out of order still match' => array(
        array(array('PRIMARY', 1, 'id', 0), array('idx', 2, 'name', 1), array('idx', 1, 'id', 1)),
        array('primary' => array('id'), 'keys' => array(array('name' => 'idx', 'columns' => array('id', 'name')))),
        array(),
    ),
    'column name case does not force a rebuild' => array(
        array(array('PRIMARY', 1, 'id', 0), array('idx', 1, 'Name', 1)),
        array('primary' => array('ID'), 'keys' => array(array('name' => 'idx', 'columns' => array('name')))),
        array(),
    ),
));

test('db_update_table compares integer columns without their display width', function (array $column, string $live_type, bool $changed) {
    ddl_reset(array(
        'table_exists' => true,
        'cell'         => '1',
        'row'          => array('ENGINE' => 'InnoDB', 'TABLE_COMMENT' => '', 'Type' => $live_type, 'Null' => 'NO', 'Extra' => ''),
    ));

    expect(db_update_table('plugin_config', array('type' => 'InnoDB', 'columns' => array(array('name' => 'id', 'NULL' => false) + $column))))->toBeTrue()
        ->and(ddl_state()['executed'] !== array())->toBe($changed);
})->with(array(
    // MySQL 8.0.19 and later drop the width that MariaDB and MySQL 5.7 report.
    'int(11) on MySQL 8'              => array(array('type' => 'int(11)'), 'int', false),
    'int(11) on MariaDB'              => array(array('type' => 'int(11)'), 'int(11)', false),
    'unsigned flag on MySQL 8'        => array(array('type' => 'int(10)', 'unsigned' => true), 'int unsigned', false),
    'unsigned in the type'            => array(array('type' => 'BIGINT UNSIGNED'), 'bigint(20) unsigned', false),
    'tinyint(1) on MySQL 8'           => array(array('type' => 'tinyint(3)'), 'tinyint(1)', false),
    'integer alias'                   => array(array('type' => 'integer'), 'int', false),
    'different integer type'          => array(array('type' => 'int(11)'), 'bigint', true),
    'lost unsigned'                   => array(array('type' => 'int(11)', 'unsigned' => true), 'int', true),
    'zerofill keeps its width'        => array(array('type' => 'int(5) zerofill'), 'int(8) zerofill', true),
    'same zerofill width'             => array(array('type' => 'int(5) zerofill'), 'int(5) zerofill', false),
    'changed varchar length'          => array(array('type' => 'varchar(64)'), 'varchar(32)', true),
));

test('plugin DDL wrappers refuse unsafe identifiers and record nothing', function () {
    ddl_reset();

    api_plugin_db_table_create('fixture', 'plugin_x` (id int); DROP TABLE user_auth; --', ddl_thold_style_table());
    api_plugin_db_add_column('fixture', 'plugin_config', array('name' => 'x', 'type' => 'int, ADD y int'));
    api_plugin_db_add_column('fixture', 'plugin_config` ADD x int; --', array('name' => 'x', 'type' => 'int'));
    api_plugin_drop_table('plugin_x; DROP TABLE user_auth');
    api_plugin_drop_remote_table('plugin_x; DROP TABLE user_auth');

    expect(ddl_state()['executed'])->toBe(array())
        ->and(ddl_state()['prepared'])->toBe(array());
});

test('plugin DDL wrappers create, add and drop well-formed plugin objects', function () {
    ddl_reset();

    api_plugin_db_table_create('fixture', 'plugin_fixture', array('type' => 'InnoDB', 'columns' => array(array('name' => 'id', 'type' => 'int', 'NULL' => false)), 'primary' => 'id'));
    api_plugin_db_add_column('fixture', 'plugin_config', array('name' => 'extra', 'type' => 'tinyint(1)', 'NULL' => false, 'default' => 0, 'after' => 'id'));
    api_plugin_drop_table('plugin_fixture');

    expect(ddl_state()['executed'])->toBe(array(
        "CREATE TABLE `plugin_fixture` (\n`id` int NOT NULL,\n PRIMARY KEY (`id`)) ENGINE = InnoDB",
        'ALTER TABLE `plugin_config` ADD `extra` tinyint(1) NOT NULL default 0 AFTER `id`',
        'DROP TABLE IF EXISTS `plugin_fixture`',
        'DROP TABLE IF EXISTS `plugin_fixture`',
    ))->and(array_column(ddl_state()['prepared'], 1))->toBe(array(
        array('fixture', 'plugin_fixture'),
        array('fixture', 'plugin_config', 'extra'),
    ));
});
