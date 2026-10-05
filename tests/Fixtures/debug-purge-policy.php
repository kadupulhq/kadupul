<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

// Actual authentication policy functions will execute on these owned SQLite
// records. HTTP bootstrap remains the renderer fixture's explicit boundary.
$policy = $scenario['debug_purge_policy'];
require_once __DIR__ . '/../Helpers/PhpSource.php';
$csrfSource = file_get_contents($root . '/include/csrf.php');
if ($csrfSource === false) {
    throw new RuntimeException('Cannot read the production purge request guard');
}
foreach (['csrf_refuse_cross_site_get', 'csrf_request_is_cross_site', 'csrf_request_host_matches', 'csrf_strip_host_port'] as $function) {
    eval(test_php_function_source($csrfSource, $function));
}

$db->sqliteCreateFunction('UNIX_TIMESTAMP', static fn($value) => strtotime($value));
$db->sqliteCreateFunction('FROM_UNIXTIME', static fn($value) => gmdate('Y-m-d H:i:s', (int) $value));
$config['config_options_array']['auth_method'] = $policy['auth_method'] ?? 1;
$config['config_options_array']['graph_auth_method'] = 3;
$db->exec("ALTER TABLE user_auth ADD COLUMN policy_hosts INTEGER DEFAULT 2;
ALTER TABLE user_auth ADD COLUMN policy_graphs INTEGER DEFAULT 2;
ALTER TABLE user_auth ADD COLUMN enabled TEXT DEFAULT 'on';
ALTER TABLE user_auth ADD COLUMN locked TEXT DEFAULT '';
ALTER TABLE user_auth ADD COLUMN reset_perms INTEGER NOT NULL DEFAULT 0;
ALTER TABLE user_auth ADD COLUMN policy_graph_templates INTEGER DEFAULT 1;
ALTER TABLE user_auth ADD COLUMN policy_trees INTEGER DEFAULT 1;
INSERT INTO user_auth(id,username,full_name,realm) VALUES(99,'Operator','Operator',0);
CREATE TABLE user_auth_row_cache(user_id INTEGER,class TEXT,hash TEXT,total_rows INTEGER,time TEXT,PRIMARY KEY(user_id,class,hash));
CREATE TABLE user_auth_realm(user_id INTEGER,realm_id INTEGER);
CREATE TABLE user_auth_perms(user_id INTEGER,type INTEGER,item_id INTEGER);
CREATE TABLE user_auth_group(id INTEGER,enabled TEXT,name TEXT,policy_hosts INTEGER,policy_graphs INTEGER,policy_graph_templates INTEGER,policy_trees INTEGER);
CREATE TABLE user_auth_group_members(user_id INTEGER,group_id INTEGER);
CREATE TABLE user_auth_group_perms(group_id INTEGER,type INTEGER,item_id INTEGER);
ALTER TABLE host ADD COLUMN deleted TEXT DEFAULT '';
ALTER TABLE host ADD COLUMN host_template_id INTEGER DEFAULT 0;
CREATE TABLE graph_local(id INTEGER,host_id INTEGER,graph_template_id INTEGER);
CREATE TABLE graph_templates(id INTEGER,name TEXT);
CREATE TABLE host_template(id INTEGER,name TEXT);
INSERT INTO graph_local VALUES(1,1,10),(2,2,20);
INSERT INTO graph_templates VALUES(10,'First'),(20,'Second');
INSERT INTO data_local(id,host_id,data_template_id) VALUES(105,0,0),(106,777,0);
INSERT INTO data_template_data(local_data_id,data_template_id,name_cache,active,id,name,data_source_path,data_source_profile_id) VALUES(105,0,'Unassigned','',105,'Unassigned','',1),(106,0,'Missing Device','',106,'Missing Device','',1);
INSERT INTO data_debug(started,done,info,user,datasource,issue) VALUES(1700000000,0,'a:0:{}',2,105,''),(1700000000,0,'a:0:{}',2,106,''),(1700000000,0,'a:0:{}',2,999,'');");
$db->prepare('UPDATE user_auth SET policy_hosts=? WHERE id=99')->execute([$policy['policy_hosts'] ?? 2]);
if ($policy['admin'] ?? false) {
    $db->exec('INSERT INTO user_auth_realm VALUES(99,1)');
}
$insert = $db->prepare('INSERT INTO user_auth_perms VALUES(99,3,?)');
foreach ($policy['exceptions'] ?? [] as $id) {
    $insert->execute([$id]);
}
if ($policy['empty_hosts'] ?? false) {
    $db->exec('DELETE FROM host');
}
$db->exec("INSERT INTO settings_user VALUES(99,'hide_disabled','on')");
$tables = array_merge($tables, ['user_auth_realm','user_auth_perms','user_auth_group','user_auth_group_members','user_auth_group_perms','graph_local','graph_templates','host_template']);

if ($policy['null_host'] ?? false) $db->exec('INSERT INTO data_local(id,host_id,data_template_id) VALUES(107,NULL,0)');
if (isset($policy['batch_count'])) {
    $insertData = $db->prepare('INSERT INTO data_local(id,host_id,data_template_id) VALUES(?,1,10)');
    for ($index = 0; $index < $policy['batch_count']; $index++) {
        $id = 20000 + $index;
        $insertData->execute([$id]);
        $db->prepare("INSERT INTO data_debug(started,done,info,user,datasource,issue) VALUES(1700000000,0,'a:0:{}',2,?,'')")->execute([$id]);
    }
}
