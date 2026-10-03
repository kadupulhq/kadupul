<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

require dirname(__DIR__, 2) . '/lib/api_aggregate.php';
require dirname(__DIR__, 2) . '/include/global_constants.php';
function cacti_log(...$arguments): void {}
final class AggregateCursorStatement extends PDOStatement
{
    private bool $late = false;
    protected function __construct(private string $fault) {}
    public function closeCursor(): bool
    {
        $closed = parent::closeCursor();
        if (str_starts_with($this->queryString, 'INSERT INTO aggregate_graphs_graph_item')) {
            if ($this->fault === 'throw') throw new RuntimeException('original fixture cursor close failure');
            if ($this->fault === 'false') return false;
            if ($this->fault === 'late-state') $this->late = true;
        }
        return $closed;
    }
    public function errorCode(): ?string
    {
        return $this->late ? 'HY000' : parent::errorCode();
    }
}
$fault = $argv[1];
$caller = ($argv[2] ?? '') === 'caller';
$file = tempnam(sys_get_temp_dir(), 'aggregate-cursor-');
if ($file === false) throw new RuntimeException('Cannot allocate owned SQLite fixture.');
try {
    chmod($file, 0600);
    $database = new PDO('sqlite:' . $file, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $observer = new PDO('sqlite:' . $file, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $database->exec('CREATE TABLE aggregate_graphs_graph_item(aggregate_graph_id INTEGER,graph_templates_item_id INTEGER,sequence INTEGER,color_template INTEGER,t_graph_type_id TEXT,graph_type_id INTEGER,t_cdef_id TEXT,cdef_id INTEGER,item_skip TEXT,item_total TEXT)');
    $database->exec('CREATE TABLE prior_work(value TEXT)');
    $database->exec('INSERT INTO aggregate_graphs_graph_item(aggregate_graph_id,graph_templates_item_id) VALUES(1,100)');
    $before = $observer->query('SELECT * FROM aggregate_graphs_graph_item')->fetchAll(PDO::FETCH_ASSOC);
    $database->setAttribute(PDO::ATTR_STATEMENT_CLASS, [AggregateCursorStatement::class,[$fault]]);
    $database_hostname = 'cursor-fixture';
    $database_port = 0;
    $database_default = 'cursor-fixture';
    $database_sessions = ['cursor-fixture:0:cursor-fixture' => $database];
    if ($caller) {
        $database->beginTransaction();
        $database->exec("INSERT INTO prior_work VALUES('kept')");
    }
    $saved = aggregate_graph_items_save([['aggregate_graph_id' => 1,'graph_templates_item_id' => 11]], 'aggregate_graphs_graph_item');
    $rows = $database->query('SELECT * FROM aggregate_graphs_graph_item')->fetchAll(PDO::FETCH_ASSOC);
    $persisted = $observer->query('SELECT * FROM aggregate_graphs_graph_item')->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['saved' => $saved,'rows_preserved' => $rows === $before,'independent_preserved' => $persisted === $before,
        'caller_owned' => $database->inTransaction() === $caller,'prior_work' => $caller ? $database->query('SELECT value FROM prior_work')->fetchColumn() : null], JSON_THROW_ON_ERROR);
    if ($database->inTransaction()) $database->rollBack();
} finally {
    if (isset($database) && $database->inTransaction()) $database->rollBack();
    unset($observer,$database);
    unlink($file);
}
