<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

ob_start();
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
require_once $root . '/lib/cdef.php';
require $root . '/lib/variables.php';
require $root . '/lib/html_utility.php';
require $root . '/lib/html_validate.php';
require $root . '/lib/api_graph.php';
require $root . '/tests/Helpers/PhpSource.php';
eval(test_php_function_source(file_get_contents($root . '/tests/security/cdef_reference_installer_native_probe.php'), 'installerSeed'));
// Execute actual first-party save helpers without page dispatch. These controls
// cover the save/cache operation, not HTTP authentication/CSRF or predispatch
// authorized orphan housekeeping; installed route proof remains separate.
eval(test_php_function_source(file_get_contents($root . '/color_templates.php'), 'sync_color_templates'));
eval(test_php_function_source(file_get_contents($root . '/aggregate_graphs.php'), 'form_save'));
eval(test_php_function_source(file_get_contents($root . '/aggregate_templates.php'), 'aggregate_form_save'));
$no_http_headers = true;
define('IN_CACTI_INSTALL', 1);
define('CACTI_VERSION', trim(file_get_contents($root . '/include/cacti_version')));
// Use the production gettext fallback and legacy helpers; no function or SQL
// replacements. These explicit settings only disable logging/browser behavior.
$config = ['base_path' => $root, 'library_path' => $root . '/lib', 'is_web' => false, 'poller_id' => 1, 'url_path' => '/fixture/', 'cacti_server_os' => strtolower(PHP_OS_FAMILY),
    'config_options_array' => ['i18n_language_support' => '0', 'i18n_log' => '0', 'selective_debug' => '',
        'log_verbosity' => '0', 'log_destination' => '0', 'path_cactilog' => '/dev/null', 'client_timezone_support' => '0',
        'path_spine' => '', 'reports_allow_ln' => '', 'auth_method' => '0'],
];
$_SESSION = [];
require $root . '/include/global_languages.php';
require $root . '/include/global_arrays.php';
require $root . '/include/global_form.php';
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

$dsn = getenv('KADUPUL_REFERENCE_TEST_DSN');
if ($dsn === false || !str_starts_with($dsn, 'mysql:')) {
    throw new RuntimeException('An explicitly configured native caller probe DSN is required.');
}
$database = new PDO($dsn, getenv('KADUPUL_REFERENCE_TEST_USER') ?: '', getenv('KADUPUL_REFERENCE_TEST_PASSWORD') ?: '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false,
]);
$database_sessions = ["$database_hostname:$database_port:$database_default" => $database];
echo "RUNTIME php=" . PHP_VERSION . " driver=" . $database->getAttribute(PDO::ATTR_DRIVER_NAME) . " server=" . $database->query('SELECT VERSION()')->fetchColumn() . "\n";


function outerSnapshot(PDO $database): array
{
    $rows = [];
    foreach (['graph_local' => 'id','graph_templates_graph' => 'id','graph_templates_item' => 'id',
        'aggregate_graphs' => 'id','aggregate_graphs_items' => 'aggregate_graph_id,local_graph_id',
        'aggregate_graphs_graph_item' => 'aggregate_graph_id,graph_templates_item_id',
        'aggregate_graph_templates' => 'id', 'aggregate_graph_templates_graph' => 'aggregate_template_id',
        'aggregate_graph_templates_item' => 'aggregate_template_id,graph_templates_item_id',
        'cdef' => 'id','cdef_items' => 'id','settings' => 'name'] as $table => $order) {
        $rows[$table] = $database->query("SELECT * FROM `$table` ORDER BY $order")->fetchAll(PDO::FETCH_ASSOC);
    }
    return $rows;
}

