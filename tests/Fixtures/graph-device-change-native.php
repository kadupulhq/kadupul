<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

function update_graph_title_cache($id): void
{
    $GLOBALS['graphDeviceTitles'][] = $id;
}

function native_graph_device_change(): array
{
    global $db, $scenario;
    require dirname(__DIR__, 2) . '/lib/api_graph.php';
    $db->exec("INSERT INTO host(id,site_id,description) VALUES(101,0,'allowed'),(201,0,'foreign'),(12,0,'destination');
        INSERT INTO graph_local VALUES(1001,101,102,'',0);
        INSERT INTO graph_templates VALUES(102,'visible');
        INSERT INTO graph_templates_graph VALUES(1001,'allowed graph',300,200);
        INSERT INTO user_auth_perms VALUES(42,3,101),(42,3,12),(42,1,1001),(42,4,102);
        CREATE TABLE data_local(id INTEGER PRIMARY KEY,host_id INTEGER);
        CREATE TABLE data_template_rrd(id INTEGER PRIMARY KEY,local_data_id INTEGER);
        CREATE TABLE graph_templates_item(local_graph_id INTEGER,task_item_id INTEGER);
        CREATE TABLE poller_item(local_data_id INTEGER,host_id INTEGER)");
    if ($scenario['deny_graph'] ?? false) {
        $db->exec('DELETE FROM user_auth_perms WHERE user_id=42 AND ((type=1 AND item_id=1001) OR (type=4 AND item_id=102));
            INSERT INTO graph_local VALUES(1002,101,103,"",0),(1003,12,103,"",0);
            INSERT INTO graph_templates VALUES(103,"Other visible template");
            INSERT INTO graph_templates_graph VALUES(1002,"Other source graph",300,200),(1003,"Destination graph",300,200);
            INSERT INTO user_auth_perms VALUES(42,4,103)');
    }
    $source = $scenario['source'] ?? 101;
    $db->prepare('UPDATE graph_local SET host_id=?,snmp_query_id=? WHERE id=1001')->execute([$source, $scenario['snmp'] ?? 0]);
    $children = isset($scenario['size']) ? array_map(static fn(int $id): array => [$id,101,101], range(5001, 5000 + $scenario['size'])) : ($scenario['children'] ?? [[5001,101,101]]);
    foreach ($children as $index => $child) {
        [$id, $owner, $poller] = $child;
        if (!($scenario['missing'] ?? false) && $id !== 0) {
            $db->prepare('INSERT OR IGNORE INTO data_local VALUES(?,?)')->execute([$id,$owner]);
        }
        $db->prepare('INSERT INTO data_template_rrd VALUES(?,?)')->execute([6001 + $index,$id]);
        $db->prepare('INSERT INTO graph_templates_item VALUES(1001,?)')->execute([6001 + $index]);
        if ($poller !== null) {
            $db->prepare('INSERT INTO poller_item VALUES(?,?)')->execute([$id,$poller]);
        }
    }
    $GLOBALS['graphDeviceTitles'] = [];
    $GLOBALS['database_last_error'] = 'preserved prior diagnostic';
    $before = ['graph' => $db->query('SELECT id,host_id FROM graph_local')->fetchAll(PDO::FETCH_ASSOC),
        'data' => $db->query('SELECT id,host_id FROM data_local ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
        'poller' => $db->query('SELECT local_data_id,host_id FROM poller_item ORDER BY local_data_id')->fetchAll(PDO::FETCH_ASSOC)];
    $admission = ['graph' => is_graph_allowed(1001),'source' => $source === 0 || is_device_allowed($source),'foreign' => is_device_allowed(201)];
    $GLOBALS['graphDeviceWrites'] = [];
    $status = api_graph_change_device(1001, $scenario['destination'] ?? 0);
    $after = ['graph' => $db->query('SELECT id,host_id FROM graph_local')->fetchAll(PDO::FETCH_ASSOC),
        'data' => $db->query('SELECT id,host_id FROM data_local ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
        'poller' => $db->query('SELECT local_data_id,host_id FROM poller_item ORDER BY local_data_id')->fetchAll(PDO::FETCH_ASSOC)];
    $GLOBALS['nativeChildCoverageMarkers'] = ['native-policy-operation-returned','policy-session-observed','graph-device-child-scope-observed'];
    return ['status' => $status,'before' => $before,'after' => $after,'titles' => $GLOBALS['graphDeviceTitles'],
        'writes' => $GLOBALS['graphDeviceWrites'], 'admission' => $admission,'queries' => $GLOBALS['queries'],'sql' => $GLOBALS['querySql'],'rows' => $GLOBALS['queryRowCounts'],'diagnostic' => $GLOBALS['database_last_error']];
}
