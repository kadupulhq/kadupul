<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

require dirname(__DIR__, 2) . '/src/Platform/Contract/CdefReferenceReadiness.php';
require dirname(__DIR__, 2) . '/src/Platform/Infrastructure/Legacy/CdefReferenceTriggers.php';
require dirname(__DIR__, 2) . '/src/Platform/Infrastructure/Legacy/CdefReferenceContract.php';
require dirname(__DIR__, 2) . '/src/Platform/Infrastructure/Legacy/CdefReferenceReadinessProcedure.php';
require dirname(__DIR__, 2) . '/src/Platform/Infrastructure/Legacy/CdefReferenceReadiness.php';

use Kadupul\Platform\Infrastructure\Legacy\CdefReferenceContract;
use Kadupul\Platform\Infrastructure\Legacy\CdefReferenceReadiness;

final class ContractProbeDatabase extends PDO
{
    public array $ddl = [];

    public function exec(string $statement): int|false
    {
        if (preg_match('/\A\s*(CREATE|ALTER|DROP)\s/i', $statement) === 1) {
            $this->ddl[] = $statement;
        }

        return parent::exec($statement);
    }
}

function contractProbeAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
    echo "PASS $message\n";
}

function contractProbeRefused(callable $operation, string $expected): void
{
    try {
        $operation();
    } catch (RuntimeException $error) {
        contractProbeAssert(str_contains($error->getMessage(), $expected), $expected);

        return;
    }
    throw new RuntimeException('Expected native contract preflight refusal was not observed.');
}

