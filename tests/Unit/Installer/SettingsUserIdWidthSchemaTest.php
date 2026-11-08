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

/*
 * A new install, an upgraded install and the schema audit must agree that
 * settings_user.user_id holds every user_auth.id (#888).
 */

function settings_user_schema_column($table, $column) {
	$sql = file_get_contents(dirname(__DIR__, 3) . '/cacti.sql');

	if (!preg_match('/^CREATE TABLE `?' . preg_quote($table, '/') . '`? \((.*?)^\)/ms', $sql, $body)) {
		throw new RuntimeException('cacti.sql has no table ' . $table);
	}

	if (!preg_match('/^\s*`?' . preg_quote($column, '/') . '`?\s+(.+?),?$/m', $body[1], $definition)) {
		throw new RuntimeException('cacti.sql has no column ' . $table . '.' . $column);
	}

	return $definition[1];
}

function settings_user_schema_integer_type($definition) {
	preg_match('/^(tinyint|smallint|mediumint|int|bigint)\((\d+)\)( unsigned)?/i', $definition, $type);

	return strtolower($type[0] ?? '');
}

test('cacti.sql gives settings_user.user_id the integer type and width of user_auth.id', function () {
	$user_id = settings_user_schema_integer_type(settings_user_schema_column('settings_user', 'user_id'));
	$id      = settings_user_schema_integer_type(settings_user_schema_column('user_auth', 'id'));

	expect($user_id)->toBe('mediumint(8) unsigned')
		->and($id)->toBe($user_id)
		->and(settings_user_schema_column('settings_user', 'user_id'))->toBe("mediumint(8) unsigned NOT NULL default '0'");
});

test('the 1.2.32 upgrade widens settings_user.user_id to the cacti.sql definition', function () {
	$upgrade = file_get_contents(dirname(__DIR__, 3) . '/install/upgrades/1_2_32.php');

	expect(preg_match_all('/ALTER TABLE settings_user MODIFY user_id ([^"]+)"/', $upgrade, $alters))->toBe(1)
		->and($alters[1][0])->toBe(settings_user_schema_column('settings_user', 'user_id'));
});

test('the schema audit baseline records settings_user.user_id as cacti.sql defines it', function () {
	$audit = file_get_contents(dirname(__DIR__, 3) . '/docs/audit_schema.sql');

	expect($audit)->toContain("INSERT INTO `table_columns` VALUES ('settings_user',1,'user_id','mediumint(8) unsigned','NO','PRI','0','');")
		->and($audit)->toContain("INSERT INTO `table_columns` VALUES ('user_auth',1,'id','mediumint(8) unsigned',");
});

test('cacti.sql makes each account and group id column as wide as the id it holds (#927)', function () {
	$user = settings_user_schema_integer_type(settings_user_schema_column('user_auth', 'id'));
	$group = settings_user_schema_integer_type(settings_user_schema_column('user_auth_group', 'id'));

	expect($group)->toBe('int(10) unsigned')
		->and(settings_user_schema_integer_type(settings_user_schema_column('user_log', 'user_id')))->toBe($user)
		->and(settings_user_schema_integer_type(settings_user_schema_column('user_auth_row_cache', 'user_id')))->toBe($user)
		->and(settings_user_schema_integer_type(settings_user_schema_column('settings_user_group', 'group_id')))->toBe($group);
});

test('the 1.2.32 upgrade widens each account and group id column to its cacti.sql definition (#927)', function () {
	$upgrade = file_get_contents(dirname(__DIR__, 3) . '/install/upgrades/1_2_32.php');

	foreach (array('user_log' => 'user_id', 'user_auth_row_cache' => 'user_id', 'settings_user_group' => 'group_id') as $table => $column) {
		expect(preg_match("/array\\('" . $table . "',\\s+'" . $column . "',\\s+'[a-z]+',\\s+\"([^\"]+)\"\\)/", $upgrade, $definition))->toBe(1)
			->and(strtolower($definition[1]))->toBe(strtolower(settings_user_schema_column($table, $column)));
	}
});

test('the schema audit baseline records the account and group id columns as cacti.sql defines them (#927)', function () {
	$audit = file_get_contents(dirname(__DIR__, 3) . '/docs/audit_schema.sql');

	expect($audit)->toContain("INSERT INTO `table_columns` VALUES ('user_log',2,'user_id','mediumint(8) unsigned','NO','PRI','0','');")
		->and($audit)->toContain("INSERT INTO `table_columns` VALUES ('user_auth_row_cache',1,'user_id','mediumint(8) unsigned','NO','PRI','0','');")
		->and($audit)->toContain("INSERT INTO `table_columns` VALUES ('settings_user_group',1,'group_id','int(10) unsigned','NO','PRI','0','');")
		->and($audit)->toContain("INSERT INTO `table_columns` VALUES ('user_auth_group',1,'id','int(10) unsigned',");
});
