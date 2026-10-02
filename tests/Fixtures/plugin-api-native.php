<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later
// Full production module is loaded by the native bootstrap; SQL is executed,
// with only UPDATE IGNORE and multi-table DELETE adapted to SQLite syntax.
foreach (array(
    'CREATE TABLE plugin_hooks(id INTEGER PRIMARY KEY, name TEXT, hook TEXT, `function` TEXT, file TEXT, status INTEGER)',
    'CREATE TABLE plugin_realms(id INTEGER PRIMARY KEY, plugin TEXT, file TEXT, display TEXT)',
    'CREATE TABLE plugin_db_changes(plugin TEXT, `table` TEXT, `column` TEXT, method TEXT)',
    'CREATE TABLE user_auth_realm(user_id INTEGER, realm_id INTEGER, PRIMARY KEY(user_id, realm_id))',
    'CREATE TABLE user_auth_group_realm(group_id INTEGER, realm_id INTEGER, PRIMARY KEY(group_id, realm_id))'
) as $sql) {
    $db->exec($sql);
}
$_SESSION['sess_user_id'] = 2;
function fixture_setup_hook($hook, $function = 'first', $enabled = false)
{
    api_plugin_register_hook('fixture', $hook, $function, 'setup.php', $enabled);
}
function fixture_setup_realm($file, $display = 'Fixture', $admin = true)
{
    api_plugin_register_realm('fixture', $file, $display, $admin);
}
function fixture_snapshot($table)
{
    return $GLOBALS['db']->query('SELECT * FROM ' . $table . ' ORDER BY 1')->fetchAll(PDO::FETCH_ASSOC);
}
$result = array();
switch ($scenario['operation']) {
    case 'schema':
        $db->exec('CREATE TABLE existing(id INTEGER PRIMARY KEY)');
        api_plugin_db_table_create('fixture', 'owned', array('columns' => array(array('name' => 'id', 'type' => 'INTEGER')), 'type' => 'InnoDB'));
        api_plugin_db_add_column('fixture', 'existing', array('name' => 'added', 'type' => 'INTEGER', 'default' => 0));
        $result['changes'] = fixture_snapshot('plugin_db_changes');
        $result['columns'] = $db->query('PRAGMA table_info(existing)')->fetchAll(PDO::FETCH_ASSOC);
        api_plugin_db_changes_remove('fixture');
        $result['removed_changes'] = fixture_snapshot('plugin_db_changes');
        $result['remaining_columns'] = $db->query('PRAGMA table_info(existing)')->fetchAll(PDO::FETCH_ASSOC);
        $result['remaining_tables'] = $db->query("SELECT name FROM sqlite_master WHERE name='owned'")->fetchAll(PDO::FETCH_ASSOC);
        break;
    case 'hooks':
        fixture_setup_hook('ordinary');
        $result['ordinary'] = fixture_snapshot('plugin_hooks');
        fixture_setup_hook('config_settings');
        fixture_setup_hook('ordinary', 'updated');
        $result['updated'] = fixture_snapshot('plugin_hooks');
        api_plugin_disable_hooks('fixture');
        $result['disabled'] = fixture_snapshot('plugin_hooks');
        api_plugin_enable_hooks('fixture');
        $result['enabled'] = fixture_snapshot('plugin_hooks');
        api_plugin_disable_hooks_all('fixture');
        $result['disabled_all'] = fixture_snapshot('plugin_hooks');
        fixture_setup_hook('ordinary', 'explicit', true);
        $result['explicit'] = fixture_snapshot('plugin_hooks');
        api_plugin_remove_hooks('fixture');
        $result['removed'] = fixture_snapshot('plugin_hooks');
        break;
    case 'realms':
        fixture_setup_realm('a.php');
        $result['created'] = fixture_snapshot('plugin_realms');
        $result['grants'] = fixture_snapshot('user_auth_realm');
        fixture_setup_realm('a.php', 'Updated');
        $result['updated'] = fixture_snapshot('plugin_realms');
        $db->exec("INSERT INTO plugin_realms VALUES (2,'fixture','a.php','Duplicate'),(3,'fixture','b.php,a.php,c.php','Combined')");
        $db->exec('INSERT INTO user_auth_realm VALUES(3,102); INSERT INTO user_auth_group_realm VALUES(4,102)');
        fixture_setup_realm('a.php', 'Consolidated');
        $result['consolidated'] = fixture_snapshot('plugin_realms');
        $result['consolidated_grants'] = fixture_snapshot('user_auth_realm');
        $result['groups'] = fixture_snapshot('user_auth_group_realm');
        api_plugin_remove_realms('fixture');
        $result['removed'] = fixture_snapshot('plugin_realms');
        $result['remaining_grants'] = fixture_snapshot('user_auth_realm');
        $result['remaining_groups'] = fixture_snapshot('user_auth_group_realm');
        break;
    case 'lifecycle':
        $db->exec("INSERT INTO plugin_config VALUES(1,'before',4,'Before','','','1'),(2,'fixture',4,'Fixture','','','1')");
        api_plugin_install('fixture');
        $result['installed'] = fixture_snapshot('plugin_config');
        api_plugin_enable('fixture');
        $result['is_enabled'] = api_plugin_is_enabled('fixture');
        $result['is_enabled_cached'] = api_plugin_is_enabled('fixture');
        $result['absent_enabled'] = api_plugin_is_enabled('absent');
        $result['enabled'] = fixture_snapshot('plugin_config');
        api_plugin_disable('fixture');
        $result['disabled'] = fixture_snapshot('plugin_config');
        api_plugin_moveup('fixture');
        $result['moved'] = fixture_snapshot('plugin_config');
        api_plugin_disable_all('fixture');
        api_plugin_uninstall('fixture', false);
        $result['uninstalled'] = fixture_snapshot('plugin_config');
        break;
    case 'cleanup':
        $db->exec("INSERT INTO plugin_hooks VALUES(1,'orphan','hook','fn','setup.php',1),(2,'internal','hook','fn','setup.php',1)");
        $db->exec("INSERT INTO plugin_db_changes VALUES('orphan','t','','create'),('internal','t','','create')");
        $db->exec("INSERT INTO plugin_realms VALUES(1,'orphan','a.php','Orphan'),(2,'internal','b.php','Internal')");
        $db->exec('INSERT INTO user_auth_realm VALUES(1,101),(1,102); INSERT INTO user_auth_group_realm VALUES(1,101),(1,102)');
        plugin_clean_old_plugin_info();
        foreach (array('plugin_hooks','plugin_db_changes','plugin_realms','user_auth_realm','user_auth_group_realm') as $table) {
            $result[$table] = fixture_snapshot($table);
        }
        break;
}
echo json_encode($result, JSON_THROW_ON_ERROR);
