<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

$root = dirname(__DIR__, 2);
require $root . '/include/vendor/autoload.php';
require $root . '/lib/cdef_reference.php';
require $root . '/lib/database.php';
require $root . '/lib/functions.php';
require $root . '/lib/plugins.php';
require $root . '/lib/auth.php';
require $root . '/include/global_constants.php';
require $root . '/lib/import.php';
require $root . '/lib/api_aggregate.php';
require $root . '/lib/api_graph.php';
require $root . '/lib/aggregate.php';
require $root . '/lib/variables.php';
require $root . '/lib/html.php';
require $root . '/lib/html_utility.php';
require $root . '/lib/html_form.php';
require $root . '/lib/headers_secure.php';
require $root . '/tests/Helpers/PhpSource.php';
eval(test_php_function_source(file_get_contents($root . '/tests/security/cdef_reference_installer_native_probe.php'), 'installerSeed'));
define('IN_CACTI_INSTALL', 1);
define('CACTI_VERSION', trim(file_get_contents($root . '/include/cacti_version')));
// Use the production gettext fallback and legacy helpers; no function or SQL
// replacements. These explicit settings only disable logging/browser behavior.
$config = ['base_path' => $root, 'library_path' => $root . '/lib', 'include_path' => $root . '/include', 'is_web' => false, 'poller_id' => 1, 'url_path' => '/fixture/', 'cacti_server_os' => strtolower(PHP_OS_FAMILY),
    'config_options_array' => ['i18n_language_support' => '0', 'i18n_log' => '0', 'selective_debug' => '',
        'log_verbosity' => '0', 'log_destination' => '0', 'path_cactilog' => '/dev/null', 'client_timezone_support' => '0',
        'path_spine' => '', 'reports_allow_ln' => '', 'auth_method' => '0'],
];
$_SESSION = [];
require $root . '/include/global_languages.php';
require $root . '/include/global_arrays.php';
require $root . '/include/global_form.php';
// Resource authorization reads actual user-setting defaults while checking graphs.
$no_http_header_files = []; // This isolated CLI probe has no HTTP logging entrypoints.
require $root . '/include/global_settings.php';
$_SESSION['sess_user_id'] = 1;
// xml_to_cdef only reads the name from the production CDEF form field schema.
$fields_cdef_edit = ['name' => ['method' => 'textbox']];
$preview_only = false;
$import_debug_info = [];
$import_messages = [];
$database_hostname = 'isolated-fixture';
$database_port = 0;
$database_default = 'isolated-fixture';

function callerAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
    echo "PASS $message\n";
}

