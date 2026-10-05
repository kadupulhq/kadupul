<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

// Native storage/aggregate proof supplements the persisted controller matrix.
$root = dirname(__DIR__, 2);
require $root . '/lib/cdef_reference.php';
require $root . '/lib/database.php';
require $root . '/lib/functions.php';
require $root . '/lib/plugins.php';
require $root . '/lib/auth.php';
require $root . '/include/global_constants.php';
require $root . '/lib/import.php';
require $root . '/lib/api_aggregate.php';
require $root . '/lib/aggregate.php';
require $root . '/lib/api_graph.php';
require $root . '/lib/api_data_source.php';
require $root . '/lib/graph_data_removal.php';
require $root . '/lib/variables.php';
require $root . '/tests/Helpers/PhpSource.php';
eval(test_php_function_source(file_get_contents($root . '/tests/security/cdef_reference_installer_native_probe.php'), 'installerSeed')); // nosemgrep: php.lang.security.eval-use.eval-use
define('IN_CACTI_INSTALL', 1);
define('CACTI_VERSION', trim(file_get_contents($root . '/include/cacti_version')));
$config = array('base_path' => $root,'library_path' => $root . '/lib','is_web' => false,'poller_id' => 1,'url_path' => '/fixture/',
    'cacti_db_version' => CACTI_VERSION, 'cacti_server_os' => strtolower(PHP_OS_FAMILY), 'config_options_array' => array('i18n_language_support' => '0','i18n_log' => '0',
        'selective_debug' => '','log_verbosity' => '0','log_destination' => '0','path_cactilog' => '/dev/null','client_timezone_support' => '0',
        'path_spine' => '','reports_allow_ln' => '','auth_method' => '1','graph_auth_method' => '1','rrd_autoclean' => ''));
$_SESSION = array('sess_user_id' => 42);
require $root . '/include/global_languages.php';
require $root . '/include/global_arrays.php';
require $root . '/include/global_form.php';
$database_hostname = 'task-owned-removal';
$database_port = 0;
$database_default = 'task-owned-removal';
$dsn = getenv('KADUPUL_REFERENCE_TEST_DSN');
if ($dsn === false || !str_starts_with($dsn, 'mysql:')) throw new RuntimeException('An explicit native removal probe DSN is required.');
/** Fault injection preserves native SQL/storage while exposing driver failures. */
final class RemovalProbeDatabase extends PDO
{
    public string $fault = '';
    public function commit(): bool
    {
        return $this->fault === 'commit' ? false : parent::commit();
    }
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        if ($this->fault === 'cache' && str_starts_with($query, 'DELETE FROM data_source_stats_hourly_last')) {
            throw new PDOException('Owned volatile cache DELETE failure');
        }
        return parent::prepare($query, $options);
    }
}
$database = new RemovalProbeDatabase($dsn, getenv('KADUPUL_REFERENCE_TEST_USER') ?: '', getenv('KADUPUL_REFERENCE_TEST_PASSWORD') ?: '', array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES => false));
echo 'RUNTIME php=' . PHP_VERSION . ' server=' . $database->query('SELECT VERSION()')->fetchColumn() . "\n";
$caseMode = $database->query('SELECT @@lower_case_table_names')->fetchColumn();
echo 'SCHEMA_CASE_MODE ' . $caseMode . "\n";

function removalProbeAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
    echo 'PASS ' . $message . "\n";
}

function removalProbeSnapshot(PDO $database): array
{
    $state = array();
    foreach (array('graph_local' => 'id','graph_templates_graph' => 'id','graph_templates_item' => 'id','data_local' => 'id',
        'data_template_data' => 'id','data_template_rrd' => 'id','aggregate_graphs' => 'id','aggregate_graphs_items' => 'aggregate_graph_id,local_graph_id',
        'aggregate_graphs_graph_item' => 'aggregate_graph_id,graph_templates_item_id','settings' => 'name') as $table => $order) {
        $state[$table] = $database->query('SELECT * FROM `' . $table . '` ORDER BY ' . $order)->fetchAll(PDO::FETCH_ASSOC);
    }
    return $state;
}

