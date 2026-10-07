<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

// Extension of the owned physical-controller bootstrap. The linked identity,
// graph/data/poller mutations, and actual authorization SQL use one SQLite DB.
// Graph title generation and form validation remain explicit leaf ports.
require_once $root . '/lib/api_graph.php';
$GLOBALS['graphHandoffWrites'] = [];
$db->exec('CREATE TABLE data_template_rrd(id INTEGER PRIMARY KEY,local_data_id INTEGER);
 CREATE TABLE poller_item(local_data_id INTEGER,host_id INTEGER);
 INSERT INTO graph_local(id,host_id,graph_template_id,snmp_query_id) VALUES(101,12,0,0);
 INSERT INTO graph_templates_graph(id,local_graph_id,graph_template_id,title_cache) VALUES(201,101,0,"Before");
 INSERT INTO graph_templates_item(id,graph_template_id,local_graph_id,task_item_id) VALUES(501,0,101,401);
 INSERT INTO user_auth_perms VALUES(42,1,101);
 INSERT INTO data_local(id,host_id) VALUES(301,12);
 INSERT INTO data_template_rrd VALUES(401,301);
 INSERT INTO poller_item VALUES(301,12);');
$db->exec('ALTER TABLE graph_templates_graph ADD COLUMN title TEXT DEFAULT "Before";ALTER TABLE graph_templates_graph ADD COLUMN width INTEGER DEFAULT 300;ALTER TABLE graph_templates_graph ADD COLUMN height INTEGER DEFAULT 100;ALTER TABLE graph_local ADD COLUMN snmp_index TEXT DEFAULT ""');
if ($scenario['deny_graph'] ?? false) $db->exec('DELETE FROM user_auth_perms WHERE user_id=42 AND type=1 AND item_id=101');
if ($scenario['foreign_child'] ?? false) $db->exec('UPDATE data_local SET host_id=13 WHERE id=301;UPDATE poller_item SET host_id=13 WHERE local_data_id=301');
if ($scenario['foreign_poller'] ?? false) $db->exec('UPDATE poller_item SET host_id=13 WHERE local_data_id=301');
if ($scenario['snmp_graph'] ?? false) $db->exec('UPDATE graph_local SET snmp_query_id=7 WHERE id=101');

function graph_handoff_fixture_state(): array
{
    return ['writes' => $GLOBALS['graphHandoffWrites'], 'metadata' => $GLOBALS['db']->query('SELECT * FROM graph_templates_graph WHERE local_graph_id=101')->fetchAll(PDO::FETCH_ASSOC),'poller' => $GLOBALS['db']->query('SELECT * FROM poller_item')->fetchAll(PDO::FETCH_ASSOC)];
}
function graph_handoff_fixture_save(array $fields, string $table): int
{
    if (!in_array($table, ['graph_local','graph_templates_graph'], true)) throw new RuntimeException('Unsupported native graph write');
    $db = $GLOBALS['db'];
    foreach (array_keys($fields) as $column) {
        if (!preg_match('/^[a-z_]+$/D', $column)) throw new RuntimeException('Invalid native graph field');
        if (!in_array($column, array_column($db->query('PRAGMA table_info(' . $table . ')')->fetchAll(PDO::FETCH_ASSOC), 'name'), true)) $db->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' TEXT');
    }
    $id = (int) $fields['id'];
    unset($fields['id']);
    $statement = $db->prepare('UPDATE ' . $table . ' SET ' . implode(',', array_map(static fn($column) => $column . '=?', array_keys($fields))) . ' WHERE id=?');
    $statement->execute([...array_values($fields),$id]);
    $GLOBALS['events'][] = ['graph-save',$table,$id];
    return $id;
}
function update_graph_title_cache($id): void
{
    $GLOBALS['events'][] = ['graph-title',$id];
    db_execute_prepared('UPDATE graph_templates_graph SET title_cache=title WHERE local_graph_id=?', [$id]);
}
function change_graph_template(...$args): void
{
    $GLOBALS['events'][] = ['template-change',$args];
}
function cacti_count($value): int
{
    return is_countable($value) ? count($value) : 0;
}
function snmpagent_graphs_action_bottom(array $data): void
{
    $GLOBALS['events'][] = ['snmpagent-bottom',$data];
}