function graphApiControls(PDO $database, PDO $observer): void
{
    // These expectations follow the original signed graph API: local copies
    // share data sources, template copies remap input definitions and SNMP
    // associations, and suggested titles retain their substitution expression.
    api_resize_graphs(15000002, 777, 333);
    callerAssert($observer->query('SELECT width,height FROM graph_templates_graph WHERE local_graph_id=15000002')->fetch(PDO::FETCH_NUM) === [777, 333], 'graph resize persists exact requested dimensions');
    callerAssert((int) $observer->query('SELECT width FROM graph_templates_graph WHERE local_graph_id=15000003')->fetchColumn() === 640, 'graph resize leaves the sibling unchanged');
    $lookup = api_get_graphs_from_datasource(15000002);
    // The legacy lookup has no ORDER BY; its contract is the ID/name mapping.
    ksort($lookup);
    callerAssert($lookup === [15000001 => 'Measured aggregate', 15000002 => 'Member A'], 'data-source lookup returns distinct original graph IDs and title caches');
    callerAssert(api_get_graphs_from_datasource(16000999) === [], 'unknown data source has no related graphs');
    $copy = api_duplicate_graph(15000002, 0, 'Copy <graph_title>');
    callerAssert((int) $copy > 0 && (int) $copy !== 15000002, 'local graph copy returns a distinct persisted graph');
    $sourceItems = $observer->query('SELECT task_item_id,sequence,graph_type_id,text_format FROM graph_templates_item WHERE local_graph_id=15000002 ORDER BY sequence')->fetchAll(PDO::FETCH_ASSOC);
    $read = $observer->prepare('SELECT task_item_id,sequence,graph_type_id,text_format FROM graph_templates_item WHERE local_graph_id=? ORDER BY sequence');
    $read->execute([$copy]);
    callerAssert($read->fetchAll(PDO::FETCH_ASSOC) === $sourceItems, 'local graph copy preserves exact item ordering and shared data-source handoff');
    $read = $observer->prepare('SELECT title,title_cache FROM graph_templates_graph WHERE local_graph_id=?');
    $read->execute([$copy]);
    callerAssert($read->fetch(PDO::FETCH_ASSOC) === ['title' => 'Copy Member A', 'title_cache' => 'Copy Member A'], 'local graph copy expands the original title and refreshes its cache');
    callerAssert(api_duplicate_graph(16000999, 0, 'missing') === false, 'missing local graph copy refuses without a new parent');

    $database->exec("INSERT INTO graph_templates(id,name,multiple) VALUES(16000001,'Native template','on')");
    $database->exec("INSERT INTO graph_templates_graph(graph_template_id,local_graph_id,title,title_cache,width,height) VALUES(16000001,0,'Template title','Template title',555,222)");
    $database->exec("INSERT INTO graph_templates_item(graph_template_id,local_graph_id,task_item_id,sequence,graph_type_id,text_format,color_id) VALUES(16000001,0,15000101,1,4,'Template item',1)");
    $originalItem = (int) $database->lastInsertId();
    $database->exec("INSERT INTO graph_template_input(id,graph_template_id,name,description,column_name) VALUES(16000001,16000001,'Native color','Native input','color_id')");
    $database->exec("INSERT INTO graph_template_input_defs(graph_template_input_id,graph_template_item_id) VALUES(16000001,$originalItem)");
    $database->exec("INSERT INTO snmp_query_graph(id,snmp_query_id,name,graph_template_id) VALUES(50001,1,'Native query',16000001)");
    $database->exec("INSERT INTO snmp_query_graph_rrd(snmp_query_graph_id,data_template_id,data_template_rrd_id,snmp_field_name) VALUES(50001,1,15000101,'ifInOctets')");
    $database->exec("INSERT INTO snmp_query_graph_rrd_sv(snmp_query_graph_id,data_template_id,sequence,field_name,text) VALUES(50001,1,2,'name','Native data |query_ifDescr|')");
    $database->exec("INSERT INTO snmp_query_graph_sv(snmp_query_graph_id,sequence,field_name,text) VALUES(50001,1,'title','|query_missing|'),(50001,2,'title','Resolved |query_ifDescr|')");
    $templateCopy = (int) api_duplicate_graph(0, 16000001, 'Copy <template_title>');
    callerAssert($templateCopy > 0 && $templateCopy !== 16000001, 'template copy returns a new template identity');
    $read = $observer->prepare('SELECT name,multiple FROM graph_templates WHERE id=?');
    $read->execute([$templateCopy]);
    callerAssert($read->fetch(PDO::FETCH_ASSOC) === ['name' => 'Copy Native template', 'multiple' => 'on'], 'template copy preserves multiple mode and expands its original name');
    $read = $observer->prepare('SELECT width,height FROM graph_templates_graph WHERE graph_template_id=? AND local_graph_id=0');
    $read->execute([$templateCopy]);
    callerAssert($read->fetch(PDO::FETCH_NUM) === [555, 222], 'template copy preserves original graph dimensions');
    $read = $observer->prepare('SELECT gi.column_name,gi.name,gti.graph_template_id,gti.sequence FROM graph_template_input gi INNER JOIN graph_template_input_defs gid ON gid.graph_template_input_id=gi.id INNER JOIN graph_templates_item gti ON gti.id=gid.graph_template_item_id WHERE gi.graph_template_id=?');
    $read->execute([$templateCopy]);
    callerAssert($read->fetch(PDO::FETCH_ASSOC) === ['column_name' => 'color_id', 'name' => 'Native color', 'graph_template_id' => $templateCopy, 'sequence' => 1], 'template copy remaps input definitions to the copied child item');
    $queryCopy = $observer->prepare('SELECT id,name FROM snmp_query_graph WHERE graph_template_id=?');
    $queryCopy->execute([$templateCopy]);
    $mappedQuery = $queryCopy->fetch(PDO::FETCH_ASSOC);
    callerAssert(is_array($mappedQuery) && $mappedQuery['name'] === 'Copy Native template' && (int) $mappedQuery['id'] !== 50001, 'template copy remaps the associated data query');
    $read = $observer->prepare('SELECT data_template_id,data_template_rrd_id,snmp_field_name FROM snmp_query_graph_rrd WHERE snmp_query_graph_id=?');
    $read->execute([$mappedQuery['id']]);
    callerAssert($read->fetch(PDO::FETCH_ASSOC) === ['data_template_id' => 1, 'data_template_rrd_id' => 15000101, 'snmp_field_name' => 'ifInOctets'], 'copied query retains exact data-source mapping');
    $read = $observer->prepare('SELECT sequence,field_name,text FROM snmp_query_graph_rrd_sv WHERE snmp_query_graph_id=?');
    $read->execute([$mappedQuery['id']]);
    callerAssert($read->fetch(PDO::FETCH_ASSOC) === ['sequence' => 2, 'field_name' => 'name', 'text' => 'Native data |query_ifDescr|'], 'copied query retains data-source suggested values');
    $read = $observer->prepare('SELECT sequence,field_name,text FROM snmp_query_graph_sv WHERE snmp_query_graph_id=? ORDER BY sequence');
    $read->execute([$mappedQuery['id']]);
    callerAssert($read->fetchAll(PDO::FETCH_ASSOC) === [['sequence' => 1, 'field_name' => 'title', 'text' => '|query_missing|'], ['sequence' => 2, 'field_name' => 'title', 'text' => 'Resolved |query_ifDescr|']], 'copied query retains ordered graph suggested values');
    $withoutQuery = (int) api_duplicate_graph(0, 16000001, 'Unmapped <template_title>', false);
    callerAssert($withoutQuery > 0 && (int) $observer->query("SELECT COUNT(*) FROM snmp_query_graph WHERE graph_template_id=$withoutQuery")->fetchColumn() === 0, 'template copy can explicitly omit data-query associations');
    $database->exec("UPDATE graph_template_input SET column_name='not_a_column' WHERE id=16000001");
    $before = (int) $observer->query('SELECT COUNT(*) FROM graph_templates')->fetchColumn();
    callerAssert(api_duplicate_graph(0, 16000001, 'Invalid copy') === false && (int) $observer->query('SELECT COUNT(*) FROM graph_templates')->fetchColumn() === $before, 'invalid input column refuses a template clone before a parent write');
    callerAssert(api_duplicate_graph(0, 16000999, 'Missing copy') === false, 'missing template clone refuses');

    $database->exec("INSERT INTO graph_local(id,graph_template_id,host_id,snmp_query_id,snmp_query_graph_id,snmp_index) VALUES(16000101,16000001,1,1,50001,'native'),(16000102,0,0,0,0,''),(16000103,1,1,0,0,'')");
    $database->exec("INSERT INTO graph_templates_graph(local_graph_id,graph_template_id,title,title_cache) VALUES(16000101,16000001,'Old title','Old title'),(16000102,0,'No template','No template'),(16000103,1,'Simple title','Simple title')");
    $database->exec("INSERT INTO host_snmp_cache(host_id,snmp_query_id,field_name,field_value,snmp_index,oid) VALUES(1,1,'ifDescr','Native interface','native','1.3.6.1.2.1.2.2.1.2.1')");
    callerAssert(api_reapply_suggested_graph_title(16000101) === true && $observer->query('SELECT title FROM graph_templates_graph WHERE local_graph_id=16000101')->fetchColumn() === 'Resolved |query_ifDescr|', 'suggested title skips unresolved candidates and stores the original resolvable expression');
    callerAssert(api_reapply_suggested_graph_title(16000102) === null && api_reapply_suggested_graph_title(16000103) === null && api_reapply_suggested_graph_title(16000999) === null, 'non-template simple and missing graphs do not reapply a suggested title');
    $database->exec('DELETE FROM snmp_query_graph_sv WHERE snmp_query_graph_id=50001');
    callerAssert(api_reapply_suggested_graph_title(16000101) === false, 'data-query graph without suggested values reports no title match');
    $database->exec("INSERT INTO data_local(id,host_id) VALUES(15000002,1)");
    $database->exec("INSERT INTO poller_item(local_data_id,host_id,rrd_name) VALUES(15000002,1,'fixture')");
    callerAssert(api_graph_change_device(15000002, 0) === true, 'simple graph admits an actual device change without querying a device');
    callerAssert((int) $observer->query('SELECT host_id FROM graph_local WHERE id=15000002')->fetchColumn() === 0 && (int) $observer->query('SELECT host_id FROM data_local WHERE id=15000002')->fetchColumn() === 0 && (int) $observer->query('SELECT host_id FROM poller_item WHERE local_data_id=15000002')->fetchColumn() === 0, 'device change hands the new identity to graph data and poller rows');
    callerAssert(api_graph_change_device(16000101, 0) === false && (int) $observer->query('SELECT host_id FROM graph_local WHERE id=16000101')->fetchColumn() === 1, 'data-query graph refuses device reassignment and preserves its host');
    callerAssert(api_graph_change_device(16000103, 0) === true && $observer->query('SELECT title_cache FROM graph_templates_graph WHERE local_graph_id=16000103')->fetchColumn() === 'Simple title', 'device change without data-source children preserves the literal title cache');
    $database->exec("INSERT INTO graph_templates_graph(local_graph_id,graph_template_id,title,title_cache) VALUES(16000106,16000001,'Orphan fixture','Orphan fixture')");
    callerAssert(api_reapply_suggested_graph_title(16000106) === null, 'suggested title refuses a missing graph-local parent');

    $database->exec("INSERT INTO data_template_data(local_data_id,name,name_cache) VALUES(15000002,'Task-owned source','Task-owned source')");
    try {
        $reviewed = [$copy];
        api_delete_graphs($reviewed, '2', [], static function (): void {});
        throw new RuntimeException('Widened data-source scope was accepted');
    } catch (RuntimeException $error) {
        callerAssert($error->getMessage() === 'Graph data-source scope changed' && (int) $observer->query("SELECT COUNT(*) FROM graph_local WHERE id=$copy")->fetchColumn() === 1 && (int) $observer->query('SELECT COUNT(*) FROM data_template_rrd WHERE id=15000102')->fetchColumn() === 1, 'reviewed removal refuses unconfirmed shared data-source scope before graph or source deletion');
    }
    api_graph_remove($copy);
    callerAssert((int) $observer->query("SELECT COUNT(*) FROM graph_local WHERE id=$copy")->fetchColumn() === 0 && (int) $observer->query("SELECT COUNT(*) FROM graph_templates_item WHERE local_graph_id=$copy")->fetchColumn() === 0, 'single graph removal deletes only its own persisted graph and items');
    callerAssert((int) $observer->query('SELECT COUNT(*) FROM data_template_rrd WHERE id=15000102')->fetchColumn() === 1, 'single graph removal preserves the shared data source');
    $verified = 0;
    api_graph_remove_multi([16000102, 16000103], true, static function () use (&$verified): void {
        $verified++;
    });
    callerAssert($verified === 1 && (int) $observer->query('SELECT COUNT(*) FROM graph_local WHERE id IN(16000102,16000103)')->fetchColumn() === 0, 'reviewed multi removal checks scope before removing the exact cohort');
    $batch = range(16002000, 16003000);
    $database->exec('INSERT INTO graph_local(id) VALUES(' . implode('),(', $batch) . ')');
    foreach ([16002000, 16002999, 16003000] as $id) {
        $database->exec("INSERT INTO graph_templates_graph(local_graph_id,title,title_cache) VALUES($id,'Batch child','Batch child')");
        $database->exec("INSERT INTO graph_templates_item(local_graph_id,sequence,graph_type_id,text_format) VALUES($id,1,1,'Batch comment')");
        $database->exec("INSERT INTO graph_tree_items(local_graph_id) VALUES($id)");
        $database->exec("INSERT INTO reports_items(local_graph_id,item_text) VALUES($id,'Task-owned report child')");
    }
    api_graph_remove_multi($batch);
    callerAssert((int) $observer->query('SELECT COUNT(*) FROM graph_local WHERE id BETWEEN 16002000 AND 16003000')->fetchColumn() === 0, 'multi removal deletes the complete thousand-item batch and its trailing cohort');
    foreach (['graph_templates_graph', 'graph_templates_item', 'graph_tree_items', 'reports_items'] as $table) {
        callerAssert((int) $observer->query("SELECT COUNT(*) FROM $table WHERE local_graph_id BETWEEN 16002000 AND 16003000")->fetchColumn() === 0, "multi removal deletes exact first full-batch and trailing $table dependents");
    }
    try {
        api_graph_remove_multi([15000002], true, static function (): void {
            throw new RuntimeException('Task-owned reviewed scope refusal');
        });
        throw new RuntimeException('Reviewed refusal was ignored');
    } catch (RuntimeException $error) {
        callerAssert($error->getMessage() === 'Task-owned reviewed scope refusal' && (int) $observer->query('SELECT COUNT(*) FROM graph_local WHERE id=15000002')->fetchColumn() === 1, 'reviewed scope failure preserves the requested graph before deletion');
    }
    $ids = [0];
    api_graph_remove_bad_graphs($ids);
    callerAssert($ids === [], 'bad graph cleanup removes the zero sentinel from the request');
    api_graph_remove(0);
    api_graph_remove_multi([]);
    $ids = [];
    api_delete_graphs($ids, '1');
    callerAssert($ids === [], 'empty deletion requests remain empty');
    foreach (['1', '2'] as $type) {
        $id = $type === '1' ? 16000104 : 16000105;
        $database->exec("INSERT INTO graph_local(id) VALUES($id)");
        $database->exec("INSERT INTO graph_templates_graph(local_graph_id,title,title_cache) VALUES($id,'Delete fixture','Delete fixture')");
        $ids = [$id];
        api_delete_graphs($ids, $type, [], static function (): void {});
        callerAssert((int) $observer->query("SELECT COUNT(*) FROM graph_local WHERE id=$id")->fetchColumn() === 0, "delete type $type removes its exact no-data-source graph");
    }
    try {
        $ids = [15000002];
        api_delete_graphs($ids, '1', []);
        throw new RuntimeException('Missing reviewed verifier was accepted');
    } catch (RuntimeException $error) {
        callerAssert($error->getMessage() === 'Reviewed graph removal requires a dependency verifier' && (int) $observer->query('SELECT COUNT(*) FROM graph_local WHERE id=15000002')->fetchColumn() === 1, 'reviewed delete rejects a missing verifier before touching the graph');
    }
}

