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


if (($argv[1] ?? '') === 'writer') {
    $database->exec('USE `' . $argv[2] . '`');
    $database->exec('SET SESSION innodb_lock_wait_timeout=15');
    $database->exec('SET SESSION TRANSACTION ISOLATION LEVEL ' . $argv[3]);
    $table = $argv[4];
    $idField = $table === 'aggregate_graphs_graph_item' ? 'aggregate_graph_id' : 'aggregate_template_id';
    $database->query('SHOW CREATE TABLE `' . $table . '`')->fetchAll();
    $database->query('SELECT * FROM `' . $table . '`')->fetchAll();
    echo 'CONNECTION ' . $database->query('SELECT CONNECTION_ID()')->fetchColumn() . "\n";
    flush();
    if (fgets(STDIN) !== "RUN\n") throw new RuntimeException('Missing coordinated writer start.');
    echo aggregate_graph_items_save([[$idField => 1,'graph_templates_item_id' => 103,'cdef_id' => 15000002]], $table) ? "ACCEPTED\n" : "REJECTED\n";
    exit;
}

$schema = 'kadupul_cdef_aggregate_' . bin2hex(random_bytes(8));
$created = false;
try {
    echo 'SERVER ' . $database->query('SELECT VERSION()')->fetchColumn() . "\n";
    $database->exec("CREATE DATABASE `$schema`");
    $created = true;
    $database->exec("USE `$schema`");
    $observer->exec("USE `$schema`");
    installerSeed($database, $root);
    cdef_reference_install();
    $database->exec("SET SESSION sql_mode='STRICT_ALL_TABLES'");
    $database->exec("INSERT INTO cdef (id,hash,name) VALUES(15000001,'" . str_repeat('a', 32) . "','Preserved cache owner')");
    foreach (['aggregate_graph_templates_item' => 'aggregate_template_id','aggregate_graphs_graph_item' => 'aggregate_graph_id'] as $table => $idField) {
        $database->exec("DELETE FROM `$table`");
        $database->exec("INSERT INTO `$table` (`$idField`,graph_templates_item_id,sequence,cdef_id,item_skip,color_template,item_total) VALUES(1,100,1,15000001,'on',0,''),(2,200,2,0,'',0,'')");
        $before = $database->query("SELECT * FROM `$table` ORDER BY `$idField`, graph_templates_item_id")->fetchAll(PDO::FETCH_ASSOC);
        $saved = aggregate_graph_items_save([[$idField => 1,'graph_templates_item_id' => 101,'sequence' => 1,'cdef_id' => 15000002]], $table);
        callerAssert($saved === false, "$table actual production helper reports missing-CDEF rejection");
        callerAssert(
            $database->query("SELECT * FROM `$table` ORDER BY `$idField`, graph_templates_item_id")->fetchAll(PDO::FETCH_ASSOC) === $before,
            "$table rejected actual replacement preserves all previous cache rows"
        );
        $database->beginTransaction();
        $database->exec("UPDATE cdef SET name='Caller-owned work' WHERE id=15000001");
        $saved = aggregate_graph_items_save([[$idField => 1,'graph_templates_item_id' => 101,'cdef_id' => 15000002]], $table);
        callerAssert($saved === false && $database->inTransaction(), "$table refusal preserves caller transaction ownership");
        callerAssert(
            $database->query("SELECT name FROM cdef WHERE id=15000001")->fetchColumn() === 'Caller-owned work',
            "$table refusal preserves earlier caller writes"
        );
        callerAssert(
            $database->query("SELECT * FROM `$table` ORDER BY `$idField`, graph_templates_item_id")->fetchAll(PDO::FETCH_ASSOC) === $before,
            "$table refusal rolls back only replacement savepoint"
        );
        $database->rollBack();
        $saved = aggregate_graph_items_save([[$idField => 1,'graph_templates_item_id' => 101,'sequence' => 3,'cdef_id' => 15000001]], $table);
        callerAssert($saved === true && !$database->inTransaction(), "$table valid production replacement commits own transaction");
        $rows = $database->query("SELECT * FROM `$table` ORDER BY `$idField`, graph_templates_item_id")->fetchAll(PDO::FETCH_ASSOC);
        callerAssert(count($rows) === 2 && (int) $rows[0]['graph_templates_item_id'] === 101 && (int) $rows[0]['sequence'] === 3
            && $rows[1] === $before[1], "$table valid replacement preserves unrelated rows and consumer values");
        $database->beginTransaction();
        $saved = aggregate_graph_items_save([[$idField => 1,'graph_templates_item_id' => 102,'cdef_id' => 15000001]], $table);
        callerAssert($saved === true && $database->inTransaction(), "$table valid replacement preserves caller transaction");
        $database->rollBack();
        callerAssert(
            $database->query("SELECT * FROM `$table` ORDER BY `$idField`, graph_templates_item_id")->fetchAll(PDO::FETCH_ASSOC) === $rows,
            "$table caller rollback restores pre-replacement cache"
        );
        $create = $database->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_ASSOC)['Create Table'];
        $database->exec(str_replace('CREATE TABLE ', 'CREATE TEMPORARY TABLE ', $create));
        try {
            callerAssert(
                aggregate_graph_items_save([[$idField => 1,'graph_templates_item_id' => 101]], $table) === false,
                "$table temporary shadow rejected before mutation"
            );
            callerAssert(
                (int) $database->query("SELECT COUNT(*) FROM `$table`")->fetchColumn() === 0,
                "$table temporary shadow remains untouched"
            );
        } finally {
            $database->exec("DROP TEMPORARY TABLE `$table`");
        }
        foreach (['READ COMMITTED', 'REPEATABLE READ'] as $isolation) {
            foreach (['commit', 'rollback'] as $decision) {
                $database->exec("INSERT INTO cdef (id,hash,name) VALUES(15000002,'" . str_repeat('b', 32) . "','Concurrent cache target')");

                $process = null;
                $pipes = [];
                try {
                    $process = proc_open(
                        [PHP_BINARY,'-d','auto_prepend_file=','-d','zend.exception_ignore_args=1',__FILE__,'writer',$schema,$isolation,$table],
                        [['pipe','r'],['pipe','w'],['pipe','w']],
                        $pipes
                    );
                    if (!is_resource($process)) throw new RuntimeException('Could not start actual aggregate writer.');
                    $output = fgets($pipes[1]);
                    if (!is_string($output) || !preg_match('/^CONNECTION [0-9]+\n$/D', $output)) throw new RuntimeException('Missing actual writer connection handshake.');
                    $database->beginTransaction();
                    $database->exec('DELETE FROM cdef WHERE id=15000002');
                    fwrite($pipes[0], "RUN\n");
                    fclose($pipes[0]);
                    stream_set_blocking($pipes[1], false);
                    $deadline = microtime(true) + 10;
                    $connection = null;
                    $blocked = false;
                    do {
                        $output .= stream_get_contents($pipes[1]);
                        if (preg_match('/CONNECTION ([0-9]+)\n/', $output, $match)) $connection = (int) $match[1];
                        if ($connection !== null) {
                            $state = $observer->prepare("SELECT trx_state FROM information_schema.innodb_trx WHERE trx_mysql_thread_id=?");
                            $state->execute([$connection]);
                            $blocked = $state->fetchColumn() === 'LOCK WAIT';
                            if (!$blocked) {
                                $metadata = $observer->prepare('SELECT STATE FROM information_schema.PROCESSLIST WHERE ID=?');
                                $metadata->execute([$connection]);
                                $observedState = $metadata->fetchColumn();
                                $blocked = strcasecmp((string) $observedState, 'Waiting for table metadata lock') === 0;
                                if (!$blocked) {
                                    $status = $observer->query('SHOW ENGINE INNODB STATUS')->fetch(PDO::FETCH_ASSOC)['Status'];
                                    foreach (explode('---TRANSACTION', $status) as $transaction) {
                                        if (preg_match('/(?:MySQL|MariaDB) thread id ' . $connection . '\\b/', $transaction) && str_contains($transaction, 'LOCK WAIT')) {
                                            $blocked = true;
                                            break;
                                        }
                                    }
                                }
                            }
                        }
                        if (!$blocked) usleep(20000);
                    } while (!$blocked && microtime(true) < $deadline);
                    if (!$blocked) {
                        $statement = $observer->prepare('SELECT INFO FROM information_schema.PROCESSLIST WHERE ID=?');
                        $statement->execute([$connection]);
                        $info = $statement->fetchColumn();
                        echo "DIAGNOSTIC own fixture statement " . (is_string($info) ? $info : 'not active') . "\n";
                    }
                    if (!$blocked) echo "DIAGNOSTIC actual writer outcome " . trim($output) . " state=" . ($observedState ?? 'unknown') . "\n";
                    callerAssert($blocked, "$table $isolation $decision actual writer waits on native parent lock");
                    if ($decision === 'commit') $database->commit();
                    else $database->rollBack();
                    stream_set_blocking($pipes[1], true);
                    $output .= stream_get_contents($pipes[1]);
                    $error = stream_get_contents($pipes[2]);
                    fclose($pipes[1]);
                    fclose($pipes[2]);
                    $pipes = [];
                    $exit = proc_close($process);
                    $process = null;
                    callerAssert($exit === 0 && $error === '', "$table $isolation $decision actual writer exits cleanly");
                    $expected = $decision === 'commit' ? 'REJECTED' : 'ACCEPTED';
                    callerAssert(str_ends_with($output, $expected . "\n"), "$table $isolation $decision resumed helper returns confirmed outcome");
                    $current = $database->query("SELECT * FROM `$table` ORDER BY `$idField`, graph_templates_item_id")->fetchAll(PDO::FETCH_ASSOC);
                    if ($decision === 'commit') {
                        callerAssert($current === $rows, "$table $isolation resumed refusal preserves previous cache");
                    } else {
                        callerAssert(
                            count($current) === 2 && (int) $current[0]['cdef_id'] === 15000002 && $current[1] === $rows[1],
                            "$table $isolation parent rollback permits complete replacement"
                        );
                        callerAssert(
                            aggregate_graph_items_save([[$idField => 1,'graph_templates_item_id' => 101,'sequence' => 3,'cdef_id' => 15000001]], $table),
                            "$table $isolation restores cache before next independent case"
                        );
                        $database->exec('DELETE FROM cdef WHERE id=15000002');
                    }
                } finally {
                    if ($database->inTransaction()) $database->rollBack();
                    if (is_resource($process)) {
                        proc_terminate($process);
                        foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
                        proc_close($process);
                    }
                }
            }
        }
        $database->exec("ALTER TABLE `$table` ENGINE=MyISAM COMMENT='ENGINE=InnoDB'");
        try {
            callerAssert(
                aggregate_graph_items_save([[$idField => 1,'graph_templates_item_id' => 101], [$idField => 1,'graph_templates_item_id' => 101]], $table) === false,
                "$table actual MyISAM with misleading engine comment refuses before duplicate-key replacement"
            );
            callerAssert(
                $observer->query("SELECT * FROM `$table` ORDER BY `$idField`, graph_templates_item_id")->fetchAll(PDO::FETCH_ASSOC) === $rows,
                "$table independent observer confirms nontransactional refusal preserves cache"
            );
        } finally {
            $database->exec("ALTER TABLE `$table` ENGINE=InnoDB COMMENT='ENGINE=MyISAM TEMPORARY'");
        }
        $database->beginTransaction();
        $database->exec("UPDATE cdef SET name='Safe comment caller work' WHERE id=15000001");
        callerAssert(
            aggregate_graph_items_save([[$idField => 1,'graph_templates_item_id' => 104,'cdef_id' => 15000001]], $table)
            && $database->inTransaction()
            && $database->query("SELECT name FROM cdef WHERE id=15000001")->fetchColumn() === 'Safe comment caller work',
            "$table actual InnoDB misleading comment admits replacement preserving caller work"
        );
        callerAssert(
            $observer->query("SELECT * FROM `$table` ORDER BY `$idField`, graph_templates_item_id")->fetchAll(PDO::FETCH_ASSOC) === $rows,
            "$table independent observer does not see caller uncommitted replacement"
        );
        $database->rollBack();
        callerAssert(
            $observer->query("SELECT * FROM `$table` ORDER BY `$idField`, graph_templates_item_id")->fetchAll(PDO::FETCH_ASSOC) === $rows,
            "$table safe comment caller rollback restores old cache"
        );

    }
} finally {
    if ($database->inTransaction()) $database->rollBack();
    if ($created) $database->exec("DROP DATABASE `$schema`");
}
