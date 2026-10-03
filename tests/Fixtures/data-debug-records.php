<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

// Owned real SQLite records. Only SQL dialect and permission dropdown visibility
// differ from the installed controller; no RRD file or device is contacted.
$db->sqliteCreateFunction('REGEXP', static fn($pattern, $value): int => preg_match('~' . str_replace('~', '\\~', (string) $pattern) . '~i', (string) $value) === 1 ? 1 : 0, 2);
$db->sqliteCreateFunction('CONCAT', static fn(...$values): string => implode('', $values));
$config['rra_path'] = $directory . '/rra';
$config['config_options_array']['auth_cache_enabled'] = '';
$config['config_options_array']['user_auto_logout_time'] = 0;
$config['config_options_array']['max_title_length'] = 100;
$db->exec("INSERT INTO settings_user VALUES(99,'user_auto_logout_time','0');
ALTER TABLE host ADD COLUMN hostname TEXT;
ALTER TABLE host ADD COLUMN site_id INTEGER;
UPDATE host SET hostname='192.0.2.10',site_id=1 WHERE id=1;
UPDATE host SET hostname='192.0.2.20',site_id=2 WHERE id=2;
ALTER TABLE data_local ADD COLUMN snmp_query_id INTEGER DEFAULT 0;
ALTER TABLE data_local ADD COLUMN snmp_index TEXT DEFAULT '';
ALTER TABLE data_template_data ADD COLUMN id INTEGER;
ALTER TABLE data_template_data ADD COLUMN name TEXT;
ALTER TABLE data_template_data ADD COLUMN data_source_path TEXT;
ALTER TABLE data_template_data ADD COLUMN data_source_profile_id INTEGER DEFAULT 1;
UPDATE data_template_data SET id=local_data_id,name=name_cache,data_source_path='<path_rra>/test.rrd';
UPDATE data_template_data SET data_source_profile_id=2 WHERE local_data_id=103;
CREATE TABLE sites(id INTEGER,name TEXT);
INSERT INTO sites VALUES(1,'Site One'),(2,'Site Two');
CREATE TABLE data_source_profiles(id INTEGER,name TEXT);
INSERT INTO data_source_profiles VALUES(1,'Default & profile'),(2,'Other profile');
CREATE TABLE data_debug(id INTEGER PRIMARY KEY AUTOINCREMENT,started INTEGER,done INTEGER DEFAULT 0,info TEXT,user INTEGER,datasource INTEGER,issue TEXT DEFAULT '');");
$info = array('rrd_folder_writable' => 1, 'rrd_writable' => 1, 'rrd_exists' => 1, 'active' => 'on', 'owner' => 'native owner', 'runas_website' => 'native web', 'runas_poller' => 'native poller', 'last_result' => array('value' => '12'), 'valid_data' => 1, 'rra_timestamp' => 'first', 'rra_timestamp2' => 'second', 'rrd_match' => 1);
$insert = $db->prepare('INSERT INTO data_debug(started,done,info,user,datasource,issue) VALUES(?,?,?,?,?,?)');
$insert->execute(array(1700000000, 1, serialize($info), 1, 101, ''));
$insert->execute(array(1700000010, 0, serialize($info), 2, 102, null));
$insert->execute(array(1700000020, 0, serialize($info), 1, 103, ''));
if (isset($scenario['state'])) {
    $state = $scenario['state'];
    if ($state === 'waiting') {
        $done = 0;
        $issue = null;
    } elseif ($state === 'analysis') {
        $done = 0;
        $issue = '';
    } else {
        $done = 1;
        $issue = $state === 'failed' ? 'Polling issue' : '';
    }
    $db->prepare('UPDATE data_debug SET done=?,issue=? WHERE datasource=101')->execute(array($done, $issue));
}
$tables = array_merge($tables, array('sites', 'data_source_profiles', 'data_debug'));
if ($cleanerView) {
    $db->exec('CREATE TABLE data_source_purge_temp(id INTEGER PRIMARY KEY,name TEXT,name_cache TEXT,local_data_id INTEGER,data_template_id INTEGER,last_mod TEXT,size INTEGER,in_cacti INTEGER)');
    $insert = $db->prepare('INSERT INTO data_source_purge_temp VALUES(?,?,?,?,?,?,?,?)');
    $now = date('Y-m-d H:i:s');
    $insert->execute(array(1, 'one.rrd', 'Alpha DS', 101, 10, $now, 1024, 0));
    $insert->execute(array(2, 'two.rrd', 'Beta DS', 102, 10, $now, 2048, 0));
    $insert->execute(array(3, 'owned.rrd', 'In use', 103, 20, $now, 4096, 1));
    $tables[] = 'data_source_purge_temp';
}
