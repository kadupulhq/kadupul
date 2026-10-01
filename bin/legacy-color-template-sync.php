<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors.
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use Kadupul\ColorTemplates\Infrastructure\Legacy\LegacyColorTemplateAccess;
use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Platform\Contract\DatabaseConnection;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('KADUPUL_REDACT_DATABASE_LOGS', true);
ob_start();
require_once __DIR__ . '/../include/vendor/autoload.php';
require __DIR__ . '/../include/cli_check.php';
require_once __DIR__ . '/../lib/api_aggregate.php';

$status = 'failed';
$summary = [];
$diagnostic = null;
try {
    $input = stream_get_contents(STDIN, 4097);
    if (strlen($input) > 4096) {
        throw new InvalidArgumentException('Payload too large.');
    }
    $wireCommand = json_decode($input, false, 8, JSON_THROW_ON_ERROR);
    if (!$wireCommand instanceof stdClass) {
        throw new InvalidArgumentException('Invalid sync command.');
    }
    $command = get_object_vars($wireCommand);
    if (array_diff(array_keys($command), ['actor', 'template_id']) !== []
        || !is_int($command['actor'] ?? null) || $command['actor'] < 1
        || !is_int($command['template_id'] ?? null) || $command['template_id'] < 1) {
        throw new InvalidArgumentException('Invalid sync command.');
    }
    $db = $database_sessions["$database_hostname:$database_port:$database_default"];
    if ((int) ($config['poller_id'] ?? 0) !== 1) {
        throw new RuntimeException('Primary installation required.');
    }
    $writeTables = ['aggregate_graphs', 'aggregate_graphs_items', 'aggregate_graphs_graph_item', 'aggregate_graph_templates', 'aggregate_graph_templates_item', 'graph_local', 'graph_templates_item', 'graph_templates_graph'];
    $engineQuery = $db->prepare('SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN (' . implode(',', array_fill(0, count($writeTables), '?')) . ')');
    $engineQuery->execute($writeTables);
    $engines = [];
    foreach ($engineQuery->fetchAll(PDO::FETCH_ASSOC) as $table) {
        $engines[(string) $table['TABLE_NAME']] = strtoupper((string) $table['ENGINE']);
    }
    foreach ($writeTables as $table) {
        if (($engines[$table] ?? '') !== 'INNODB') {
            throw new RuntimeException('Aggregate synchronization storage is unavailable.');
        }
    }
    if ($db->inTransaction() || !$db->beginTransaction()) {
        throw new RuntimeException('Sync transaction unavailable.');
    }
    $console = new class ($command['actor']) implements ConsoleAccess {
        public function __construct(private int $id) {}
        public function consoleActor(): ?Actor
        {
            return new Actor($this->id, 'color-sync-worker');
        }
        public function canManageDevices(Actor $actor): bool
        {
            return false;
        }
    };
    $database = new class ($db) implements DatabaseConnection {
        public function __construct(private PDO $connection) {}
        public function get(): PDO
        {
            return $this->connection;
        }
    };
    (new LegacyColorTemplateAccess($console, $database))->assertCurrent($command['actor']);
    $_SESSION = ['sess_user_id' => $command['actor']];
    define('KADUPUL_THROW_DATABASE_ERRORS', true);
    $database_last_error = '';
    $template = $db->prepare('SELECT color_template_id,name FROM color_templates WHERE color_template_id=? FOR UPDATE');
    $template->execute([$command['template_id']]);
    $row = $template->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new InvalidArgumentException('Color template not found.');
    }
    $aggregateTemplates = db_fetch_assoc_prepared('SELECT DISTINCT aggregate_template_id FROM aggregate_graph_templates_item WHERE color_template=? ORDER BY aggregate_template_id', [$command['template_id']]);
    foreach ($aggregateTemplates as $aggregateTemplate) {
        push_out_aggregates((int) $aggregateTemplate['aggregate_template_id']);
    }
    $aggregateGraphs = db_fetch_assoc_prepared('SELECT DISTINCT ag.aggregate_template_id, ag.local_graph_id
        FROM aggregate_graphs_graph_item agi INNER JOIN aggregate_graphs ag ON ag.id=agi.aggregate_graph_id
        WHERE agi.color_template=? AND ((ag.aggregate_template_id > 0 AND ag.template_propogation = "") OR ag.aggregate_template_id = 0)
        ORDER BY ag.aggregate_template_id, ag.local_graph_id', [$command['template_id']]);
    foreach ($aggregateGraphs as $aggregateGraph) {
        push_out_aggregates((int) $aggregateGraph['aggregate_template_id'], (int) $aggregateGraph['local_graph_id']);
    }
    if (!$db->commit()) {
        throw new RuntimeException('Sync commit failed.');
    }
    $status = 'ok';
    $summary = ['template' => (string) $row['name'], 'aggregate_templates' => count($aggregateTemplates), 'aggregate_graphs' => count($aggregateGraphs)];
} catch (Throwable $error) {
    $diagnostic = $error::class . ' in ' . basename($error->getFile()) . ':' . $error->getLine();
    if (isset($database_last_error) && preg_match('/Error ([0-9]+):/', $database_last_error, $databaseCode)) {
        $diagnostic .= ' (database error ' . $databaseCode[1] . ')';
    }
    if (preg_match('/Interface "([A-Za-z0-9_\\\\]+)" not found/', $error->getMessage(), $missing)) {
        $diagnostic .= ' (missing ' . $missing[1] . ')';
    }
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    $status = $error instanceof InvalidArgumentException ? 'invalid' : ($error instanceof \Kadupul\ColorTemplates\Application\Query\ColorTemplateAccessDenied ? 'denied' : 'failed');
}

$unexpected = (string) ob_get_clean();
if ($unexpected !== '') {
    $status = 'failed';
    $summary = [];
}
echo 'KADUPUL_COLOR_SYNC_RESULT=' . json_encode(['actor' => $command['actor'] ?? null, 'template_id' => $command['template_id'] ?? null, 'status' => $status, 'summary' => $summary, 'diagnostic' => $diagnostic], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) . PHP_EOL;
exit($status === 'ok' ? 0 : 1);