$cases = array('aggregate_success','aggregate_parent_denied','aggregate_sibling_denied','aggregate_source_denied','shared_selected_sources','shared_external_source','late_local_failure','source_cache_success','source_cache_failure','source_commit_failure','schema_case_equivalent','schema_different');
if (($argv[1] ?? '') === '--schema-only') $cases = array('schema_case_equivalent','schema_different');
foreach ($cases as $case) {
    foreach (array(false,true) as $callerOwned) {
        if ($callerOwned && $case === 'source_commit_failure') continue; // A savepoint never owns the outer commit.
        $schema = 'kadupul_graph_data_removal_' . bin2hex(random_bytes(8));
        $created = false;
        try {
            $database->exec('CREATE DATABASE `' . $schema . '`');
            $created = true;
            $database->exec('USE `' . $schema . '`');
            $database_default = $schema;
            $database_sessions = array("$database_hostname:$database_port:$database_default" => $database);
            installerSeed($database, $root);
            cdef_reference_install();
            $database->exec("SET SESSION sql_mode='STRICT_ALL_TABLES'");
            $database->exec("INSERT INTO user_auth(id,username,password,enabled,locked,policy_hosts,policy_graphs,policy_graph_templates) VALUES(42,'owned-removal-fixture','','on','',2,2,2)");
            $database->exec('INSERT INTO user_auth_realm(user_id,realm_id) VALUES(42,3),(42,5)');
            $database->exec("INSERT INTO host(id,hostname,description,poller_id) VALUES(100,'owned-a','Allowed A',1),(101,'owned-denied','Denied',1),(102,'owned-b','Allowed B',1)");
            $database->exec('INSERT INTO user_auth_perms(user_id,type,item_id) VALUES(42,3,100),(42,3,102),(42,1,15000001)');
            $database->exec("INSERT INTO graph_local(id,graph_template_id,host_id) VALUES(15000001,0,0),(15000002,0,100),(15000003,0,102)");
            $database->exec("INSERT INTO graph_templates_graph(local_graph_id,graph_template_id,title,title_cache) VALUES(15000001,0,'Owned aggregate','Owned aggregate'),(15000002,0,'Member A','Member A'),(15000003,0,'Member B','Member B')");
            $database->exec('INSERT INTO data_local(id,host_id) VALUES(16000001,100),(16000002,102)');
            $database->exec("INSERT INTO data_template_data(local_data_id,name,name_cache) VALUES(16000001,'Owned A','Owned A'),(16000002,'Owned B','Owned B')");
            $database->exec("INSERT INTO data_template_rrd(id,local_data_id,data_source_name) VALUES(16000101,16000001,'a'),(16000102,16000002,'b')");
            $database->exec("INSERT INTO graph_templates_item(local_graph_id,sequence,graph_type_id,consolidation_function_id,text_format,task_item_id) VALUES(15000002,1,1,1,'Member A',16000101),(15000003,1,1,1,'Member B',16000102)");
            $database->exec("INSERT INTO data_source_stats_hourly_cache VALUES(16000001,'a',CURRENT_TIMESTAMP,10),(16000002,'b',CURRENT_TIMESTAMP,20)");
            $database->exec("INSERT INTO data_source_stats_hourly_last VALUES(16000001,'a',10,10),(16000002,'b',20,20)");
            if (str_starts_with($case, 'aggregate_')) {
                $database->exec("INSERT INTO aggregate_graphs(id,aggregate_template_id,local_graph_id,title_format,graph_template_id,gprint_prefix,graph_type,total,total_type,total_prefix,order_type,user_id) VALUES(15000101,0,15000001,'Owned aggregate',0,'',0,1,0,'',1,42)");
                $database->exec('INSERT INTO aggregate_graphs_items(aggregate_graph_id,local_graph_id,sequence) VALUES(15000101,15000002,1),(15000101,15000003,2)');
                if ($case === 'aggregate_parent_denied') {
                    $database->exec('UPDATE graph_local SET host_id=101 WHERE id=15000001');
                    $database->exec('DELETE FROM user_auth_perms WHERE user_id=42 AND type=1 AND item_id=15000001');
                }
                if ($case === 'aggregate_sibling_denied') $database->exec('UPDATE graph_local SET host_id=101 WHERE id=15000003');
                if ($case === 'aggregate_source_denied') $database->exec('UPDATE data_local SET host_id=101 WHERE id=16000002');
            }
            if (str_starts_with($case, 'shared_')) $database->exec('UPDATE graph_templates_item SET task_item_id=16000101 WHERE local_graph_id=15000003');
            if ($case === 'late_local_failure') $database->exec("CREATE TRIGGER owned_removal_failure BEFORE DELETE ON graph_local FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Owned late deletion failure'");
            if ($case === 'schema_case_equivalent' || $case === 'schema_different') {
                $database_default = $case === 'schema_case_equivalent' ? strtoupper($schema) : $schema . '_different';
                $database_sessions = array("$database_hostname:$database_port:$database_default" => $database);
            }
            $before = removalProbeSnapshot($database);
            $database->exec('CREATE TABLE caller_work(value INTEGER) ENGINE=InnoDB');
            if ($callerOwned) {
                $database->beginTransaction();
                $database->exec('INSERT INTO caller_work VALUES(7)');
            }
            $failed = false;
            $failureMessage = '';
            $sourceCase = str_starts_with($case, 'source_');
            $database->fault = $case === 'source_cache_failure' ? 'cache' : ($case === 'source_commit_failure' ? 'commit' : '');
            $ids = $case === 'shared_selected_sources' ? array(15000002,15000003) : array(15000002);
            try {
                if ($sourceCase) $ids = array(16000001);
                $scope = GraphDataRemovalScope::review($sourceCase ? 'data' : 'graph', $ids, str_starts_with($case, 'shared_') ? 2 : 1);
                $scope->run(static function () use (&$ids, $scope, $case, $sourceCase): void {
                    if ($sourceCase) api_data_source_remove_multi($ids, true, array($scope,'verify'), $scope);
                    else api_delete_graphs($ids, str_starts_with($case, 'shared_') ? 2 : 1, $scope->dataIds(), array($scope,'verify'), $scope);
                });
            } catch (Throwable $error) {
                $failed = true;
                $failureMessage = $error->getMessage();
                if (!in_array($case, array('aggregate_parent_denied','aggregate_sibling_denied','aggregate_source_denied','late_local_failure','source_cache_failure','source_commit_failure','schema_different'), true)
                    && !($case === 'schema_case_equivalent' && !in_array($caseMode, array(1,2,'1','2'), true))) echo 'UNEXPECTED_FAILURE ' . $error->getMessage() . "\n";
            }
            $database->fault = '';
            $expectedFailure = in_array($case, array('aggregate_parent_denied','aggregate_sibling_denied','aggregate_source_denied','late_local_failure','source_cache_failure','source_commit_failure','schema_different'), true)
                || ($case === 'schema_case_equivalent' && !in_array($caseMode, array(1,2,'1','2'), true));
            removalProbeAssert($failed === $expectedFailure, "$case expected admission/failure owned=" . (int) $callerOwned);
            removalProbeAssert($database->inTransaction() === $callerOwned, "$case preserves transaction ownership");
            removalProbeAssert((int) $database->query('SELECT COUNT(*) FROM caller_work')->fetchColumn() === ($callerOwned ? 1 : 0), "$case preserves caller work");
            if ($failed) removalProbeAssert(removalProbeSnapshot($database) === $before, "$case preserves exact persisted rows");
            else {
                removalProbeAssert((int) $database->query('SELECT COUNT(*) FROM graph_local WHERE id=15000002')->fetchColumn() === ($sourceCase ? 1 : 0), "$case preserves selected mode graph behavior");
                if ($case === 'aggregate_success') {
                    removalProbeAssert((int) $database->query('SELECT COUNT(*) FROM aggregate_graphs_items WHERE local_graph_id=15000002')->fetchColumn() === 0, "$case removes selected membership");
                    removalProbeAssert((int) $database->query('SELECT COUNT(*) FROM aggregate_graphs_items WHERE local_graph_id=15000003')->fetchColumn() === 1, "$case retains sibling membership");
                    removalProbeAssert((int) $database->query('SELECT COUNT(*) FROM graph_templates_item WHERE local_graph_id=15000001')->fetchColumn() > 0, "$case regenerates aggregate parent through production code");
                }
                if ($case === 'shared_selected_sources') removalProbeAssert((int) $database->query('SELECT COUNT(*) FROM data_local WHERE id=16000001')->fetchColumn() === 0, "$case removes source shared only by selected set");
                if ($case === 'shared_external_source') removalProbeAssert((int) $database->query('SELECT COUNT(*) FROM data_local WHERE id=16000001')->fetchColumn() === 1, "$case retains source used by surviving graph");
            }
            if ($sourceCase) {
                removalProbeAssert((int) $database->query('SELECT COUNT(*) FROM data_local WHERE id=16000001')->fetchColumn() === ($failed ? 1 : 0), "$case checks persistent source outcome");
                removalProbeAssert((int) $database->query('SELECT COUNT(*) FROM data_source_stats_hourly_cache WHERE local_data_id=16000001')->fetchColumn() === 0, "$case exposes actual MEMORY invalidation");
                removalProbeAssert((int) $database->query('SELECT COUNT(*) FROM data_source_stats_hourly_last WHERE local_data_id=16000001')->fetchColumn() === ($case === 'source_cache_failure' ? 1 : 0), "$case checks each volatile DELETE outcome");
                removalProbeAssert((int) $database->query('SELECT COUNT(*) FROM data_source_stats_hourly_cache WHERE local_data_id=16000002')->fetchColumn() === 1, "$case preserves unselected cache rows");
                if ($failed) removalProbeAssert(str_contains($failureMessage, 'Volatile statistics caches'), "$case reports cache invalidation separately from persistent cleanup");
            }
            if ($database->inTransaction()) {
                $database->rollBack();
                removalProbeAssert(removalProbeSnapshot($database) === $before, "$case caller rollback restores persistent operation");
                if ($sourceCase) removalProbeAssert((int) $database->query('SELECT COUNT(*) FROM data_source_stats_hourly_cache WHERE local_data_id=16000001')->fetchColumn() === 0, "$case caller rollback cannot restore MEMORY cache");
            }
        } finally {
            $database->fault = '';
            if ($database->inTransaction()) $database->rollBack();
            if ($created) $database->exec('DROP DATABASE `' . $schema . '`');
        }
    }
}
echo "GRAPH_DATA_REMOVAL_NATIVE_COMPLETE\n";