$dsn = getenv('KADUPUL_REFERENCE_TEST_DSN');
if ($dsn === false || !str_starts_with($dsn, 'mysql:')) {
    throw new RuntimeException('An explicitly configured native caller probe DSN is required.');
}
$database = new PDO($dsn, getenv('KADUPUL_REFERENCE_TEST_USER') ?: '', getenv('KADUPUL_REFERENCE_TEST_PASSWORD') ?: '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false,
]);
$database_sessions = ["$database_hostname:$database_port:$database_default" => $database];
$observer = new PDO($dsn, getenv('KADUPUL_REFERENCE_TEST_USER') ?: '', getenv('KADUPUL_REFERENCE_TEST_PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);


$cases = [
    'keep-lines' => [AGGREGATE_GRAPH_TYPE_KEEP,AGGREGATE_TOTAL_NONE,AGGREGATE_TOTAL_TYPE_ALL,AGGREGATE_ORDER_NONE,GRAPH_ITEM_TYPE_LINE1],
    'keep-area' => [AGGREGATE_GRAPH_TYPE_KEEP,AGGREGATE_TOTAL_NONE,AGGREGATE_TOTAL_TYPE_ALL,AGGREGATE_ORDER_DS_GRAPH,GRAPH_ITEM_TYPE_AREA],
    'stacked' => [GRAPH_ITEM_TYPE_STACK,AGGREGATE_TOTAL_NONE,AGGREGATE_TOTAL_TYPE_ALL,AGGREGATE_ORDER_GRAPH_DS,GRAPH_ITEM_TYPE_LINE1],
    'line-stacked' => [AGGREGATE_GRAPH_TYPE_LINE1_STACK,AGGREGATE_TOTAL_NONE,AGGREGATE_TOTAL_TYPE_ALL,AGGREGATE_ORDER_BASE_GRAPH,GRAPH_ITEM_TYPE_LINE1],
    'keep-stacked' => [AGGREGATE_GRAPH_TYPE_KEEP_STACKED,AGGREGATE_TOTAL_NONE,AGGREGATE_TOTAL_TYPE_ALL,AGGREGATE_ORDER_NONE,GRAPH_ITEM_TYPE_STACK],
    'total-all' => [AGGREGATE_GRAPH_TYPE_KEEP,AGGREGATE_TOTAL_ALL,AGGREGATE_TOTAL_TYPE_ALL,AGGREGATE_ORDER_DS_GRAPH,GRAPH_ITEM_TYPE_LINE1],
    'total-similar' => [GRAPH_ITEM_TYPE_STACK,AGGREGATE_TOTAL_ALL,AGGREGATE_TOTAL_TYPE_SIMILAR,AGGREGATE_ORDER_GRAPH_DS,GRAPH_ITEM_TYPE_AREA],
    'only-all' => [AGGREGATE_GRAPH_TYPE_KEEP,AGGREGATE_TOTAL_ONLY,AGGREGATE_TOTAL_TYPE_ALL,AGGREGATE_ORDER_BASE_GRAPH,GRAPH_ITEM_TYPE_LINE1],
    'only-similar' => [AGGREGATE_GRAPH_TYPE_LINE2_STACK,AGGREGATE_TOTAL_ONLY,AGGREGATE_TOTAL_TYPE_SIMILAR,AGGREGATE_ORDER_NONE,GRAPH_ITEM_TYPE_LINE2],
];
foreach ($cases as $name => [$graphType,$total,$totalType,$order,$sourceType]) {
    $schema = 'kadupul_aggregate_branches_' . bin2hex(random_bytes(8));
    $created = false;
    try {
        $database->exec("CREATE DATABASE `$schema`");
        $created = true;
        $database->exec("USE `$schema`");
        $database_default = $schema;
        $database_sessions = ["$database_hostname:$database_port:$database_default" => $database];
        $observer->exec("USE `$schema`");
        installerSeed($database, $root);
        cdef_reference_install();
        $database->exec("SET SESSION sql_mode='STRICT_ALL_TABLES'");
        $database->exec("INSERT INTO graph_local(id,graph_template_id,host_id,snmp_query_id,snmp_index) VALUES(15000001,1,0,0,''),(15000002,1,0,0,''),(15000003,1,0,0,'')");
        $database->exec("INSERT INTO graph_templates_graph(local_graph_id,graph_template_id,title,title_cache) VALUES(15000001,1,'Existing aggregate','Existing aggregate'),(15000002,1,'Member A','Member A'),(15000003,1,'Member B','Member B')");
        $database->exec("INSERT INTO data_template_rrd(id,local_data_template_rrd_id,local_data_id,data_source_name,data_source_type_id) VALUES(15000102,15000101,15000002,'traffic',1),(15000103,15000101,15000003,'traffic',1)");
        $database->exec("INSERT INTO data_template_rrd(id,local_data_template_rrd_id,local_data_id,data_source_name,data_source_type_id) VALUES(15000101,0,0,'fixture_template',1)");
        $database->exec("INSERT INTO graph_templates_item(local_graph_id,graph_template_id,task_item_id,sequence,graph_type_id,consolidation_function_id,text_format,cdef_id,color_id) VALUES(0,1,15000101,100,4,1,'Fixture template item',0,1)");
        $database->exec("INSERT INTO aggregate_graphs(id,aggregate_template_id,local_graph_id,title_format,graph_template_id,gprint_prefix,graph_type,total,total_type,total_prefix,order_type,user_id) VALUES(15000201,0,15000001,'Existing aggregate',1,'',0,1,0,'',1,1)");
        foreach ([15000002 => 15000102,15000003 => 15000103] as $member => $task) {
            $statement = $database->prepare('INSERT INTO graph_templates_item(local_graph_id,graph_template_id,task_item_id,sequence,graph_type_id,consolidation_function_id,text_format,cdef_id,color_id,hard_return) VALUES(?,?,?,?,?,1,?,0,1,?)');
            $statement->execute([$member,1,$task,1,$sourceType,'Traffic |host_description|','']);
            $statement->execute([$member,1,$task,2,GRAPH_ITEM_TYPE_GPRINT,'Average :current:','on']);
            $statement->execute([$member,1,0,3,GRAPH_ITEM_TYPE_COMMENT,'Member comment','on']);
        }
        $local = 15000001;
        $attributes = ['graph_title' => 'Measured aggregate','aggregate_template_id' => 0,'graph_template_id' => 1,
            'graph_type' => $graphType,'total' => $total,'total_type' => $totalType,'total_prefix' => 'Total ',
            'reorder' => $order,'gprint_prefix' => 'Source ', 'gprint_format' => 0,
            'color_templates' => [],'graph_item_types' => [],'cdefs' => [], 'skipped_items' => [],'total_items' => $total === AGGREGATE_TOTAL_NONE ? [] : [1 => 1, 2 => 2, 3 => 3],'item_no' => 3];
        callerAssert(aggregate_create_update($local, [15000002,15000003], $attributes) === true, "$name admits actual native mixed-item generation");
        $items = $observer->query('SELECT sequence,graph_type_id,cdef_id,text_format FROM graph_templates_item WHERE local_graph_id=15000001 ORDER BY sequence')->fetchAll(PDO::FETCH_ASSOC);
        callerAssert(count($items) >= 2, "$name persists independently observed graph items");
        $sequences = array_map(static fn($item) => (int) $item['sequence'], $items);
        callerAssert(count(array_unique($sequences)) === count($sequences), "$name retains unique ordered graph sequences");
        callerAssert((int) $observer->query('SELECT COUNT(*) FROM aggregate_graphs_items WHERE aggregate_graph_id=15000201')->fetchColumn() === 2, "$name confirms both actual members");
        callerAssert($observer->query('SELECT title_cache FROM graph_templates_graph WHERE local_graph_id=15000001')->fetchColumn() === 'Measured aggregate', "$name confirms legacy title cache");
        if ($total === AGGREGATE_TOTAL_NONE && $graphType === AGGREGATE_GRAPH_TYPE_KEEP) {
            callerAssert(count($items) === 6, "$name preserves exact two-member mixed-item cardinality");
            callerAssert((int) $items[0]['graph_type_id'] === $sourceType, "$name preserves original LINE or AREA type");
        }
        if ($total !== AGGREGATE_TOTAL_NONE) {
            $expression = $totalType === AGGREGATE_TOTAL_TYPE_ALL ? 'ALL_DATA_SOURCES_NODUPS' : 'SIMILAR_DATA_SOURCES_NODUPS';
            $query = $observer->prepare('SELECT COUNT(*) FROM cdef_items AS ci INNER JOIN graph_templates_item AS gi ON gi.cdef_id=ci.cdef_id WHERE gi.local_graph_id=15000001 AND ci.value LIKE ?');
            $query->execute(['%' . $expression . '%']);
            callerAssert((int) $query->fetchColumn() > 0, "$name persists actual expected totalling CDEF expression");
        }
        if ($name === 'keep-lines') {
            ob_start();
            draw_aggregate_graph_items_list(15000002);
            $itemHtml = ob_get_clean();
            $previousXml = libxml_use_internal_errors(true);
            try {
                $document = new DOMDocument();
                callerAssert($document->loadHTML($itemHtml), 'item renderer returns parseable actual legacy HTML');
                $xpath = new DOMXPath($document);
                callerAssert($xpath->query('//select[starts-with(@name,"agg_color_")]')->length === 3, 'item renderer exposes exact three color selections');
                callerAssert($xpath->query('//input[@type="checkbox" and starts-with(@name,"agg_skip_")]')->length === 3, 'item renderer exposes three optional skip controls');
                callerAssert($xpath->query('//input[@type="checkbox" and starts-with(@name,"agg_total_")]')->length === 3, 'item renderer exposes three optional total controls');
                callerAssert(str_contains($document->textContent, 'Graph Items') && str_contains($document->textContent, 'Member comment'), 'item renderer retains actual graph labels and source comment');
                $database->exec('UPDATE graph_templates_graph SET height=200,width=640 WHERE graph_template_id=1');
                $database->exec("INSERT INTO aggregate_graph_templates_graph(aggregate_template_id,t_height,height,t_width,width) VALUES(15000401,'on',345,'',999)");
                ob_start();
                draw_aggregate_template_graph_config(15000401, 1);
                $configHtml = ob_get_clean();
                $configDocument = new DOMDocument();
                callerAssert($configDocument->loadHTML($configHtml), 'graph configuration renderer returns parseable actual HTML');
                $configXPath = new DOMXPath($configDocument);
                callerAssert($configXPath->query('//input[@name="height" and @value="345"]')->length === 1, 'configuration renderer uses explicitly overridden aggregate height');
                callerAssert($configXPath->query('//input[@name="width" and @value="640"]')->length === 1, 'configuration renderer inherits unforced template width');
                callerAssert($configXPath->query('//input[@name="t_height" and @checked]')->length === 1 && $configXPath->query('//input[@name="t_width" and @checked]')->length === 0, 'configuration renderer preserves forced and optional checkbox states');
                callerAssert(str_contains($configDocument->textContent, 'Graph Configuration') && $configXPath->query('//input[@name="t_height" and @title="Override this Value<br>"]')->length === 1, 'configuration renderer retains localized override labels');

            } finally {
                libxml_clear_errors();
                libxml_use_internal_errors($previousXml);
            }
            graphApiControls($database, $observer);
        }
        if (in_array($name, ['total-all', 'total-similar'], true)) {
            // The reviewed percentile body only changes checked SQL boundaries.
            // Use the pinned pre-rewrite body as an output oracle; the test fixture
            // contributes no original production file coverage.
            require_once $root . '/tests/Fixtures/aggregate-percentile-original.php';
            $database->exec("INSERT INTO graph_local(id,graph_template_id,host_id,snmp_query_id,snmp_index) VALUES(15000004,1,0,0,'')");
            $database->exec('INSERT INTO aggregate_graphs(id,aggregate_template_id,local_graph_id,title_format,graph_template_id,gprint_prefix,graph_type,total,total_type,total_prefix,order_type,user_id) SELECT 15000204,aggregate_template_id,15000004,title_format,graph_template_id,gprint_prefix,graph_type,total,total_type,total_prefix,order_type,user_id FROM aggregate_graphs WHERE local_graph_id=15000001');
            $database->exec('INSERT INTO graph_templates_item(local_graph_id,graph_template_id,task_item_id,sequence,graph_type_id,consolidation_function_id,text_format,value,cdef_id,color_id,hard_return,gprint_id)
                SELECT 15000004,graph_template_id,task_item_id,sequence,graph_type_id,consolidation_function_id,text_format,value,cdef_id,color_id,hard_return,gprint_id FROM graph_templates_item WHERE local_graph_id=15000001');
            $statement = $database->prepare('INSERT INTO graph_templates_item(local_graph_id,graph_template_id,task_item_id,sequence,graph_type_id,consolidation_function_id,text_format,value,cdef_id,color_id,hard_return) VALUES(0,1,15000101,?,?,1,?,?,0,1,?)');
            $statement->execute([101, GRAPH_ITEM_TYPE_COMMENT, '95th |95:bits:0:current:2|', '', 'on']);
            $statement->execute([102, GRAPH_ITEM_TYPE_HRULE, '95th rule', '|95:bytes:0:max:2|', 'on']);
            aggregate_percentile_original([15000002,15000003], [], 15000004, $total, $totalType);
            callerAssert(aggregate_graph_mutation(static function () use ($total, $totalType): bool {
                aggregate_handle_ptile_type([15000002,15000003], [], 15000001, $total, $totalType);
                return true;
            }), "$name admits genuine percentile comment and HRULE writes");
            $read = $observer->prepare('SELECT sequence,graph_type_id,text_format,value,hard_return,task_item_id FROM graph_templates_item WHERE local_graph_id=? ORDER BY sequence,id');
            $read->execute([15000004]);
            $oracleRows = $read->fetchAll(PDO::FETCH_ASSOC);
            $read->execute([15000001]);
            callerAssert($read->fetchAll(PDO::FETCH_ASSOC) === $oracleRows, "$name preserves pinned original percentile ordering and raw expressions including coincident sequence");
            $mode = $totalType === AGGREGATE_TOTAL_TYPE_ALL ? 'aggregate_sum' : 'aggregate_current';
            $comments = array_values(array_filter($oracleRows, static fn($row): bool => (int) $row['graph_type_id'] === GRAPH_ITEM_TYPE_COMMENT && str_starts_with($row['text_format'], '95th ')));
            $rules = array_values(array_filter($oracleRows, static fn($row): bool => (int) $row['graph_type_id'] === GRAPH_ITEM_TYPE_HRULE && $row['text_format'] === '95th rule'));
            callerAssert(count($comments) === 1 && count($rules) === 1 && str_contains($comments[0]['text_format'], ':' . $mode . ':') && str_contains($rules[0]['value'], ':' . $mode . '_peak:'), "$name preserves actual expected current and peak RRD percentile substitutions");
            $items = $observer->query('SELECT sequence,graph_type_id,cdef_id,text_format FROM graph_templates_item WHERE local_graph_id=15000001 ORDER BY sequence')->fetchAll(PDO::FETCH_ASSOC);
        }
        $before = $items;
        $database->exec('SET @branch_refuse=1');
        $database->exec("CREATE TRIGGER refuse_branch_generation BEFORE INSERT ON graph_templates_item FOR EACH ROW BEGIN IF COALESCE(@branch_refuse,0)=1 AND NEW.local_graph_id=15000001 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Task-owned mixed branch refusal'; END IF; END");
        callerAssert(aggregate_create_update($local, [15000002,15000003], $attributes) === false, "$name refuses a genuine late mixed-item write");
        callerAssert($observer->query('SELECT sequence,graph_type_id,cdef_id,text_format FROM graph_templates_item WHERE local_graph_id=15000001 ORDER BY sequence')->fetchAll(PDO::FETCH_ASSOC) === $before, "$name preserves previous independently observed mixed graph on failure");
    } finally {
        if ($database->inTransaction()) $database->rollBack();
        if ($created) $database->exec("DROP DATABASE `$schema`");
    }
}
echo "PASS native aggregate admitted branch matrix complete\n";
