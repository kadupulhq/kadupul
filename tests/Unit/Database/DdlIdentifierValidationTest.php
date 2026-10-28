<?php

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
    'db_build_column_definition_sql', 'db_is_safe_table_definition',
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

    $stubs = sys_get_temp_dir() . '/kadupul-ddl-validation-' . getmypid() . '/lib';
    if (!is_dir($stubs)) {
        mkdir($stubs, 0700, true);
    }
    file_put_contents($stubs . '/database.php', '<?php');
    file_put_contents($stubs . '/poller.php', '<?php');
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
    foreach (array('config', 'database_hostname', 'database_port', 'database_default', 'database_sessions', 'ddl_validation') as $name) {
        $saved_globals[$name] = array_key_exists($name, $GLOBALS) ? array($GLOBALS[$name]) : null;
    }
});

// Restore what other files in the same run may read.
afterEach(function () use (&$saved_globals) {
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
        . " UNIQUE KEY `uniq_name` (`name`)) ENGINE = InnoDB COMMENT = 'Plugin''s threshold data' ROW_FORMAT = Dynamic",
        'ALTER TABLE `plugin_thold_data` CHARSET = utf8mb4',
        'ALTER TABLE `plugin_thold_data` COLLATE = utf8mb4_unicode_ci',
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
        'indexes'      => array(array('Key_name' => 'idx', 'Seq_in_index' => 1, 'Column_name' => 'id')),
    ));

    expect(db_update_table('plugin_config', array(
        'type'    => 'InnoDB',
        'columns' => array(array('name' => 'id', 'type' => 'int', 'NULL' => false)),
        'keys'    => array(array('name' => 'idx', 'columns' => array('id', 'name(10)'))),
    )))->toBeTrue();

    expect(ddl_state()['executed'])->toBe(array(
        'ALTER TABLE `plugin_config` DROP INDEX `idx`',
        'ALTER TABLE `plugin_config` ADD INDEX `idx` (`id`,`name`(10))',
    ));
});

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
