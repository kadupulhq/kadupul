<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use Kadupul\Graphing\Application\Query\ColorTemplateAccessDenied;
use Kadupul\Graphing\Infrastructure\Legacy\ColorTemplateSql;
use Kadupul\Graphing\Infrastructure\Legacy\LegacyColorTemplateAccess;
use Kadupul\Graphing\Infrastructure\Legacy\LegacyColorTemplateStore;
use Kadupul\Graphing\Infrastructure\Legacy\LegacyColorTemplateSynchronization;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// Isolate procedural globals, plugin hooks and poller effects from Symfony HTTP.
define('KADUPUL_REDACT_DATABASE_LOGS', true);
ob_start();
require __DIR__ . '/../include/cli_check.php';
require_once __DIR__ . '/../lib/api_aggregate.php';

$status = 'failed';
$summary = null;
$db = null;
try {
    $input = stream_get_contents(STDIN, 4097);
    if (strlen($input) > 4096) {
        throw new InvalidArgumentException('Payload too large');
    }
    $command = json_decode($input, true, 4, JSON_THROW_ON_ERROR);
    if (!is_array($command) || array_diff(array_keys($command), ['actor', 'template_id', 'revision']) !== []
        || !is_int($command['actor'] ?? null) || $command['actor'] < 1
        || !is_int($command['template_id'] ?? null) || $command['template_id'] < 1 || $command['template_id'] > 16777215
        || !is_string($command['revision'] ?? null)) {
        throw new InvalidArgumentException('Invalid command');
    }
    $db = $database_sessions["$database_hostname:$database_port:$database_default"] ?? null;
    if (!$db instanceof PDO || (int) ($config['poller_id'] ?? 0) !== 1
        || $db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ') === false || !$db->beginTransaction()) {
        throw new RuntimeException('Primary transaction unavailable');
    }
    try {
        LegacyColorTemplateAccess::assertGranted($db, $command['actor']);
    } catch (ColorTemplateAccessDenied) {
        $status = 'denied';
        throw new RuntimeException('Access denied');
    }
    // Lock the template and its items so the colors pushed out are the ones confirmed.
    $revision = LegacyColorTemplateStore::revisions($db, [$command['template_id']], true)[$command['template_id']] ?? null;
    if ($revision === null || !hash_equals($revision, $command['revision'])) {
        $status = 'changed';
        throw new RuntimeException('Color template changed');
    }
    $name = (string) ColorTemplateSql::column(ColorTemplateSql::execute($db, 'SELECT name FROM color_templates WHERE color_template_id = ?', [$command['template_id']]));
    $_SESSION = ['sess_user_id' => $command['actor']];
    define('KADUPUL_THROW_DATABASE_ERRORS', true);

    // The same two sets the legacy sync_color_templates() pushed out, in a stable order.
    $templates = array_map('intval', array_column(ColorTemplateSql::all(ColorTemplateSql::execute($db, 'SELECT DISTINCT aggregate_template_id FROM aggregate_graph_templates_item WHERE color_template = ? ORDER BY aggregate_template_id', [$command['template_id']])), 'aggregate_template_id'));
    foreach ($templates as $templateId) {
        if (push_out_aggregates($templateId) !== true) {
            throw new RuntimeException('Aggregate template push out failed');
        }
    }
    $graphs = ColorTemplateSql::all(ColorTemplateSql::execute($db, "SELECT DISTINCT ag.aggregate_template_id, ag.local_graph_id
        FROM aggregate_graphs_graph_item agi INNER JOIN aggregate_graphs ag ON ag.id = agi.aggregate_graph_id
        WHERE ((ag.aggregate_template_id > 0 AND ag.template_propogation = '') OR ag.aggregate_template_id = 0)
        AND agi.color_template = ? ORDER BY ag.aggregate_template_id, ag.local_graph_id", [$command['template_id']]));
    foreach ($graphs as $graph) {
        if (push_out_aggregates((int) $graph['aggregate_template_id'], (int) $graph['local_graph_id']) !== true) {
            throw new RuntimeException('Aggregate graph push out failed');
        }
    }
    if (!$db->commit()) {
        throw new RuntimeException('Commit not confirmed');
    }
    $status = 'ok';
    $summary = ['template' => $name, 'aggregate_templates' => count($templates), 'aggregate_graphs' => count($graphs)];
} catch (Throwable) {
    if ($db instanceof PDO && $db->inTransaction()) {
        try {
            $db->rollBack();
        } catch (Throwable) {
            // The connection closes with the process, which rolls the transaction back.
        }
    }
}

// Legacy code may print; only the marker line reaches the caller.
ob_end_clean();
print LegacyColorTemplateSynchronization::result($status, $summary) . PHP_EOL;
exit($status === 'ok' ? 0 : 1);
