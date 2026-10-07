<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

// Execute native policy helpers against persisted rows, without HTTP bootstrap.
if (PHP_SAPI !== 'cli') {
    exit(1);
}
$root = dirname(__DIR__, 2);
$scenario = json_decode($argv[1], true, 512, JSON_THROW_ON_ERROR);
if ($scenario['operation'] === 'graph-policy-display') {
    require __DIR__ . '/graph-policy-display-native.php';
    require_once $root . '/tests/Helpers/GraphPolicyDisplayCoverageRegistration.php';
}
if ($scenario['operation'] === 'spike-controller') {
    require __DIR__ . '/spike-controller-native.php';
    require_once $root . '/tests/Helpers/SpikeControllerCoverageRegistration.php';
}
if ($scenario['operation'] === 'graph-device-change') require __DIR__ . '/graph-device-change-native.php';
if ($scenario['operation'] === 'management-list') require __DIR__ . '/management-list-native.php';
if ($scenario['operation'] === 'management-bulk') require __DIR__ . '/management-bulk-native.php';
if ($scenario['operation'] === 'graph-data-removal') require __DIR__ . '/graph-data-removal-native.php';
if (isset($argv[3])) {
    require_once $root . '/tests/Helpers/NativeChildCoverageEvidence.php';
    $nativeChildCoverageSnapshot = NativeChildCoverageEvidence::snapshot($root, 'tests/Fixtures/auth-policy-native.php', $argv[1], array('lib/auth.php', 'lib/graph_item_choices.php', 'tests/Helpers/PhpSource.php', 'tests/Fixtures/rrd-process-coverage.php', 'tests/Helpers/NativeChildCoverageEvidence.php', 'lib/rrd.php', 'src/Graphing/Infrastructure/Rrd/ProxyCipher.php', 'lib/dsdebug.php', 'lib/rrd_maintenance.php', 'lib/poller.php', 'lib/boost.php', 'lib/api_data_source.php', 'lib/rrdcheck.php', 'lib/dsstats.php'));
    if ($scenario['operation'] === 'graph-policy-display') {
        $nativeChildCoverageSnapshot = NativeChildCoverageEvidence::snapshot($root, 'tests/Fixtures/auth-policy-native.php', $argv[1], GraphPolicyDisplayCoverageRegistration::SOURCES);
    }
    if ($scenario['operation'] === 'graph-device-change') {
        require_once $root . '/tests/Helpers/GraphDeviceChangeCoverageRegistration.php';
        $nativeChildCoverageSnapshot = NativeChildCoverageEvidence::snapshot($root, 'tests/Fixtures/auth-policy-native.php', $argv[1], GraphDeviceChangeCoverageRegistration::SOURCES);
        define('GRAPH_DEVICE_CHANGE_TEST_COVERAGE', true);
    }
    if ($scenario['operation'] === 'management-list') {
        require_once $root . '/tests/Helpers/ManagementListCoverageRegistration.php';
        $nativeChildCoverageSnapshot = NativeChildCoverageEvidence::snapshot($root, 'tests/Fixtures/auth-policy-native.php', $argv[1], ManagementListCoverageRegistration::SOURCES);
        define('MANAGEMENT_LIST_TEST_COVERAGE', true);
    }
    if ($scenario['operation'] === 'management-bulk') {
        require_once $root . '/tests/Helpers/ManagementBulkCoverageRegistration.php';
        $nativeChildCoverageSnapshot = NativeChildCoverageEvidence::snapshot($root, 'tests/Fixtures/auth-policy-native.php', $argv[1], ManagementBulkCoverageRegistration::SOURCES);
    }
    if (in_array($scenario['operation'], ['graph-cache-revocation', 'graph-image-cache'], true)) {
        require_once $root . '/tests/Helpers/GraphCacheCoverageRegistration.php';
        $nativeChildCoverageSnapshot = NativeChildCoverageEvidence::snapshot($root, 'tests/Fixtures/auth-policy-native.php', $argv[1], GraphCacheCoverageRegistration::SOURCES);
        define('GRAPH_CACHE_TEST_COVERAGE', true);
    }
    if ($scenario['operation'] === 'spike-controller') {
        $nativeChildCoverageSnapshot = NativeChildCoverageEvidence::snapshot($root, 'tests/Fixtures/auth-policy-native.php', $argv[1], SpikeControllerCoverageRegistration::SOURCES);
        define('SPIKE_CSRF_TEST_COVERAGE', true);
        define('SPIKE_CONTROLLER_VALIDATION_TEST_COVERAGE', true);
    }
    if ($scenario['operation'] === 'graph-data-removal') {
        require_once $root . '/tests/Helpers/GraphDataRemovalCoverageRegistration.php';
        $nativeChildCoverageSnapshot = NativeChildCoverageEvidence::snapshot($root, 'tests/Fixtures/auth-policy-native.php', $argv[1], GraphDataRemovalCoverageRegistration::SOURCES);
        define('GRAPH_DATA_REMOVAL_TEST_COVERAGE', true);
    }
}
if (in_array($scenario['operation'], ['graph-cache-revocation', 'graph-image-cache'], true)) require __DIR__ . '/graph-cache-native.php';
$config = ['cacti_db_version' => '1.2.33'];
$db = $scenario['operation'] === 'graph-data-removal' ? new GraphDataRemovalFixtureDatabase() : new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE user_auth (id INTEGER, reset_perms INTEGER, enabled TEXT DEFAULT \'on\', locked TEXT DEFAULT \'\', show_tree TEXT, show_list TEXT, show_preview TEXT, graph_settings TEXT, policy_hosts INTEGER, policy_graphs INTEGER, policy_graph_templates INTEGER, policy_trees INTEGER DEFAULT 1)');
$db->prepare('INSERT INTO user_auth(id,reset_perms,show_tree,show_list,show_preview,graph_settings,policy_hosts,policy_graphs,policy_graph_templates) VALUES (42, 0, ?, ?, ?, ?, ?, ?, ?)')->execute(array_merge(array_fill(0, 4, $scenario['view_default'] ?? ''), array_fill(0, 3, $scenario['policy'] ?? 1)));
$db->exec("INSERT INTO user_auth(id,reset_perms,show_tree,show_list,show_preview,graph_settings,policy_hosts,policy_graphs,policy_graph_templates) VALUES (43, 0, '', '', '', '', 1, 1, 1)");
$db->exec('CREATE TABLE user_auth_realm (user_id INTEGER, realm_id INTEGER)');
$db->exec('CREATE TABLE user_auth_group (id INTEGER, enabled TEXT, name TEXT DEFAULT "fixture", show_tree TEXT, show_list TEXT, show_preview TEXT, graph_settings TEXT, policy_hosts INTEGER, policy_graphs INTEGER, policy_graph_templates INTEGER, policy_trees INTEGER DEFAULT 1)');
$db->exec('CREATE TABLE user_auth_group_members (user_id INTEGER, group_id INTEGER)');
$db->exec('CREATE TABLE user_auth_group_realm (group_id INTEGER, realm_id INTEGER)');
$db->exec('CREATE TABLE user_auth_perms (user_id INTEGER, type INTEGER, item_id INTEGER)');
$db->exec('CREATE TABLE user_auth_group_perms (group_id INTEGER, type INTEGER, item_id INTEGER)');
$db->exec('CREATE TABLE plugin_realms (id INTEGER, file TEXT, display TEXT)');
$db->exec("CREATE TABLE graph_tree(id INTEGER PRIMARY KEY, enabled TEXT, name TEXT);
CREATE TABLE graph_tree_items(id INTEGER PRIMARY KEY, graph_tree_id INTEGER, parent INTEGER, title TEXT DEFAULT '', local_graph_id INTEGER DEFAULT 0, host_id INTEGER DEFAULT 0, site_id INTEGER DEFAULT 0, host_grouping_type INTEGER DEFAULT 0, position INTEGER DEFAULT 0);
CREATE TABLE host(id INTEGER PRIMARY KEY, site_id INTEGER, description TEXT, host_template_id INTEGER DEFAULT 0, disabled TEXT DEFAULT '', deleted TEXT DEFAULT '');
CREATE TABLE graph_local(id INTEGER PRIMARY KEY, host_id INTEGER, graph_template_id INTEGER, snmp_index TEXT DEFAULT '', snmp_query_id INTEGER DEFAULT 0);
CREATE TABLE graph_templates_graph(local_graph_id INTEGER PRIMARY KEY, title_cache TEXT, width INTEGER, height INTEGER);
CREATE TABLE graph_templates(id INTEGER PRIMARY KEY, name TEXT);
CREATE TABLE host_template(id INTEGER PRIMARY KEY);
CREATE TABLE sites(id INTEGER PRIMARY KEY, name TEXT);
CREATE TABLE user_auth_row_cache(user_id INTEGER, class TEXT, hash TEXT, total_rows INTEGER, time TEXT, PRIMARY KEY(user_id,class,hash));
CREATE TABLE reports(id INTEGER PRIMARY KEY,user_id INTEGER);
CREATE TABLE reports_items(id INTEGER PRIMARY KEY,report_id INTEGER);
INSERT INTO graph_tree VALUES(100,'on','Visible'),(101,'','Disabled'),(102,'on','Other');
INSERT INTO graph_tree_items(id,graph_tree_id,parent,title,position) VALUES(11,100,0,'Parent',2),(12,100,11,'Child',1),(13,102,0,'Other tree',1);
INSERT INTO reports VALUES(1,42),(2,43); INSERT INTO reports_items VALUES(10,1),(20,2),(30,999);");
$db->sqliteCreateFunction('IF', static fn($condition, $yes, $no) => $condition ? $yes : $no);
$db->sqliteCreateFunction('UNIX_TIMESTAMP', static fn($value) => strtotime($value));
$db->sqliteCreateFunction('FROM_UNIXTIME', static fn($value) => gmdate('Y-m-d H:i:s', $value));
$db->exec("INSERT INTO plugin_realms VALUES (5, 'first.php,middle.php,last.php', 'Extension realm')");
foreach ($scenario['realms'] ?? [] as $user) {
    $db->prepare('INSERT INTO user_auth_realm VALUES (?, 21)')->execute([$user]);
}
foreach ($scenario['groups'] ?? [] as $index => $group) {
    $id = $index + 1;
    $db->prepare('INSERT INTO user_auth_group(id,enabled,show_tree,show_list,show_preview,graph_settings,policy_hosts,policy_graphs,policy_graph_templates) VALUES (?, ?, ?, ?, ?, ?, 1, 1, 1)')->execute(array_merge([$id, $group['enabled'] ?? 'on'], array_fill(0, 4, $group['view'] ?? '')));
    $db->prepare('INSERT INTO user_auth_group_members VALUES (?, ?)')->execute([$group['user'] ?? 42, $id]);
    $db->prepare('INSERT INTO user_auth_group_realm VALUES (?, 21)')->execute([$id]);
    $db->prepare('UPDATE user_auth_group SET policy_trees=? WHERE id=?')->execute([$group['tree_policy'] ?? 1, $id]);
    if ($scenario['operation'] === 'graphs') {
        $policy = $group['graph_policy'] ?? 2;
        $db->prepare('UPDATE user_auth_group SET policy_graphs=?,policy_hosts=?,policy_graph_templates=? WHERE id=?')->execute([$policy, $policy, $policy, $id]);
        foreach ($group['graph_exceptions'] ?? [] as $type) {
            $item = [1 => 100, 3 => 101, 4 => 102][$type];
            $db->prepare('INSERT INTO user_auth_group_perms VALUES (?, ?, ?)')->execute([$id, $type, $item]);
        }
    }
    foreach ($group['exceptions'] ?? [] as $type) {
        $db->prepare('INSERT INTO user_auth_group_perms VALUES (?, ?, 100)')->execute([$id, $type]);
    }
}
foreach ($scenario['exceptions'] ?? [] as $type) {
    $db->prepare('INSERT INTO user_auth_perms VALUES (42, ?, 100)')->execute([$type]);
}
$db->prepare('UPDATE user_auth SET policy_trees=? WHERE id=42')->execute([$scenario['tree_policy'] ?? 1]);
$_SESSION = $scenario['anonymous'] ?? false ? [] : ['sess_user_id' => 42];
$queries = 0;
$querySql = [];
$queryRowCounts = [];
$logs = [];
function db_fetch_assoc_prepared($sql, $params = [])
{
    if (($GLOBALS['scenario']['operation'] ?? '') === 'spike-controller' && str_contains($sql, 'SELECT DISTINCT data_template_rrd.local_data_id')) {
        $GLOBALS['spikeLookupParameters'][] = $params;
    }
    if (($GLOBALS['scenario']['operation'] ?? '') === 'graph-device-change' && !empty($GLOBALS['scenario']['read_failure']) && str_contains($sql, $GLOBALS['scenario']['read_failure'] === 'data' ? 'SELECT DISTINCT dtr.local_data_id' : 'SELECT DISTINCT pi.host_id')) {
        throw new RuntimeException('Fixture child metadata read failure.');
    }
    $GLOBALS['queries']++;
    $GLOBALS['querySql'][] = $sql;
    if (($GLOBALS['scenario']['operation'] ?? '') === 'graph-data-removal'
        && !empty($GLOBALS['scenario']['read_failure']) && str_contains($sql, $GLOBALS['scenario']['read_failure'])) {
        $GLOBALS['database_last_error'] = 'fixture read failure';
        return array();
    }
    // MySQL gives SELECT aliases precedence in GROUP BY; SQLite otherwise
    // resolves this normalizer's id against its joined input columns.
    if (($GLOBALS['scenario']['operation'] ?? '') === 'management-list' && str_contains($sql, 'GROUP BY id, name')) {
        $sql = str_replace('GROUP BY id, name', 'GROUP BY 1, 2', $sql);
    }
    if (($GLOBALS['scenario']['operation'] ?? '') === 'management-list') $sql = str_replace(' RLIKE ', ' REGEXP ', $sql);
    $q = $GLOBALS['db']->prepare($sql);
    $q->execute($params);
    $rows = $q->fetchAll(PDO::FETCH_ASSOC);
    $bulk = ($GLOBALS['scenario']['operation'] ?? '') === 'management-bulk';
    $eligibility = str_starts_with($sql, 'SELECT id FROM graph_local ') || str_starts_with($sql, 'SELECT id FROM data_local ') || str_starts_with($sql, 'SELECT id FROM host ');
    $GLOBALS['queryRowCounts'][] = ['choices' => str_contains($sql, 'data_template_rrd'), 'rows' => count($rows), 'bulk_eligibility' => $bulk && $eligibility];
    if ($bulk && str_starts_with($sql, 'SELECT description FROM host WHERE id')) $GLOBALS['bulkFixture']['title_ids'][] = (int) $params[0];
    if ($bulk && $eligibility) {
        management_bulk_fixture_after_query($sql);
        $failure = $GLOBALS['scenario']['read_failure'] ?? 0;
        $eligibilityCount = count(array_filter($GLOBALS['queryRowCounts'], static fn(array $row): bool => $row['bulk_eligibility']));
        if ($failure && ($failure === true || $eligibilityCount === $failure)) {
            $GLOBALS['database_last_error'] = 'fixture late eligibility failure';
            return [];
        }
    }
    return $rows;
}
function db_fetch_row_prepared($sql, $params = [])
{
    $rows = db_fetch_assoc_prepared($sql, $params);
    return $rows[0] ?? [];
}
function db_fetch_cell_prepared($sql, $params = [])
{
    if (!empty($GLOBALS['graphCacheReadFailure']) && str_contains($sql, 'SELECT reset_perms')) return false;
    $rows = db_fetch_assoc_prepared($sql, $params);
    return $rows ? reset($rows[0]) : false;
}
function db_fetch_assoc($sql)
{
    return db_fetch_assoc_prepared($sql);
}
function db_fetch_cell($sql)
{
    return db_fetch_cell_prepared($sql);
}
function db_execute_prepared($sql, $params = [])
{
    if (($GLOBALS['scenario']['operation'] ?? '') === 'graph-device-change') $GLOBALS['graphDeviceWrites'][] = [$sql, $params];
    if (($GLOBALS['scenario']['operation'] ?? '') === 'graph-data-removal') {
        return graph_data_removal_fixture_execute($sql, $params, func_get_args()[3] ?? false);
    }
    $query = $GLOBALS['db']->prepare($sql);
    return $query->execute($params);
}
function kill_session_var($name)
{
    unset($_SESSION[$name]);
}
function get_guest_account()
{
    return ($GLOBALS['scenario']['actor'] ?? '') === 'guest' ? 42 : 0;
}
function db_table_exists($name)
{
    return (bool) db_fetch_cell_prepared('SELECT 1 FROM sqlite_master WHERE type = ? AND name = ?', ['table', $name]);
}
function read_config_option($name)
{
    return $name === 'auth_method' ? ($GLOBALS['scenario']['auth_method'] ?? 1) : ($GLOBALS['scenario']['config'][$name] ?? '');
}
function read_user_setting($name, ...$args)
{
    return $name === 'hide_disabled' ? ($GLOBALS['scenario']['hide_disabled'] ?? '') : '';
}
function cacti_version_compare($left, $right, $operator)
{
    return version_compare($left, $right, $operator);
}
function array_rekey($rows, $key, $value)
{
    if (is_array($value)) {
        $result = array();
        foreach ($rows as $row) $result[$row[$key]] = array_intersect_key($row, array_flip($value));
        return $result;
    }
    return array_column($rows, $value, $key);
}
function cacti_sizeof($rows)
{
    return is_countable($rows) ? count($rows) : 0;
}
function cacti_log($message, ...$args)
{
    $GLOBALS['logs'][] = $message;
}
if (isset($argv[3])) {
    define('RRD_TEST_COVERAGE_DIRECTORY', $argv[2]);
    define('AUTH_POLICY_TEST_COVERAGE', true);
    require __DIR__ . '/rrd-process-coverage.php';
}
require $root . '/lib/auth.php';
$result = null;
$cached = null;
switch ($scenario['operation']) {
    case 'graph-policy-display':
        $result = graph_policy_display_fixture_run();
        break;
    case 'spike-controller':
        spike_controller_fixture_run();
        break;
    case 'management-list':
        management_list_fixture_run();
        break;
    case 'management-bulk':
        management_bulk_fixture_run();
        break;
    case 'graph-cache-revocation':
    case 'graph-image-cache':
        $result = graph_cache_fixture_run($scenario);
        break;
    case 'graph-data-removal':
        graph_data_removal_fixture_run();
        break;
    case 'graph-item-choices':
        require_once $root . '/tests/Helpers/PhpSource.php';
        $apiSource = file_get_contents($root . '/lib/api_data_source.php');
        if ($apiSource === false) throw new RuntimeException('Cannot read data-source authorization contract');
        eval(test_php_function_source($apiSource, 'api_data_source_is_allowed'));
        $db->exec("CREATE TABLE data_local(id INTEGER PRIMARY KEY, host_id INTEGER);
CREATE TABLE data_template_data(local_data_id INTEGER PRIMARY KEY,name_cache TEXT);
CREATE TABLE data_template_rrd(id INTEGER PRIMARY KEY,local_data_id INTEGER,data_source_name TEXT);
INSERT INTO host(id,description,disabled) VALUES(100,'Allowed','on'),(101,'Foreign',''),(102,'Unattached','');
INSERT INTO graph_local(id,host_id,graph_template_id) VALUES(100,100,100),(101,101,101);
INSERT INTO graph_templates VALUES(100,'Allowed'),(101,'Foreign');
INSERT INTO data_local VALUES(10,100),(11,101),(12,0),(13,102);
INSERT INTO data_template_data VALUES(10,'Allowed source'),(11,'Foreign source'),(12,'Free source'),(13,'Unattached source');
INSERT INTO data_template_rrd VALUES(20,10,'rate'),(21,11,'rate'),(22,12,'rate'),(23,13,'rate');");
        if (empty($scenario['no_realm'])) {
            $db->exec('INSERT INTO user_auth_realm VALUES(42,5)');
        }
        $db->exec('UPDATE user_auth SET policy_hosts=2,policy_graphs=2,policy_graph_templates=2 WHERE id=42');
        foreach ([3,4] as $type) {
            $db->prepare('INSERT INTO user_auth_perms VALUES(42,?,102)')->execute([$type]);
        }
        if (!empty($scenario['group_grant'])) {
            $db->exec("INSERT INTO user_auth_group(id,enabled,policy_hosts,policy_graphs,policy_graph_templates) VALUES(9,'on',2,2,2);
INSERT INTO user_auth_group_members VALUES(42,9);
INSERT INTO user_auth_group_perms VALUES(9,3,100),(9,4,100);");
        } else {
            $db->exec('INSERT INTO user_auth_perms VALUES(42,3,100),(42,4,100)');
        }
        for ($i = 0; $i < ($scenario['device_count'] ?? 0); $i++) {
            $db->prepare('INSERT INTO host(id,description) VALUES(?,?)')->execute([1000 + $i, 'Inventory ' . $i]);
        }
        for ($i = 0; $i < ($scenario['choice_count'] ?? 0); $i++) {
            $id = 1000 + $i;
            $db->prepare('INSERT INTO data_local VALUES(?,100)')->execute([$id]);
            $db->prepare('INSERT INTO data_template_data VALUES(?,?)')->execute([$id, sprintf('Many %05d', $i)]);
            $db->prepare('INSERT INTO data_template_rrd VALUES(?, ?,?)')->execute([$id,$id,'rate']);
        }
        if (!empty($scenario['allow_inventory'])) {
            $db->exec('UPDATE user_auth SET policy_hosts=1 WHERE id=42; DELETE FROM user_auth_perms WHERE user_id=42 AND type=3; INSERT INTO user_auth_perms VALUES(42,3,101)');
        }
        $db->sqliteCreateFunction('CONCAT_WS', static fn($separator, ...$parts) => implode($separator, array_filter($parts, static fn($part) => $part !== null)));
        function get_nfilter_request_var($name)
        {
            return $GLOBALS['scenario']['choices_request'][$name] ?? '';
        }
        function db_qstr($value)
        {
            return $GLOBALS['db']->quote($value);
        }
        function __esc($value)
        {
            return htmlspecialchars($value, ENT_QUOTES);
        }
        require $root . '/lib/graph_item_choices.php';
        $before = $queries;
        $querySql = [];
        $queryRowCounts = [];
        $choices = graph_item_choices();
        $choiceQueries = $queries - $before;
        $choiceSql = $querySql;
        $policyRowCounts = array_column(array_filter($queryRowCounts, static fn($query) => !$query['choices']), 'rows');
        $admitted = [];
        foreach ([10,11,12,13] as $id) {
            $admitted[$id] = api_data_source_is_allowed($id);
        }
        $result = ['choices' => $choices, 'status' => http_response_code() ?: 200, 'queries' => $choiceQueries,
            'protected_reads' => count(array_filter($choiceSql, static fn($sql) => str_contains($sql, 'data_template_rrd'))),
            'max_policy_rows' => $policyRowCounts === [] ? 0 : max($policyRowCounts),
            'device_inventory_reads' => count(array_filter($choiceSql, static fn($sql) => str_contains($sql, 'SELECT h1.*'))),
            'count_reads' => count(array_filter($choiceSql, static fn($sql) => str_contains($sql, 'COUNT(DISTINCT id)') && !str_contains($sql, ' h.id = '))),
            'admitted' => $admitted];
        unset($before);
        break;
    case 'resource-ids':
    case 'device-filter-policy':
        $db->exec("INSERT INTO host(id,description,disabled,deleted) VALUES(100,'Target','on',''),(101,'Denied','on',''),(102,'Deleted','','on')");
        $db->exec("INSERT INTO graph_local(id,host_id,graph_template_id) VALUES(100,100,0),(101,101,0),(102,102,0); INSERT INTO graph_templates_graph VALUES(100,'Target',100,100),(101,'Foreign',100,100),(102,'Deleted',100,100)");
        if ($scenario['operation'] === 'resource-ids') {
            $before = $queries;
            $refused = [];
            $invalid_lists = [];
            foreach ([0, -1, '', [], 1.5, '1e2', '100a', (string) PHP_INT_MAX . '0', null, false] as $id) {
                try {
                    $refused[] = [is_device_allowed($id, 42), is_graph_allowed($id, 42)];
                    if ($id !== 0) {
                        $device_rows = $graph_rows = -2;
                        $invalid_lists[] = [get_allowed_devices('', '', '', $device_rows, 42, $id), $device_rows, get_allowed_graphs('', '', '', $graph_rows, 42, $id), $graph_rows];
                    }
                } catch (Throwable $error) {
                    $refused[] = get_class($error);
                }
            }
            $invalid_queries = $queries - $before;
            $admitted = [];
            foreach ([100, '100', '0100', '100 '] as $id) {
                $admitted[] = [is_device_allowed($id, 42), is_graph_allowed($id, 42)];
            }
            $result = ['refused' => $refused, 'invalid_lists' => $invalid_lists, 'invalid_queries' => $invalid_queries, 'admitted' => $admitted];
            break;
        }
        $total = -1;
        $visible = get_allowed_devices('', 'description', '', $total, 42);
        $visible_graphs = get_allowed_graphs('', '', '', $total, 42);
        $management = get_allowed_management_devices('', 'h1.id', '', $total, 42);
        $result = ['view' => array_column($visible, 'id'), 'graph_view' => array_column($visible_graphs, 'local_graph_id'), 'management' => array_column($management, 'id'), 'target' => is_device_allowed(100, 42), 'foreign' => is_device_allowed(101, 42), 'deleted' => is_device_allowed(102, 42), 'missing' => is_device_allowed(999, 42), 'graphs' => [is_graph_allowed(100, 42), is_graph_allowed(101, 42), is_graph_allowed(102, 42), is_graph_allowed(999, 42)]];
        break;
    case 'cache-owner-isolation':
        $db->exec('UPDATE user_auth SET policy_graphs=1,policy_graph_templates=1,policy_trees=1 WHERE id=42');
        $db->exec('UPDATE user_auth SET policy_graphs=2,policy_graph_templates=2,policy_trees=2 WHERE id=43');
        $answers = static fn(int $user): array => array(is_tree_allowed(100, $user), get_simple_graph_perms($user), get_simple_graph_template_perms($user));
        $result = array($answers(42), $answers(43), $answers(42));
        $db->exec('UPDATE user_auth SET policy_graphs=1,policy_graph_templates=1,policy_trees=1,reset_perms=1 WHERE id=43');
        $result[] = $answers(43);
        $result[] = $answers(42);
        break;
    case 'graphs':
        // Persist the graph/host/template relationships used by the production
        // query. Distinct IDs catch a permission joined to the wrong resource.
        $db->exec("INSERT INTO host(id,description) VALUES(101,'Fixture host');
INSERT INTO graph_templates VALUES(102,'Fixture template');
INSERT INTO graph_local VALUES(100,101,102,'interface',1);
INSERT INTO graph_templates_graph VALUES(100,'Fixture graph',500,120);");
        $db->sqliteCreateFunction('IF', static fn($condition, $yes, $no) => $condition ? $yes : $no);
        foreach ($scenario['graph_exceptions'] ?? [] as $type) {
            $item = [1 => 100, 3 => 101, 4 => 102][$type];
            $db->prepare('INSERT INTO user_auth_perms VALUES (42, ?, ?)')->execute([$type, $item]);
        }
        $where = get_policy_where($scenario['config']['graph_auth_method'], get_policies(42), '');
        $query = 'SELECT gl.id FROM graph_local AS gl LEFT JOIN host AS h ON h.id=gl.host_id ' . $where;
        $total = 0;
        $rows = get_allowed_graphs('', '', '', $total, 42, 100);
        $result = [
            'policy_rows' => array_column(db_fetch_assoc($query), 'id'),
            'allowed_rows' => array_column($rows, 'local_graph_id'),
            'total' => $total,
            'allowed' => is_graph_allowed(100, 42),
            'missing' => is_graph_allowed(999, 42),
        ];
        break;
    case 'branch':
        if (!empty($scenario['graph'])) {
            $db->exec('UPDATE graph_tree_items SET local_graph_id=100 WHERE id=12');
        }
        if (!empty($scenario['site'])) {
            $db->exec('UPDATE graph_tree_items SET site_id=500 WHERE id=12');
        }
        $result = [is_tree_branch_empty(100), is_tree_branch_empty(100, 11), is_tree_branch_empty(999)];
        break;
    case 'tree-content':
        if (!empty($scenario['graph'])) {
            $db->exec('UPDATE graph_tree_items SET local_graph_id=100 WHERE id=12');
        }
        if (!empty($scenario['site'])) {
            $db->exec('UPDATE graph_tree_items SET site_id=500 WHERE id=11');
        }
        $total = 0;
        $result = get_allowed_tree_content(100, 0, '', '', '', $total, 42);
        break;
    case 'tree-level':
        $result = get_allowed_tree_level(100, $scenario['parent'] ?? 0, $scenario['editing'] ?? false, 42);
        break;
    case 'trees':
        $total = 0;
        $result = ['rows' => get_allowed_trees(false, false, '', 'name', '', $total, 42), 'total' => $total];
        break;
    case 'row-cache':
        $sql = !empty($scenario['failure']) ? 'SELECT COUNT(*) FROM nonexistent_table' : 'SELECT COUNT(*) FROM graph_tree WHERE enabled = ?';
        $db->exec("INSERT INTO user_auth_row_cache VALUES(43,'foreign','foreign',77,'2000-01-01 00:00:00')");
        try {
            $first = get_total_row_data(42, $sql, ['on'], 'tree-test');
            $db->exec("INSERT INTO graph_tree VALUES(103,'on','Later')");
            $cached = get_total_row_data(42, $sql, ['on'], 'tree-test');
            $db->exec("UPDATE user_auth_row_cache SET time='2000-01-01 00:00:00' WHERE user_id=42");
            $refreshed = get_total_row_data(42, $sql, ['on'], 'tree-test');
            $result = ['first' => $first, 'cached' => $cached, 'refreshed' => $refreshed];
        } catch (PDOException $exception) {
            $result = ['error' => get_class($exception)];
        }
        $result['stored'] = $db->query('SELECT user_id,class,total_rows FROM user_auth_row_cache ORDER BY user_id')->fetchAll(PDO::FETCH_ASSOC);
        break;
    case 'ownership':
        $result = cacti_authorize_resource($scenario['user'] ?? 42, $scenario['resource'], $scenario['type']);
        break;
    case 'revoked-account':
        $db->exec("CREATE TABLE user_auth_cache(user_id INTEGER,token TEXT); INSERT INTO user_auth_cache VALUES(42,'target'),(43,'foreign'); UPDATE user_auth SET reset_perms=5 WHERE id=42");
        if (!empty($scenario['disabled'])) {
            $db->exec("UPDATE user_auth SET enabled='' WHERE id=42");
        }
        $_SESSION += ['sess_user_perms_key' => 0, 'sess_user_realms' => [21 => true], 'sess_user_config_array' => ['stale'], 'sess_config_array' => ['stale'], 'sess_auth_names' => ['stale'], 'sess_tree_perms' => [100 => true], 'sess_simple_perms' => true, 'sess_simple_template_perms' => true];
        ob_start();
        register_shutdown_function(static function () use ($db) {
            $state = ['output' => ob_get_clean(), 'session' => $_SESSION, 'tokens' => $db->query('SELECT user_id,token FROM user_auth_cache ORDER BY user_id')->fetchAll(PDO::FETCH_ASSOC)];
            $GLOBALS['nativeChildCoverageMarkers'] = ['permission-revocation-shutdown', 'credential-readback'];
            print json_encode($state, JSON_THROW_ON_ERROR);
        });
        is_realm_allowed(21);
        throw new RuntimeException('Revoked credentials did not take their existing response exit.');
    case 'realm':
        $result = is_realm_allowed(21, $scenario['check_user'] ?? false);
        $before = $queries;
        $db->exec('DELETE FROM user_auth_realm');
        $db->exec('DELETE FROM user_auth_group_realm');
        $cached = is_realm_allowed(21, $scenario['check_user'] ?? false);
        break;
    case 'tree':
        $result = is_tree_allowed(100);
        $before = $queries;
        $db->exec('DELETE FROM user_auth_perms');
        $db->exec('DELETE FROM user_auth_group_perms');
        $cached = is_tree_allowed(100);
        break;
    case 'graph-device-change':
        $result = native_graph_device_change();
        break;
    case 'policies':
        $result = get_policies(42);
        break;
    case 'view':
        $result = is_view_allowed($scenario['view'] ?? 'show_tree');
        break;
    case 'roles':
        $user_auth_roles = ['extension' => [7]];
        $user_auth_realm_filenames = [];
        auth_augment_roles('extension', ['first.php', 'middle.php', 'last.php', 'unknown.php']);
        auth_augment_roles_byname('extension', 'Extension realm');
        auth_augment_roles_byname('new', 'Extension realm');
        auth_augment_roles_byname('missing', 'Unknown realm');
        $before = $queries;
        $db->exec('DELETE FROM plugin_realms');
        auth_augment_roles('extension', ['first.php', 'middle.php', 'last.php']);
        auth_augment_roles_byname('extension', 'Extension realm');
        $result = $user_auth_roles;
        break;
    case 'simple':
        $result = [get_simple_device_perms(42), get_simple_graph_perms(42), get_simple_graph_template_perms(42)];
        $before = $queries;
        $db->exec('UPDATE user_auth SET policy_graphs = 2, policy_graph_templates = 2 WHERE id = 42');
        $cached = [get_simple_graph_perms(42), get_simple_graph_template_perms(42)];
        break;
    default:
        throw new InvalidArgumentException('Unknown policy operation.');
}
$nativeChildCoverageMarkers = array('native-policy-operation-returned', 'policy-session-observed');
if ($scenario['operation'] === 'graph-policy-display') $nativeChildCoverageMarkers[] = 'persisted-policy-display-compared';
if ($scenario['operation'] === 'graph-device-change') $nativeChildCoverageMarkers[] = 'graph-device-child-scope-observed';
if (in_array($scenario['operation'], ['graph-cache-revocation', 'graph-image-cache'], true)) {
    $nativeChildCoverageMarkers = array_merge($nativeChildCoverageMarkers, ['graph-cache-revocation-observed', 'graph-cache-query-budget-observed']);
    if ($scenario['operation'] === 'graph-image-cache') $nativeChildCoverageMarkers[] = 'graph-cache-image-dispatch-observed';
}
if ($scenario['operation'] === 'graph-item-choices') {
    $nativeChildCoverageMarkers = array_merge($nativeChildCoverageMarkers, ['graph-choice-policy-returned', 'graph-choice-query-budget-observed']);
}
print json_encode(['result' => $result, 'cached' => $cached, 'session' => $_SESSION, 'extra_queries' => isset($before) ? $queries - $before : null, 'logs' => $logs], JSON_THROW_ON_ERROR);