$webId = 0;
$webOperation = static function () use (&$webId): bool {
    $_SESSION['sess_error_fields'] = [];
    $_SESSION['sess_messages'] = [];
    return api_aggregate_create_from_request([15000002,15000003], $webId);
};
$operations = [
    'web_shape_9' => $webOperation,
    'web_create_9' => $webOperation,
    'web_create_10' => $webOperation,
    'web_cache_9' => $webOperation,
    'associate' => static fn() => api_aggregate_associate(15000001, [15000003]),
    'disassociate' => static fn() => api_aggregate_disassociate(15000001, [15000003]),
    'create' => static fn() => api_aggregate_create('New aggregate', [15000001]),
    'convert' => static fn() => api_aggregate_convert_template([15000001]),
    'convert_fanout' => static fn() => api_aggregate_convert_template([15000001,15000004]),
    'remove_member_fanout' => static function () {
        try {
            api_graph_remove_aggregate_items([15000003]);
            return true;
        } catch (RuntimeException $error) {
            if ($error->getMessage() !== 'Aggregate graph regeneration failed before graph removal.') throw $error;
            return false;
        }
    },
];
foreach ($operations as $name => $operation) {
    if (isset($argv[1]) && $argv[1] !== $name) continue;
    foreach ([false,true] as $callerOwned) {
        $schema = 'kadupul_outer_aggregate_' . bin2hex(random_bytes(8));
        $created = false;
        try {
            $database->exec("CREATE DATABASE `$schema`");
            $created = true;
            $database->exec("USE `$schema`");
            $database_default = $schema;
            $database_sessions = ["$database_hostname:$database_port:$database_default" => $database];
            installerSeed($database, $root);
            cdef_reference_install();
            $database->exec("SET SESSION sql_mode='STRICT_ALL_TABLES'");
            $database->exec("INSERT INTO graph_local (id,graph_template_id,host_id,snmp_query_id,snmp_index) VALUES(15000001,1,0,0,''),(15000002,1,0,0,''),(15000003,1,0,0,''),(15000004,1,0,0,'')");
            $database->exec("INSERT INTO graph_templates_graph (local_graph_id,graph_template_id,title,title_cache) VALUES(15000001,1,'Existing aggregate','Existing aggregate'),(15000002,1,'Member graph','Member graph'),(15000003,1,'Second member','Second member'),(15000004,1,'Second aggregate','Second aggregate')");
            $database->exec("INSERT INTO graph_templates_item (local_graph_id,graph_template_id,task_item_id,sequence,graph_type_id,consolidation_function_id,text_format,cdef_id) VALUES(15000001,1,15000001,1,1,1,'Preserved item',0),(15000002,1,15000001,1,1,1,'Source item',0),(15000003,1,15000001,1,1,1,'Second source',0),(15000004,1,15000001,1,1,1,'Second preserved item',0)");
            $database->exec("INSERT INTO aggregate_graphs (id,aggregate_template_id,local_graph_id,title_format,graph_template_id,gprint_prefix,graph_type,total,total_type,total_prefix,order_type,user_id) VALUES(15000101,0,15000001,'Existing aggregate',1,'',0,1,0,'',1,1),(15000104,0,15000004,'Second aggregate',1,'',0,1,0,'',1,1)");
            $database->exec("INSERT INTO aggregate_graphs_items (aggregate_graph_id,local_graph_id,sequence) VALUES(15000101,15000002,1),(15000101,15000003,2),(15000104,15000002,1),(15000104,15000003,2)");
            $database->exec("INSERT INTO aggregate_graph_templates(id,name,graph_template_id,gprint_prefix,graph_type,total,total_type,total_prefix,order_type,user_id) VALUES(15000201,'Target aggregate template',1,'',0,1,0,'',1,1)");
            $database_last_error = '';
            $beforeTypes = $database->query('SELECT id,graph_type_id FROM graph_templates_item WHERE local_graph_id=15000001 ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
            aggregate_conditional_convert_graph_type(15000001, GRAPH_ITEM_TYPE_STACK, GRAPH_ITEM_TYPE_AREA);
            $afterTypes = $database->query('SELECT id,graph_type_id FROM graph_templates_item WHERE local_graph_id=15000001 ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
            echo 'NO_MATCH_ERROR ' . ($database_last_error === '' ? 'none' : substr($database_last_error, 0, 220)) . "\n";
            callerAssert($database_last_error === '' && $beforeTypes === $afterTypes, "$name absent source type is a successful no-op without an invalid integer UPDATE");
            aggregate_conditional_convert_graph_type(15000001, GRAPH_ITEM_TYPE_COMMENT, GRAPH_ITEM_TYPE_AREA);
            callerAssert((int) $database->query('SELECT graph_type_id FROM graph_templates_item WHERE local_graph_id=15000001 ORDER BY sequence LIMIT 1')->fetchColumn() === GRAPH_ITEM_TYPE_AREA, "$name valid selected source type changes only the selected graph item");
            $database->exec('UPDATE graph_templates_item SET graph_type_id=' . GRAPH_ITEM_TYPE_COMMENT . ' WHERE local_graph_id=15000001');
            if ($name === 'associate' && !$callerOwned) {
                $database->exec("INSERT INTO cdef(id,hash,name) VALUES(15000601,'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa','Outer expression'),(15000602,'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb','Nested outer expression')");
                $database->exec("INSERT INTO cdef_items(hash,cdef_id,sequence,type,value) VALUES('cccccccccccccccccccccccccccccccc',15000601,1,6,'CURRENT_DATA_SOURCE,0,*'),('dddddddddddddddddddddddddddddddd',15000602,1,5,'15000601'),('eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee',15000602,2,6,'2,*')");
                $database->exec("INSERT INTO cdef(id,hash,name) VALUES(15000603,'ffffffffffffffffffffffffffffffff','Legitimate empty expression'),(15000604,'11111111111111111111111111111111','Nested empty expression'),(15000605,'22222222222222222222222222222222','Unsafe existing nested reference')");
                $database->exec("INSERT INTO cdef_items(hash,cdef_id,sequence,type,value) VALUES('33333333333333333333333333333333',15000604,1,5,'15000603')");
                callerAssert(aggregate_graph_cdef_text(15000604) === '' && get_cdef(15000604) === '', 'actual existing nested empty CDEF parent preserves legitimate empty expression');
                // Simulate an unsafe pre-existing orphan on this disposable schema only.
                // Restore the exact installed trigger before exercising the consumer.
                $database->exec('DROP TRIGGER kadupul_cdef_cdef_items_insert');
                try {
                    $database->exec("INSERT INTO cdef_items(hash,cdef_id,sequence,type,value) VALUES('44444444444444444444444444444444',15000605,1,5,'16777001')");
                } finally {
                    $database->exec(\Kadupul\Platform\Infrastructure\Legacy\CdefReferenceTriggers::createStatements()['kadupul_cdef_cdef_items_insert']);
                }
                $missingParentRefused = false;
                try {
                    aggregate_graph_cdef_text(15000605);
                } catch (RuntimeException $error) {
                    $missingParentRefused = $error->getMessage() === 'Aggregate CDEF parent is unavailable.';
                }
                callerAssert($missingParentRefused, 'actual missing nested CDEF parent refuses instead of becoming a legitimate empty expression');
                $database->exec('DELETE FROM cdef_items WHERE cdef_id=15000605');
                $database->exec('DELETE FROM cdef WHERE id=15000605');
                callerAssert(aggregate_graph_cdef_text(15000602) === get_cdef(15000602), 'checked recursive aggregate expression preserves actual original CDEF consumer bytes');
                $database->exec('RENAME TABLE cdef_items TO task_saved_cdef_items');
                try {
                    $refused = false;
                    try {
                        aggregate_graph_cdef_text(15000602);
                    } catch (PDOException $error) {
                        $refused = $error->getCode() === '42S02';
                    }
                    callerAssert($refused, 'actual recursive CDEF read failure refuses instead of producing an empty expression');
                } finally {
                    $database->exec('RENAME TABLE task_saved_cdef_items TO cdef_items');
                }
            }
            if ($name === 'associate' && !$callerOwned) {
                $database->exec("INSERT INTO color_templates(color_template_id,name) VALUES(15000101,'Unused fixture'),(15000102,'Referenced fixture')");
                $database->exec("INSERT INTO color_template_items(color_template_id,color_id,sequence) VALUES(15000102,1,1)");
                $_SESSION['sess_messages'] = [];
                callerAssert(sync_color_templates(15000101) === true && $_SESSION['sess_messages']['color_template_sync']['level'] === MESSAGE_LEVEL_INFO, 'real successful empty color discovery reports no usages');
                $database->exec('RENAME TABLE aggregate_graph_templates_item TO task_saved_aggregate_graph_templates_item');
                $_SESSION['sess_messages'] = [];
                try {
                    callerAssert(sync_color_templates(15000101) === false && !isset($_SESSION['sess_messages']['color_template_sync']) && $_SESSION['sess_messages']['color_template_sync_failed']['level'] === MESSAGE_LEVEL_ERROR, 'real failed color discovery refuses without no-usage or success message');
                } finally {
                    $database->exec('RENAME TABLE task_saved_aggregate_graph_templates_item TO aggregate_graph_templates_item');
                }
                $targetItem = (int) $database->query('SELECT id FROM graph_templates_item WHERE local_graph_id=15000001 ORDER BY id LIMIT 1')->fetchColumn();
                $database->exec("INSERT INTO aggregate_graphs_graph_item(aggregate_graph_id,graph_templates_item_id,sequence,color_template,item_skip,item_total) VALUES(15000101,$targetItem,1,15000102,'','')");
                $_SESSION['sess_messages'] = [];
                callerAssert(sync_color_templates(15000102) === true && str_contains($_SESSION['sess_messages']['color_template_sync']['message'], '1 Non-Templated Aggregates'), 'real non-template color cohort executes correct graph collection');
            }
            if (str_starts_with($name, 'web_')) {
                $webId = 0;
                $action = str_ends_with($name, '_10') ? '10' : '9';
                $_POST = $_REQUEST = $_CACTI_REQUEST = [
                    'drp_action' => $action, 'title_format' => 'Actual web callback aggregate',
                    'item_no' => '1', 'graph_template_id' => '1', 'gprint_prefix' => '',
                    'aggregate_graph_type' => '0', 'aggregate_total' => '1',
                    'aggregate_total_type' => '0', 'aggregate_total_prefix' => '',
                    'aggregate_order_type' => '1', 'aggregate_template_id' => '15000201',
                ];
                $database->exec("INSERT INTO graph_templates_item (local_graph_id,graph_template_id,task_item_id,sequence,graph_type_id,consolidation_function_id,text_format,cdef_id) VALUES(0,1,15000001,1,1,1,'Actual template item',0)");
                $templateItem = (int) $database->lastInsertId();
                $database->exec("INSERT INTO aggregate_graph_templates_item(aggregate_template_id,graph_templates_item_id,sequence,color_template,item_skip,item_total) VALUES(15000201,$templateItem,1,0,'','')");
            }
            if ($name === 'associate' && !$callerOwned) {
                $database->exec("INSERT INTO graph_templates_item(local_graph_id,graph_template_id,sequence,graph_type_id,text_format) VALUES(0,1,1,1,'Ignored propagated field fixture')");
                $propagatedItem = (int) $database->lastInsertId();
                $database->exec('SET @outer_header_attempts=0');
                $database->exec("CREATE TRIGGER reject_outer_header BEFORE UPDATE ON graph_templates_graph FOR EACH ROW BEGIN SET @outer_header_attempts=COALESCE(@outer_header_attempts,0)+1; SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Task-owned refused graph header'; END");
                $_POST = $_REQUEST = $_CACTI_REQUEST = [
                    'save_component_graph' => '1', 'local_graph_id' => '15000001',
                    'graph_template_id' => '1', 'aggregate_template_id' => '0',
                    'title_format' => 'Refused form title', 'template_propogation' => 'on',
                    'agg_color_' . $propagatedItem => [7],
                ];
                $_SESSION['sess_error_fields'] = $_SESSION['sess_messages'] = [];
                $formBefore = outerSnapshot($database);
                try {
                    form_save();
                    callerAssert(isset($_SESSION['sess_messages']['aggregate_regeneration_failed']) && $_SESSION['sess_messages']['aggregate_regeneration_failed']['level'] === MESSAGE_LEVEL_ERROR && !isset($_SESSION['sess_messages'][1]), 'actual direct graph-header refusal returns controlled form error without success');
                    callerAssert((int) $database->query('SELECT @outer_header_attempts')->fetchColumn() === 1, 'actual propagated graph form ignores non-editable item payload before direct header attempt');
                    callerAssert(outerSnapshot($database) === $formBefore && !$database->inTransaction(), 'actual direct graph-header refusal preserves existing graph rows');
                } finally {
                    $database->exec('DROP TRIGGER reject_outer_header');
                    $_POST = $_REQUEST = $_CACTI_REQUEST = [];
                    $_SESSION['sess_error_fields'] = $_SESSION['sess_messages'] = [];
                }
            }
            $_REQUEST['aggregate_template_id'] = 15000201;
            $database->exec('SET @outer_reject=1');
            $rejectCondition = str_ends_with($name, '_fanout') ? 'NEW.local_graph_id=15000004' : 'NEW.local_graph_id NOT IN (15000002,15000003)';
            $database->exec("CREATE TRIGGER reject_aggregate_item BEFORE INSERT ON graph_templates_item FOR EACH ROW BEGIN IF COALESCE(@outer_reject,1)=1 AND $rejectCondition THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Task-owned refused aggregate item'; END IF; END");
            if ($name === 'web_cache_9') {
                $database->exec('SET @outer_cache_reject=1');
                $database->exec("CREATE TRIGGER reject_aggregate_cache BEFORE INSERT ON aggregate_graphs_graph_item FOR EACH ROW BEGIN IF COALESCE(@outer_cache_reject,1)=1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Task-owned refused aggregate cache'; END IF; END");
            }
            if ($name === 'web_shape_9') {
                $database->exec("CREATE TRIGGER observe_outer_parent BEFORE INSERT ON graph_local FOR EACH ROW SET @outer_parent_writes=COALESCE(@outer_parent_writes,0)+1");
                $database->exec("CREATE TRIGGER observe_outer_header BEFORE UPDATE ON graph_templates_graph FOR EACH ROW SET @outer_form_writes=COALESCE(@outer_form_writes,0)+1");
                $database->exec("CREATE TRIGGER observe_outer_template BEFORE UPDATE ON aggregate_graph_templates FOR EACH ROW SET @outer_form_writes=COALESCE(@outer_form_writes,0)+1");
            }
            $observer = new PDO($dsn, getenv('KADUPUL_REFERENCE_TEST_USER') ?: '', getenv('KADUPUL_REFERENCE_TEST_PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
            $observer->exec("USE `$schema`");
            if ($callerOwned) {
                $database->beginTransaction();
                $database->exec("INSERT INTO settings(name,value) VALUES('owned_outer_prior_work','kept')");
            }
            if ($name === 'web_shape_9') {
                $database->exec('SET @outer_reject=0');
                $baseRequest = $_POST;
                $cases = ['agg_color_' => [[7], [['nested' => 7]], true, null, -1, '1e2', '4294967296'], 'agg_skip_' => [[7]], 'agg_total_' => [[7]]];
                if (in_array($argv[2] ?? '', ['forms-only','template-only'], true)) $cases = [];
                foreach ($cases as $prefix => $values) {
                    foreach ($values as $value) {
                        $_POST = $baseRequest + [$prefix . $templateItem => $value];
                        $database->exec('SET @outer_parent_writes=0');
                        $shapeBefore = outerSnapshot($database);
                        $shapeResult = $webOperation();
                        $writes = (int) $database->query('SELECT @outer_parent_writes')->fetchColumn();
                        echo "OBSERVED malformed $prefix result=" . ($shapeResult ? 'true' : 'false') . " parent_writes=$writes\n";
                        callerAssert($shapeResult === false && $writes === 0 && $webId === 0 && outerSnapshot($database) === $shapeBefore, "actual web malformed $prefix refuses before any parent or cache write");
                    }
                }
                foreach (($argv[2] ?? '') === 'template-only' ? ['template'] : ['graph','template'] as $formKind) {
                    foreach (['agg_color_','agg_skip_','agg_total_'] as $prefix) {
                        $_POST = $_REQUEST = $_CACTI_REQUEST = [
                            'local_graph_id' => '15000001', 'graph_template_id' => '1',
                            'aggregate_template_id' => '0', 'title_format' => 'Must not save malformed title',
                            'gprint_prefix' => '', 'graph_type' => '0', 'total' => '1',
                            'total_type' => '0', 'total_prefix' => '', 'order_type' => '1',
                            $prefix . $templateItem => [7],
                        ];
                        if ($formKind === 'graph') {
                            $_POST['save_component_graph'] = $_REQUEST['save_component_graph'] = $_CACTI_REQUEST['save_component_graph'] = '1';
                        } else {
                            $_POST += ['save_component_template' => '1','id' => '15000201','name' => 'Must not save malformed template name','graph_template_id_prev' => '1'];
                            $_REQUEST = $_CACTI_REQUEST = $_POST;
                        }
                        $_SESSION['sess_error_fields'] = $_SESSION['sess_messages'] = [];
                        $database->exec('SET @outer_form_writes=0');
                        $formBefore = outerSnapshot($database);
                        if ($formKind === 'graph') form_save();
                        else aggregate_form_save();
                        $writes = (int) $database->query('SELECT @outer_form_writes')->fetchColumn();
                        callerAssert($writes === 0 && outerSnapshot($database) === $formBefore && isset($_SESSION['sess_messages']['aggregate_regeneration_failed']) && $_SESSION['sess_messages']['aggregate_regeneration_failed']['level'] === MESSAGE_LEVEL_ERROR && !isset($_SESSION['sess_messages'][1]), "actual $formKind form malformed $prefix refuses before save/cache writes and reports controlled error");
                        callerAssert($database->inTransaction() === $callerOwned, "actual $formKind malformed form preserves caller ownership");
                    }
                }
                // Propagated graph items are not editable in this form. Irrelevant
                // posted item fields retain the original ignore behavior.
                $_POST = $_REQUEST = $_CACTI_REQUEST = $baseRequest;
                $_SESSION['sess_error_fields'] = $_SESSION['sess_messages'] = [];
                $database->exec('SET @outer_reject=1');
            }
            if ($name === 'create') {
                $missingBefore = outerSnapshot($database);
                callerAssert(api_aggregate_create('Missing-template fixture', [15000002], 16777001) === false && outerSnapshot($database) === $missingBefore, 'actual missing aggregate template refuses before parent fields or writes');
                callerAssert($database->inTransaction() === $callerOwned, 'missing aggregate template preserves caller transaction ownership');
            }
            $before = outerSnapshot($database);
            $observerBefore = outerSnapshot($observer);
            $reporting = error_reporting();
            $sentinel = static fn(): bool => false;
            $previous = set_error_handler($sentinel);
            try {
                $result = $operation();
                $current = set_error_handler($sentinel);
                restore_error_handler();
                $handlerRestored = $current === $sentinel && error_reporting() === $reporting;
            } finally {
                restore_error_handler();
            }
            $rowsPreserved = $before === outerSnapshot($database);
            echo 'OBSERVED ' . $name . ' refusal=' . ($result === false ? 'yes' : 'no') . ' rows_preserved=' . ($rowsPreserved ? 'yes' : 'no') . ' handler_restored=' . ($handlerRestored ? 'yes' : 'no') . "\n";
            if (str_starts_with($name, 'web_')) callerAssert($webId === 0, "$name restores request-local graph identity after refusal");
            callerAssert($rowsPreserved && $result === false, "$name refuses actual child write and preserves all operation rows");
            callerAssert(outerSnapshot($observer) === $observerBefore, "$name independent connection observes no partial operation writes");
            callerAssert($handlerRestored, "$name restores nested handler and reporting");
            callerAssert($database->inTransaction() === $callerOwned, "$name preserves caller transaction ownership");
            $database->exec('SET @outer_reject=0');
            $database->exec('SET @outer_cache_reject=0');
            if ($callerOwned) {
                callerAssert($database->query("SELECT value FROM settings WHERE name='owned_outer_prior_work'")->fetchColumn() === 'kept', "$name preserves prior caller row");
            }
            $retry = $operation();
            if ($retry !== true) echo 'LAST_ERROR ' . substr((string) ($database_last_error ?? ''), 0, 400) . "\n";
            if (str_starts_with($name, 'web_')) {
                callerAssert($webId > 0 && (int) $database->query("SELECT COUNT(*) FROM aggregate_graphs WHERE local_graph_id=$webId")->fetchColumn() === 1, "$name successful web callback confirms its new parent identity");
                callerAssert((int) $database->query("SELECT COUNT(*) FROM graph_templates_item WHERE local_graph_id=$webId")->fetchColumn() > 0, "$name successful web callback produces actual child items");
                if ($name !== 'web_create_10') callerAssert((int) $database->query("SELECT COUNT(*) FROM aggregate_graphs_graph_item agi JOIN aggregate_graphs ag ON ag.id=agi.aggregate_graph_id WHERE ag.local_graph_id=$webId")->fetchColumn() > 0, "$name successful web callback confirms cache participant writes");
            }
            callerAssert($retry === true, "$name successful actual retry returns confirmed success");
            callerAssert($database->inTransaction() === $callerOwned, "$name success leaves caller transaction ownership unchanged");
            if ($name === 'create') {
                callerAssert(api_aggregate_create('Existing-template retry', [15000002], 15000201) === true, 'actual existing aggregate template admits successful creation retry');
            }

            if ($callerOwned) $database->rollBack();
            $observer = null;
        } finally {
            if ($database->inTransaction() && !$database->rollBack()) throw new RuntimeException('Cannot roll back owned caller fixture.');
            if ($created && $database->exec("DROP DATABASE `$schema`") === false) throw new RuntimeException('Cannot remove owned caller fixture.');
        }
    }
}

callerAssert(true, 'native aggregate outer callers and cleanup complete');
ob_end_flush();
