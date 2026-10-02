<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

require dirname(__DIR__, 2) . '/include/vendor/autoload.php';

use Kadupul\Graphing\Application\Port\PaletteColorAccess;
use Kadupul\Graphing\Infrastructure\Legacy\LegacyPaletteColorStore;
use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\AuditEvent;
use Kadupul\IdentityAccess\Contract\AuditTrail;
use Kadupul\Platform\Contract\DatabaseConnection;
use Kadupul\Platform\Contract\LegacyConfiguration;

foreach (['insert', 'update', 'import', 'dependency-read'] as $operation) {
    $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $db->exec("CREATE TABLE colors (id INTEGER PRIMARY KEY AUTOINCREMENT,name TEXT,hex TEXT COLLATE NOCASE UNIQUE,read_only TEXT);
        CREATE TABLE graph_templates_item(id INTEGER PRIMARY KEY,color_id INT,graph_template_id INT,local_graph_id INT);
        CREATE TABLE color_template_items(color_id INT); CREATE TABLE settings(name TEXT,value TEXT);
        INSERT INTO colors(id,name,hex,read_only) VALUES(7,'original','abc','');");
    $connection = new class ($db) implements DatabaseConnection {
        public function __construct(private PDO $db) {}
        public function get(): PDO
        {
            return $this->db;
        }
    };
    $access = new class implements PaletteColorAccess {
        public function authorize(): Actor
        {
            return new Actor(9, 'fixture');
        }
        public function assertCurrent(int $actorId): void {}
    };
    $audit = new class implements AuditTrail {
        public array $events = [];
        public function record(AuditEvent $event): void
        {
            $this->events[] = $event;
        }
    };
    $configuration = new class implements LegacyConfiguration {
        public function values(): array
        {
            return [];
        }
    };
    $store = new LegacyPaletteColorStore($connection, $access, $audit, $configuration);
    $revision = $store->find(7)->revision;
    $snapshot = $store->snapshot();
    $before = $db->query('SELECT * FROM colors')->fetchAll(PDO::FETCH_ASSOC);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);
    if ($operation === 'dependency-read') {
        $db->exec('DROP TABLE graph_templates_item');
        $db->exec("CREATE VIEW graph_templates_item AS SELECT json_extract('invalid-json', '$') AS color_id");
        $probe = $db->prepare('SELECT color_id FROM graph_templates_item WHERE color_id IN (?)');
        $executed = $probe->execute([7]);
        $failureState = $probe->errorCode();
    } else {
        $event = $operation === 'update' ? 'UPDATE' : 'INSERT';
        $db->exec("CREATE TRIGGER reject_palette_write BEFORE $event ON colors BEGIN SELECT RAISE(FAIL, 'Fixture rejected write'); END");
        $failureState = null;
    }
    $error = null;
    $result = null;
    try {
        $result = match ($operation) {
            'insert' => $store->save(9, null, 'new', 'def', null),
            'update' => $store->save(9, 7, 'changed', 'abc', $revision),
            'import' => $store->import(9, [['name' => 'changed', 'hex' => 'abc'], ['name' => 'new', 'hex' => 'def']], true, $snapshot),
            'dependency-read' => $store->delete(9, [7], [7 => $revision]),
        };
    } catch (Throwable $caught) {
        $error = $caught;
    }
    $after = $db->query('SELECT * FROM colors')->fetchAll(PDO::FETCH_ASSOC);
    if (!$error instanceof PDOException || ($error->errorInfo[0] ?? '00000') === '00000'
        || $before !== $after || $db->inTransaction()
        || $audit->events[array_key_last($audit->events)]->outcome !== AuditEvent::FAILED
        || ($operation === 'dependency-read' && ($executed !== false || $failureState !== 'HY000'))) {
        throw new RuntimeException('Silent palette SQL failure was not safely refused: ' . $operation);
    }
}
echo 'PALETTE_SILENT_SQL_OK';