$dsn = getenv('KADUPUL_REFERENCE_TEST_DSN');
if ($dsn === false || !str_starts_with($dsn, 'mysql:')) {
    throw new RuntimeException('An explicitly configured native contract probe DSN is required.');
}
$database = new ContractProbeDatabase($dsn, getenv('KADUPUL_REFERENCE_TEST_USER') ?: '', getenv('KADUPUL_REFERENCE_TEST_PASSWORD') ?: '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$schema = 'kadupul_cdef_contract_' . bin2hex(random_bytes(8));
$created = false;
$runtimeAccount = null;
$runtimeAccountCreated = false;
try {
    $serverVersion = (string) $database->query('SELECT VERSION()')->fetchColumn();
    echo 'SERVER ' . $serverVersion . "\n";
    $database->exec("CREATE DATABASE `$schema`");
    $created = true;
    $database->exec("USE `$schema`");
    // Exercise the actual shipped persistent table definitions before the
    // smaller fixtures below isolate the remaining contract transitions.
    $tables = ['cdef', 'cdef_items', 'graph_templates_item', 'aggregate_graph_templates_item', 'aggregate_graphs_graph_item'];
    $source = file_get_contents(dirname(__DIR__, 2) . '/cacti.sql');
    foreach ($tables as $table) {
        if (preg_match('/CREATE TABLE `?' . preg_quote($table, '/') . '`? \(.*?;\s*/s', $source, $definition) !== 1) {
            throw new RuntimeException('The actual source table definition is unavailable.');
        }
        $database->exec($definition[0]);
    }
    $engineContract = new CdefReferenceContract($database, 1);
    foreach ($tables as $table) {
        $database->exec("ALTER TABLE `$table` ENGINE=MyISAM COMMENT='ENGINE=InnoDB'");
        $before = [];
        foreach ($tables as $persistent) {
            $before[$persistent] = $database->query("SHOW CREATE TABLE `$persistent`")->fetch(PDO::FETCH_ASSOC);
        }
        $database->ddl = [];
        contractProbeRefused(fn() => $engineContract->install(), 'persistent InnoDB');
        contractProbeAssert($database->ddl === [], "$table actual PDO sees no capability/index/trigger/procedure DDL before refusal");
        foreach ($tables as $persistent) {
            contractProbeAssert(
                $database->query("SHOW CREATE TABLE `$persistent`")->fetch(PDO::FETCH_ASSOC) === $before[$persistent],
                "$table misleading comment is refused before changing $persistent metadata"
            );
        }
        contractProbeAssert(
            (int) $database->query('SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE()')->fetchColumn() === 0
            && (int) $database->query('SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=DATABASE()')->fetchColumn() === 0,
            "$table actual nontransactional engine refuses before capability/index/trigger/procedure DDL"
        );
        $database->exec("ALTER TABLE `$table` ENGINE=InnoDB COMMENT='ENGINE=MyISAM TEMPORARY'");
    }
    $engineContract->install();
    contractProbeAssert(
        $engineContract->ready() && (new CdefReferenceReadiness($database, 1))->ready(),
        'actual source InnoDB tables with engine and TEMPORARY comment text remain admitted'
    );
    foreach ($tables as $table) {
        $database->exec("DROP TABLE `$table`");
    }
    $database->exec('DROP PROCEDURE kadupul_cdef_reference_status');
    foreach ([
        'cdef' => 'id MEDIUMINT UNSIGNED PRIMARY KEY',
        'cdef_items' => 'id INT UNSIGNED PRIMARY KEY, cdef_id MEDIUMINT UNSIGNED NOT NULL, type TINYINT UNSIGNED NOT NULL, value VARCHAR(150) NOT NULL, INDEX owner (cdef_id)',
        'graph_templates_item' => 'id INT UNSIGNED PRIMARY KEY, cdef_id MEDIUMINT UNSIGNED NOT NULL DEFAULT 0, INDEX reference_id (cdef_id)',
        'aggregate_graph_templates_item' => 'id INT UNSIGNED PRIMARY KEY, cdef_id MEDIUMINT UNSIGNED NULL',
        'aggregate_graphs_graph_item' => 'id INT UNSIGNED PRIMARY KEY, cdef_id MEDIUMINT UNSIGNED NULL',
    ] as $table => $columns) {
        $database->exec("CREATE TABLE `$table` ($columns) ENGINE=InnoDB");
    }
    $database->exec('INSERT INTO cdef VALUES (7),(8)');
    $contract = new CdefReferenceContract($database, 1);
    contractProbeAssert(!$contract->ready(), 'missing persistent contract is not ready');
    $contract->install();
    contractProbeAssert($contract->ready(), 'native capability installation and exact metadata readiness');
    $runtimeReady = (new CdefReferenceReadiness($database, 1))->ready();
    if (!$runtimeReady) {
        foreach (['cdef', 'cdef_items', 'graph_templates_item', 'aggregate_graph_templates_item', 'aggregate_graphs_graph_item'] as $table) {
            $columns = $database->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
            echo 'DIAGNOSTIC columns ' . $table . ' ' . json_encode(array_map(static fn(array $column): array =>
                array_intersect_key($column, array_flip(['Field', 'Type', 'Extra'])), $columns), JSON_THROW_ON_ERROR) . "\n";
        }
        echo 'DIAGNOSTIC routine ' . json_encode($database->query("SELECT ROUTINE_TYPE, SECURITY_TYPE, SQL_DATA_ACCESS FROM information_schema.ROUTINES"
            . " WHERE ROUTINE_SCHEMA=DATABASE() AND ROUTINE_NAME='kadupul_cdef_reference_status'")->fetchAll(PDO::FETCH_ASSOC), JSON_THROW_ON_ERROR) . "\n";
        echo 'DIAGNOSTIC parameter_count ' . $database->query("SELECT COUNT(*) FROM information_schema.PARAMETERS WHERE SPECIFIC_SCHEMA=DATABASE()"
            . " AND SPECIFIC_NAME='kadupul_cdef_reference_status'")->fetchColumn() . "\n";
        $statement = $database->query('CALL kadupul_cdef_reference_status()');
        $rowset = 0;
        do {
            $columns = $statement->columnCount();
            $first = $statement->fetch(PDO::FETCH_ASSOC);
            $second = $statement->fetch(PDO::FETCH_ASSOC);
            echo 'DIAGNOSTIC rowset ' . json_encode([
                'number' => $rowset++, 'columns' => $columns, 'first' => $first, 'second' => $second,
                'types' => is_array($first) ? array_map('get_debug_type', $first) : [], 'state' => $statement->errorCode(),
            ], JSON_THROW_ON_ERROR) . "\n";
        } while ($statement->nextRowset());
        echo 'DIAGNOSTIC close ' . json_encode(['confirmed' => $statement->closeCursor(), 'state' => $statement->errorCode()], JSON_THROW_ON_ERROR) . "\n";
    }
    contractProbeAssert($runtimeReady, 'actual production runtime readiness accepts installed native procedure');
    $runtimeUser = 'kcdef_runtime_' . bin2hex(random_bytes(6));
    $runtimePassword = bin2hex(random_bytes(24));
    $runtimeAccount = $database->quote($runtimeUser) . "@'%'";
    $database->exec("CREATE USER $runtimeAccount IDENTIFIED BY " . $database->quote($runtimePassword));
    $runtimeAccountCreated = true;
    $database->exec("GRANT SELECT ON `$schema`.* TO $runtimeAccount");
    $database->exec("GRANT EXECUTE ON PROCEDURE `$schema`.kadupul_cdef_reference_status TO $runtimeAccount");
    $runtimeDsn = preg_replace('/(?:^|;)dbname=[^;]*/i', '', substr($dsn, strlen('mysql:')));
    $runtime = new PDO('mysql:' . ltrim($runtimeDsn, ';'), $runtimeUser, $runtimePassword, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $runtime->exec("USE `$schema`");
    $runtimeReadiness = new CdefReferenceReadiness($runtime, 1);
    contractProbeAssert($runtimeReadiness->ready(), 'actual production API accepts SELECT and scoped EXECUTE runtime without DDL grants');
    $body = $runtime->query("SELECT ROUTINE_DEFINITION FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=DATABASE()"
        . " AND ROUTINE_NAME='kadupul_cdef_reference_status'")->fetchColumn();
    contractProbeAssert($body === null || $body === false || $body === '', 'limited runtime readiness does not require hidden routine body');
    $database->exec('DROP TRIGGER kadupul_cdef_cdef_update');
    contractProbeAssert(!$runtimeReadiness->ready(), 'actual limited production runtime refuses missing native guard');
    $contract->install();
    contractProbeAssert($runtimeReadiness->ready(), 'actual limited production runtime restored only after exact installation');
    $runtime = null;

    $contract->install();
    contractProbeAssert($contract->ready(), 'normal repeated installation is idempotent');
    $mariaDb = str_contains($serverVersion, 'MariaDB');
    $hide = $mariaDb ? 'IGNORED' : 'INVISIBLE';
    $show = $mariaDb ? 'NOT IGNORED' : 'VISIBLE';
    $database->exec('ALTER TABLE aggregate_graph_templates_item ALTER INDEX kadupul_cdef_reference ' . $hide);
    contractProbeRefused(fn() => $contract->ready(), 'usable full-column index');
    contractProbeRefused(fn() => $contract->install(), 'usable full-column index');
    $database->exec('ALTER TABLE aggregate_graph_templates_item ALTER INDEX kadupul_cdef_reference ' . $show);
    contractProbeAssert($contract->ready(), 'native index usability restored only by explicit fixture operation');
    $database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);
    $contract->install();
    contractProbeAssert($contract->ready(), 'silent PDO capability refusal is checked natively');
    $database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $database->beginTransaction();
    $database->exec('INSERT INTO cdef VALUES (9)');
    contractProbeRefused(fn() => $contract->install(), 'caller transaction');
    contractProbeAssert(
        $database->inTransaction() && (int) $database->query('SELECT COUNT(*) FROM cdef WHERE id=9')->fetchColumn() === 1,
        'native caller transaction and write preserved'
    );
    $database->rollBack();

    foreach (['cdef', 'cdef_items', 'graph_templates_item', 'aggregate_graph_templates_item', 'aggregate_graphs_graph_item'] as $table) {
        $original = $database->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_ASSOC)['Create Table'] ?? '';
        $temporary = preg_replace('/^CREATE TABLE /', 'CREATE TEMPORARY TABLE ', $original, 1, $replacements);
        if ($replacements !== 1 || !is_string($temporary)) {
            throw new RuntimeException('Cannot create the exclusively owned temporary fixture shadow.');
        }
        $database->exec($temporary);
        try {
            contractProbeRefused(fn() => $contract->ready(), 'persistent InnoDB');
        } finally {
            $database->exec("DROP TEMPORARY TABLE `$table`");
        }
    }
    foreach (['graph_templates_item', 'aggregate_graph_templates_item', 'aggregate_graphs_graph_item'] as $table) {
        $trigger = 'kadupul_cdef_' . $table . '_insert';
        $database->exec("DROP TRIGGER `$trigger`");
        $database->exec("INSERT INTO `$table` VALUES (1,999)");
        contractProbeRefused(fn() => $contract->install(), 'Existing CDEF references are unsafe');
        $database->exec("DELETE FROM `$table`");
        $contract->install();
        contractProbeAssert($contract->ready(), "exact partial contract on $table completed after explicit fixture repair");
    }
    foreach (["(1,999,1,'1')", "(1,8,5,'07')", "(1,8,5,'999')"] as $values) {
        $database->exec('DROP TRIGGER kadupul_cdef_cdef_items_insert');
        $database->exec('INSERT INTO cdef_items VALUES ' . $values);
        contractProbeRefused(fn() => $contract->install(), 'Existing CDEF references are unsafe');
        $database->exec('DELETE FROM cdef_items');
        $contract->install();
    }
    $database->exec('CREATE TRIGGER operator_cdef_before BEFORE INSERT ON cdef FOR EACH ROW SET NEW.id=NEW.id');
    contractProbeRefused(fn() => $contract->install(), 'explicit operator review');
    contractProbeAssert(
        (int) $database->query("SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA='$schema' AND TRIGGER_NAME='operator_cdef_before'")->fetchColumn() === 1,
        'operator trigger was preserved'
    );
    $database->exec('DROP TRIGGER operator_cdef_before');
    $database->exec('DROP TRIGGER kadupul_cdef_cdef_update');
    $database->exec('CREATE TRIGGER kadupul_cdef_cdef_update BEFORE UPDATE ON cdef FOR EACH ROW SET NEW.id=NEW.id');
    contractProbeRefused(fn() => $contract->install(), 'differs from the reviewed definition');
    contractProbeAssert(
        (int) $database->query("SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA='$schema' AND TRIGGER_NAME='kadupul_cdef_cdef_update'")->fetchColumn() === 1,
        'conflicting named trigger was preserved'
    );
    echo "PASS native contract capability and preflight probe complete\n";
} finally {
    if ($database->inTransaction()) {
        $database->rollBack();
    }
    if ($runtimeAccountCreated) {
        $database->exec("DROP USER IF EXISTS $runtimeAccount");
    }
    if ($created) {
        $database->exec("DROP DATABASE `$schema`");
    }
}

echo "PASS native contract and cleanup complete\n";
