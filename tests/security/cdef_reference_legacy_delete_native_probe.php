<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

require dirname(__DIR__, 2) . '/lib/cdef_reference.php';

use Kadupul\GraphDefinition\Infrastructure\Legacy\LegacyCdefDeletion;
use Kadupul\Platform\Infrastructure\Legacy\CdefReferenceContract;

function deletionAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
    echo "PASS $message\n";
}

function deletionSnapshot(PDO $database): array
{
    $snapshot = [];
    foreach (['cdef', 'cdef_items', 'graph_templates_item', 'aggregate_graph_templates_item', 'aggregate_graphs_graph_item'] as $table) {
        $snapshot[$table] = $database->query("SELECT * FROM `$table` ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    }

    return $snapshot;
}

function deletionRefused(PDO $database, array $selection, string $expected): void
{
    $before = deletionSnapshot($database);
    try {
        (new LegacyCdefDeletion($database, 1, new \Kadupul\Platform\Infrastructure\Legacy\CdefReferenceReadiness($database, 1)))->delete($selection);
    } catch (RuntimeException $error) {
        deletionAssert(str_contains($error->getMessage(), $expected), $expected);
        if ($expected !== 'caller transaction') {
            deletionAssert(!$database->inTransaction() && $database->errorCode() === '00000', 'native owned rollback leaves confirmed successful SQLSTATE and no transaction');
        }
        deletionAssert($before === deletionSnapshot($database), 'refused deletion preserves every parent child and cache row');

        return;
    }
    throw new RuntimeException('Expected native deletion refusal was not observed.');
}

$dsn = getenv('KADUPUL_REFERENCE_TEST_DSN');
if ($dsn === false || !str_starts_with($dsn, 'mysql:')) {
    throw new RuntimeException('An explicitly configured native deletion probe DSN is required.');
}
$database = new PDO($dsn, getenv('KADUPUL_REFERENCE_TEST_USER') ?: '', getenv('KADUPUL_REFERENCE_TEST_PASSWORD') ?: '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false,
]);
$schema = 'kadupul_cdef_delete_' . bin2hex(random_bytes(8));
$created = false;
try {
    echo 'SERVER ' . $database->query('SELECT VERSION()')->fetchColumn() . "\n";
    $database->exec("CREATE DATABASE `$schema`");
    $created = true;
    $database->exec("USE `$schema`");
    foreach ([
        'cdef' => 'id MEDIUMINT UNSIGNED PRIMARY KEY, `system` MEDIUMINT UNSIGNED NULL DEFAULT 0',
        'cdef_items' => 'id INT UNSIGNED PRIMARY KEY, cdef_id MEDIUMINT UNSIGNED NOT NULL, type TINYINT UNSIGNED NOT NULL, value VARCHAR(150) NOT NULL, INDEX owner (cdef_id)',
        'graph_templates_item' => 'id INT UNSIGNED PRIMARY KEY, cdef_id MEDIUMINT UNSIGNED NOT NULL DEFAULT 0, INDEX reference_id (cdef_id)',
        'aggregate_graph_templates_item' => 'id INT UNSIGNED PRIMARY KEY, cdef_id MEDIUMINT UNSIGNED NULL',
        'aggregate_graphs_graph_item' => 'id INT UNSIGNED PRIMARY KEY, cdef_id MEDIUMINT UNSIGNED NULL',
    ] as $table => $columns) {
        $database->exec("CREATE TABLE `$table` ($columns) ENGINE=InnoDB");
    }
    $database->exec('INSERT INTO cdef VALUES (7,0),(8,0),(9,1),(11,NULL)');
    (new CdefReferenceContract($database, 1))->install();
    $database->exec("INSERT INTO cdef_items VALUES (107,7,5,'8'),(108,8,1,'1'),(109,9,1,'1'),(111,11,1,'1')");
    $database->beginTransaction();
    deletionAssert($database->errorCode() === '00000', 'native successful begin has confirmed SQLSTATE');
    $database->exec('INSERT INTO cdef VALUES (10,0)');
    deletionRefused($database, [7], 'caller transaction');
    deletionAssert($database->inTransaction(), 'native caller transaction remains active');
    $database->rollBack();
    deletionAssert($database->errorCode() === '00000', 'native successful caller rollback has confirmed SQLSTATE');
    deletionRefused($database, [7,9], 'not eligible');
    deletionRefused($database, [7,11], 'not eligible');
    deletionRefused($database, [7,16777215], 'changed');
    foreach (['graph_templates_item', 'aggregate_graph_templates_item', 'aggregate_graphs_graph_item'] as $table) {
        $database->exec("INSERT INTO `$table` VALUES (1,7)");
        deletionRefused($database, [7], 'still referenced');
        $database->exec("DELETE FROM `$table`");
    }
    $database->exec("INSERT INTO cdef_items VALUES (120,8,5,'7')");
    deletionRefused($database, [7], 'still referenced by another');
    $database->exec('DELETE FROM cdef_items WHERE id=120');
    // A genuine AFTER trigger refuses the parent statement only after the
    // helper has deleted owned children. All changes must roll back together.
    $database->exec("CREATE TRIGGER fixture_delete_failure AFTER DELETE ON cdef FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Native fixture parent refusal'");
    $database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);
    deletionRefused($database, [7], 'SQL could not be confirmed');
    deletionAssert(!$database->inTransaction(), 'native silent failed parent statement ends only owned transaction');
    $database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $database->exec('DROP TRIGGER fixture_delete_failure');
    (new LegacyCdefDeletion($database, 1, new \Kadupul\Platform\Infrastructure\Legacy\CdefReferenceReadiness($database, 1)))->delete([7]);
    deletionAssert(
        (int) $database->query('SELECT COUNT(*) FROM cdef WHERE id=7')->fetchColumn() === 0
        && (int) $database->query('SELECT COUNT(*) FROM cdef_items WHERE cdef_id=7')->fetchColumn() === 0,
        'actual native helper deletes exact selected parent and its owned children'
    );
    deletionAssert(
        (int) $database->query('SELECT COUNT(*) FROM cdef')->fetchColumn() === 3
        && (int) $database->query('SELECT COUNT(*) FROM cdef_items')->fetchColumn() === 3,
        'actual native helper preserves every unselected parent and child'
    );
    echo "PASS native atomic legacy deletion probe complete\n";
} finally {
    if ($database->inTransaction()) {
        $database->rollBack();
    }
    if ($created) {
        $database->exec("DROP DATABASE `$schema`");
    }
}

echo "PASS native legacy deletion and cleanup complete\n";
