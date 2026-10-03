<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

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
require $root . '/lib/variables.php';
require $root . '/tests/Helpers/PhpSource.php';
eval(test_php_function_source(file_get_contents($root . '/tests/security/cdef_reference_installer_native_probe.php'), 'installerSeed'));
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
$observer = new PDO($dsn, getenv('KADUPUL_REFERENCE_TEST_USER') ?: '', getenv('KADUPUL_REFERENCE_TEST_PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);

function generationSnapshot(PDO $database): array
{
    $result = [];
    foreach (['graph_local' => 'id','graph_templates_graph' => 'id','graph_templates_item' => 'id',
        'aggregate_graphs' => 'id','aggregate_graphs_items' => 'aggregate_graph_id,local_graph_id', 'aggregate_graphs_graph_item' => 'aggregate_graph_id,graph_templates_item_id',
        'cdef' => 'id','cdef_items' => 'id','settings' => 'name'] as $table => $order) {
        $result[$table] = $database->query("SELECT * FROM `$table` ORDER BY $order")->fetchAll(PDO::FETCH_ASSOC);
    }
    return $result;
}

foreach (['existing','new','missing_source','empty_members','title_cache'] as $case) {
    foreach ([false,true] as $callerOwned) {
        $schema = 'kadupul_generation_' . bin2hex(random_bytes(8));
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
            $database->exec("INSERT INTO graph_local(id,graph_template_id,host_id,snmp_query_id,snmp_index) VALUES(15000001,0,0,0,''),(15000002,0,0,0,'')");
            $database->exec("INSERT INTO graph_templates_graph(local_graph_id,graph_template_id,title,title_cache) VALUES(15000001,0,'Existing aggregate','Existing aggregate'),(15000002,0,'Member graph','Member graph')");
            $database->exec("INSERT INTO graph_templates_item(local_graph_id,graph_template_id,sequence,graph_type_id,consolidation_function_id,text_format,cdef_id) VALUES(15000001,0,1,1,1,'Preserved item',0),(15000002,0,1,1,1,'Source item',0)");
            $database->exec("INSERT INTO aggregate_graphs(id,aggregate_template_id,local_graph_id,title_format,graph_template_id,gprint_prefix,graph_type,total,total_type,total_prefix,order_type,user_id) VALUES(15000101,0,15000001,'Existing aggregate',0,'',0,1,0,'',1,1)");
            foreach (['cleanup' => static fn() => aggregate_graphs_cleanup(15000002, 15000001, AGGREGATE_ORDER_NONE),
                'reorder' => static fn() => aggregate_reorder_ds_graph(15000002, 0, 15000001, AGGREGATE_ORDER_BASE_GRAPH, 0),
                'reorder_empty_data_sources' => static fn() => aggregate_reorder_ds_graph(15000002, 0, 15000001, 3, 0)] as $handlerCase => $handlerOperation) {
                $outerHandler = static fn(): bool => false;
                $innerHandler = static fn(): bool => false;
                set_error_handler($outerHandler);
                set_error_handler($innerHandler);
                $reportingBefore = error_reporting();
                $innerRemoved = false;
                try {
                    $handlerOperation();
                    $current = set_error_handler($innerHandler);
                    restore_error_handler();
                    callerAssert(
                        $current === $innerHandler && error_reporting() === $reportingBefore,
                        "$case $handlerCase restores the exact inner caller handler and reporting"
                    );
                    restore_error_handler();
                    $innerRemoved = true;
                    $outerCurrent = set_error_handler($outerHandler);
                    restore_error_handler();
                    callerAssert($outerCurrent === $outerHandler, "$case $handlerCase preserves the outer caller handler stack");
                } finally {
                    if (!$innerRemoved) restore_error_handler();
                    restore_error_handler();
                }
            }
            $database->exec('SET @generation_reject=1');
            if ($case === 'title_cache') {
                $database->exec("CREATE TRIGGER reject_title_cache BEFORE UPDATE ON graph_templates_graph FOR EACH ROW BEGIN IF COALESCE(@generation_reject,1)=1 AND NEW.title_cache<>OLD.title_cache THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Task-owned title cache refusal'; END IF; END");
            }
            $local = $case === 'new' ? 0 : 15000001;
            $originalLocal = $local;
            $members = match ($case) {
                'missing_source' => [16777002], 'empty_members' => [], default => [15000002]
            };
            $attributes = ['graph_title' => 'Changed aggregate','aggregate_template_id' => 0,'graph_template_id' => 0,
                'graph_type' => 0,'total' => AGGREGATE_TOTAL_NONE,'total_type' => 0,'reorder' => AGGREGATE_ORDER_NONE,
                'color_templates' => [],'graph_item_types' => [1 => 0],
                'cdefs' => in_array($case, ['existing','new'], true) ? [1 => 16777001] : [],
                'skipped_items' => [],'total_items' => []];
            $secondarySchema = $schema . '_secondary';
            $database->exec("CREATE DATABASE `$secondarySchema`");
            try {
                $observer->exec("USE `$secondarySchema`");
                $observer->exec('CREATE TABLE settings(name VARCHAR(128) PRIMARY KEY,value TEXT) ENGINE=InnoDB');
                $observer->exec("CREATE TABLE graph_local LIKE `$schema`.graph_local");
                $observer->exec("CREATE TABLE aggregate_graphs_graph_item LIKE `$schema`.aggregate_graphs_graph_item");
                $observer->exec("INSERT INTO aggregate_graphs_graph_item(aggregate_graph_id,graph_templates_item_id,sequence,color_template,t_graph_type_id,graph_type_id,t_cdef_id,cdef_id,item_skip,item_total) VALUES(1,1,1,0,'',0,'',0,'','')");
                $primaryBefore = generationSnapshot($database);
                try {
                    $switched = aggregate_graph_mutation(static function () use ($observer, &$database_sessions, $database_hostname, $database_port, $database_default): bool {
                        aggregate_graph_execute("INSERT INTO settings(name,value) VALUES('first_connection_write','must roll back')");
                        $database_sessions["$database_hostname:$database_port:$database_default"] = $observer;
                        aggregate_graph_execute("INSERT INTO settings(name,value) VALUES('second_connection_write','must never execute')");
                        return true;
                    });
                } finally {
                    $database_sessions["$database_hostname:$database_port:$database_default"] = $database;
                }
                callerAssert($switched === false && generationSnapshot($database) === $primaryBefore, "$case rejects changed selected PDO and rolls back original connection");
                callerAssert((int) $observer->query('SELECT COUNT(*) FROM settings')->fetchColumn() === 0, "$case never writes into alternate connection schema");
                try {
                    $switchedSave = aggregate_graph_mutation(static function () use ($observer, &$database_sessions, $database_hostname, $database_port, $database_default): bool {
                        aggregate_graph_execute("INSERT INTO settings(name,value) VALUES('first_delegate_write','must roll back')");
                        $database_sessions["$database_hostname:$database_port:$database_default"] = $observer;
                        aggregate_graph_save_row(['id' => 0,'graph_template_id' => 0,'host_id' => 0,'snmp_query_id' => 0,'snmp_index' => ''], 'graph_local');
                        return true;
                    });
                } finally {
                    $database_sessions["$database_hostname:$database_port:$database_default"] = $database;
                }
                callerAssert($switchedSave === false && generationSnapshot($database) === $primaryBefore, "$case rejects changed PDO before legacy sql_save and rolls back original rows");
                callerAssert((int) $observer->query('SELECT COUNT(*) FROM graph_local')->fetchColumn() === 0, "$case never delegates a graph write to alternate PDO");
                $cacheBefore = $observer->query('SELECT * FROM aggregate_graphs_graph_item ORDER BY aggregate_graph_id,graph_templates_item_id')->fetchAll(PDO::FETCH_ASSOC);
                try {
                    $switchedCache = aggregate_graph_mutation(static function () use ($observer, &$database_sessions, $database_hostname, $database_port, $database_default): bool {
                        aggregate_graph_execute("INSERT INTO settings(name,value) VALUES('first_cache_write','must roll back')");
                        $database_sessions["$database_hostname:$database_port:$database_default"] = $observer;
                        return aggregate_graph_items_save([['aggregate_graph_id' => 1,'graph_templates_item_id' => 2]], 'aggregate_graphs_graph_item');
                    });
                } finally {
                    $database_sessions["$database_hostname:$database_port:$database_default"] = $database;
                }
                callerAssert(
                    $switchedCache === false && generationSnapshot($database) === $primaryBefore,
                    "$case rejects changed PDO before cache replacement and rolls back original rows"
                );
                callerAssert(
                    $observer->query('SELECT * FROM aggregate_graphs_graph_item ORDER BY aggregate_graph_id,graph_templates_item_id')->fetchAll(PDO::FETCH_ASSOC) === $cacheBefore,
                    "$case never replaces persisted cache rows on alternate PDO"
                );

                foreach (['settings', 'graph_local', 'aggregate_graphs_graph_item'] as $secondaryTable) {
                    $observer->exec("ALTER TABLE `$secondaryTable` CONVERT TO CHARACTER SET latin1");
                    $observer->exec("ALTER TABLE `$secondaryTable` ENGINE=MyISAM");
                }
                foreach ([false, true] as $schemaCallerOwned) {
                    foreach (['execute', 'save', 'cache', 'read'] as $boundary) {
                        $secondaryBefore = [];
                        foreach (['settings', 'graph_local', 'aggregate_graphs_graph_item'] as $table) {
                            $secondaryBefore[$table] = $observer->query("SELECT * FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
                        }
                        if ($schemaCallerOwned) {
                            $database->beginTransaction();
                            $database->exec("INSERT INTO settings(name,value) VALUES('schema_prior_work','preserved')");
                        }
                        $scopeBefore = generationSnapshot($database);
                        try {
                            $schemaSwitched = aggregate_graph_mutation(static function () use ($database, $secondarySchema, $boundary): bool {
                                aggregate_graph_execute("INSERT INTO settings(name,value) VALUES('schema_first_write','must roll back')");
                                $database->exec("USE `$secondarySchema`");
                                if ($boundary === 'save') {
                                    aggregate_graph_save_row(['id' => 0, 'graph_template_id' => 0, 'host_id' => 0, 'snmp_query_id' => 0, 'snmp_index' => ''], 'graph_local');
                                } elseif ($boundary === 'cache') {
                                    return aggregate_graph_items_save([['aggregate_graph_id' => 1, 'graph_templates_item_id' => 2]], 'aggregate_graphs_graph_item');
                                } elseif ($boundary === 'read') {
                                    aggregate_graph_fetch_rows('SELECT * FROM settings');
                                } else {
                                    aggregate_graph_execute("INSERT INTO settings(name,value) VALUES('schema_external_write','must never execute')");
                                }
                                return true;
                            });
                        } finally {
                            $database->exec("USE `$schema`");
                        }
                        callerAssert($schemaSwitched === false && generationSnapshot($database) === $scopeBefore, "$case $boundary same-PDO schema switch refuses and preserves original rows");
                        callerAssert($database->inTransaction() === $schemaCallerOwned, "$case $boundary schema switch preserves caller transaction ownership");
                        foreach ($secondaryBefore as $table => $rows) {
                            callerAssert($observer->query("SELECT * FROM `$table`")->fetchAll(PDO::FETCH_ASSOC) === $rows, "$case $boundary never writes secondary MyISAM $table");
                        }
                        if ($schemaCallerOwned) {
                            callerAssert($database->query("SELECT value FROM settings WHERE name='schema_prior_work'")->fetchColumn() === 'preserved', "$case $boundary schema switch preserves prior caller work");
                            $database->rollBack();
                        }
                    }
                }

                foreach (['graph_local', 'graph_templates_graph', 'graph_templates_item', 'aggregate_graphs',
                    'aggregate_graphs_items', 'aggregate_graphs_graph_item', 'cdef', 'cdef_items', 'settings'] as $table) {
                    $observer->exec("CREATE TABLE IF NOT EXISTS `$table` LIKE `$schema`.`$table`");
                    $observer->exec("ALTER TABLE `$table` ENGINE=InnoDB");
                }
                foreach ([false, true] as $admissionCallerOwned) {
                    if ($admissionCallerOwned) {
                        $database->beginTransaction();
                        $database->exec("INSERT INTO settings(name,value) VALUES('admission_prior_work','preserved')");
                    }
                    $admissionBefore = generationSnapshot($database);
                    $secondaryBefore = generationSnapshot($observer);
                    $called = false;
                    $database->exec("USE `$secondarySchema`");
                    try {
                        $admitted = aggregate_graph_mutation(static function () use (&$called): bool {
                            $called = true;
                            aggregate_graph_execute("INSERT INTO settings(name,value) VALUES('wrong_configured_target','must not execute')");
                            return true;
                        });
                    } finally {
                        $database->exec("USE `$schema`");
                    }
                    callerAssert($admitted === false && !$called, "$case refuses mismatched configured schema before invoking another callback");
                    callerAssert(generationSnapshot($database) === $admissionBefore && generationSnapshot($observer) === $secondaryBefore, "$case repeated admission preserves both nine-table schemas");
                    callerAssert($database->inTransaction() === $admissionCallerOwned, "$case mismatched admission preserves caller transaction ownership");
                    $database->exec("USE `$secondarySchema`");
                    try {
                        $directCache = aggregate_graph_items_save([['aggregate_graph_id' => 1, 'graph_templates_item_id' => 2]], 'aggregate_graphs_graph_item');
                        callerAssert($directCache === false, "$case standalone cache refuses mismatched configured schema");
                        try {
                            aggregate_graph_execute("INSERT INTO settings(name,value) VALUES('direct_wrong_target','must not execute')");
                            throw new RuntimeException('A standalone write reached the wrong configured schema.');
                        } catch (RuntimeException $refusal) {
                            callerAssert(str_contains($refusal->getMessage(), 'configured target'), "$case standalone write refuses mismatched configured schema");
                        }
                    } finally {
                        $database->exec("USE `$schema`");
                    }
                    callerAssert(generationSnapshot($database) === $admissionBefore && generationSnapshot($observer) === $secondaryBefore, "$case standalone cache and write preserve both schemas");

                    if ($admissionCallerOwned) $database->rollBack();
                }


            } finally {
                $observer->exec("USE `$schema`");
                $database->exec("DROP DATABASE `$secondarySchema`");
            }
            foreach ([null,false,1,'yes'] as $unconfirmed) {
                $confirmationBefore = generationSnapshot($database);
                callerAssert(
                    aggregate_graph_mutation(static function () use ($unconfirmed): mixed {
                        aggregate_graph_execute("INSERT INTO settings(name,value) VALUES('unconfirmed_callback','must roll back')");
                        return $unconfirmed;
                    }) === false && generationSnapshot($database) === $confirmationBefore,
                    "$case rolls back every callback without explicit true confirmation"
                );
            }
            $persisted = generationSnapshot($observer);
            if ($callerOwned) {
                $database->beginTransaction();
                $database->exec("INSERT INTO settings(name,value) VALUES('generation_prior_work','preserved')");
            }
            $before = generationSnapshot($database);
            $reporting = error_reporting();
            $sentinel = static fn(): bool => false;
            set_error_handler($sentinel);
            try {
                $saved = aggregate_create_update($local, $members, $attributes);
                $current = set_error_handler($sentinel);
                restore_error_handler();
                callerAssert($current === $sentinel && error_reporting() === $reporting, "$case restores nested error handler and reporting");
            } finally {
                restore_error_handler();
            }
            callerAssert($saved === false && $local === $originalLocal, "$case refuses operation and restores returned graph identity");
            callerAssert(generationSnapshot($database) === $before, "$case preserves all nine mutation participants");
            callerAssert(generationSnapshot($observer) === $persisted, "$case independently preserves committed graph and CDEF rows");
            callerAssert($database->inTransaction() === $callerOwned, "$case preserves caller transaction ownership");
            if ($callerOwned) {
                callerAssert($database->query("SELECT value FROM settings WHERE name='generation_prior_work'")->fetchColumn() === 'preserved', "$case preserves prior caller work");
            }
            $database->exec('SET @generation_reject=0');
            $attributes['cdefs'] = [];
            callerAssert(aggregate_create_update($local, [15000002], $attributes) === true, "$case admits actual successful retry");
            callerAssert($database->inTransaction() === $callerOwned, "$case retry preserves caller transaction ownership");
            callerAssert($database->query("SELECT title_cache FROM graph_templates_graph WHERE local_graph_id=$local")->fetchColumn() === 'Changed aggregate', "$case persists exact legacy plain title cache");
            $database->exec("UPDATE graph_templates_graph SET title='|host_description|',title_cache='Existing placeholder cache' WHERE local_graph_id=$local");
            aggregate_graph_refresh_title((int) $local);
            callerAssert($database->query("SELECT title_cache FROM graph_templates_graph WHERE local_graph_id=$local")->fetchColumn() === 'Existing placeholder cache', "$case retains legacy nonempty placeholder title cache");
            $database->exec("UPDATE graph_templates_graph SET title_cache='' WHERE local_graph_id=$local");
            aggregate_graph_refresh_title((int) $local);
            callerAssert($database->query("SELECT title_cache FROM graph_templates_graph WHERE local_graph_id=$local")->fetchColumn() === '|host_description|', "$case initializes legacy empty placeholder title cache");
            $database->exec("INSERT INTO host(id,hostname,description) VALUES(16777003,'fixture.example','Checked source host')");
            $hostText = '|host_description| / |host_hostname|';
            callerAssert(aggregate_graph_substitute_host_data($hostText, '|', '|', 16777003) === substitute_host_data($hostText, '|', '|', 16777003), "$case preserves actual legacy host substitution");
            $database->exec("INSERT INTO host_snmp_cache(host_id,snmp_query_id,snmp_index,field_name,field_value,oid) VALUES(16777003,1,'fixture','ifDescr','Measured interface','fixture.oid')");
            callerAssert(aggregate_graph_substitute_query_data('|query_ifDescr|', 16777003, 1, 'fixture', 5) === substitute_snmp_query_data('|query_ifDescr|', 16777003, 1, 'fixture', 5), "$case preserves actual legacy query substitution and truncation");
            if ($callerOwned) $database->rollBack();
        } finally {
            if ($database->inTransaction()) $database->rollBack();
            if ($created) $database->exec("DROP DATABASE `$schema`");
        }
    }
}

callerAssert(true, 'native aggregate regeneration and cleanup complete');
