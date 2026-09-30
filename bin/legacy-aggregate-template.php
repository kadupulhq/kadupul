<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

use Kadupul\AggregateTemplate\Domain\AggregateTemplateRevision;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('KADUPUL_REDACT_DATABASE_LOGS', true);
ob_start();
require __DIR__ . '/../include/cli_check.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/api_aggregate.php';
require_once __DIR__ . '/../lib/api_graph.php';
require_once __DIR__ . '/../lib/api_data_source.php';
require_once __DIR__ . '/../lib/template.php';
require_once __DIR__ . '/../lib/utility.php';

$status = 'failed';
$actor = 0;
$action = '';
$targetIds = [];
$transactionStarted = false;
$connection = null;
$phase = 'input';

try {
    $input = stream_get_contents(STDIN, 131073);
    if (strlen($input) > 131072) {
        throw new RuntimeException('Payload too large');
    }
    $command = json_decode($input, true, 16, JSON_THROW_ON_ERROR);
    if (!is_array($command) || !is_int($command['actor'] ?? null) || $command['actor'] < 1
        || !is_string($command['action'] ?? null) || !in_array($command['action'], ['save', 'delete'], true)) {
        throw new RuntimeException('Invalid command');
    }
    $actor = $command['actor'];
    $action = $command['action'];
    $connectionKey = "$database_hostname:$database_port:$database_default";
    $connection = $database_sessions[$connectionKey] ?? null;
    if (!$connection instanceof PDO || (int) ($config['poller_id'] ?? 0) !== 1) {
        throw new RuntimeException('Primary database unavailable');
    }

    $phase = 'primary transaction';
    define('KADUPUL_THROW_DATABASE_ERRORS', true);
    if (!db_execute('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ') || !db_begin_transaction()) {
        throw new RuntimeException('Primary transaction unavailable');
    }
    $transactionStarted = true;
    $database_last_error = '';
    aggregateWorkerRequireTransactionalTables($connection);
    $phase = 'authorization';
    aggregateWorkerAuthorize($connection, $actor);

    if ($action === 'save') {
        $phase = 'save validation';
        $id = filter_var($command['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        $revision = $command['revision'] ?? null;
        $data = $command['data'] ?? null;
        if ($id === false || !is_string($revision) || !is_array($data)) {
            throw new RuntimeException('Invalid save request');
        }
        $targetIds = [$id];
        $isNew = $id === 0;
        $beforeRevision = null;
        if ($id > 0) {
            $locked = aggregateWorkerRead($connection, 'SELECT id FROM aggregate_graph_templates WHERE id = ? FOR UPDATE', [$id]);
            if (count($locked) !== 1) {
                throw new AggregateTemplateWorkerConflict('Aggregate template no longer exists.');
            }
            $beforeRevision = aggregateWorkerRevision($connection, $id);
            if (!hash_equals($beforeRevision, $revision)) {
                throw new AggregateTemplateWorkerConflict('Aggregate template changed while being edited.');
            }
        }

        $clean = aggregateWorkerValidate($connection, $data, $id);
        $phase = 'template write';
        $_POST = $clean['post'];
        $save = $clean['template'];
        if ($id === 0) {
            $save['user_id'] = $actor;
            $id = (int) sql_save($save, 'aggregate_graph_templates', 'id');
            if ($id < 1) {
                throw new RuntimeException('Template insert failed');
            }
            $targetIds = [$id];
        } else {
            $save['id'] = $id;
            $save['user_id'] = $actor;
            if (!sql_save($save, 'aggregate_graph_templates', 'id')) {
                throw new RuntimeException('Template update failed');
            }
        }

        $settings = aggregate_validate_graph_params($_POST, true);
        $phase = 'graph setting write';
        $settings['aggregate_template_id'] = $id;
        sql_save($settings, 'aggregate_graph_templates_graph', 'aggregate_template_id', false);

        $sourceItems = aggregateWorkerRead($connection, 'SELECT id, sequence FROM graph_templates_item WHERE local_graph_id = 0 AND graph_template_id = ? ORDER BY sequence, id', [$save['graph_template_id']]);
        $phase = 'item write';
        $items = [];
        foreach ($sourceItems as $item) {
            $items[(int) $item['id']] = ['sequence' => (int) $item['sequence']];
        }
        aggregate_validate_graph_items($_POST, $items);
        $oldRows = aggregateWorkerRead($connection, 'SELECT * FROM aggregate_graph_templates_item WHERE aggregate_template_id = ?', [$id]);
        $old = [];
        foreach ($oldRows as $row) {
            $old[(int) $row['graph_templates_item_id']] = $row;
        }
        $saveItems = [];
        foreach ($items as $itemId => $item) {
            $saveItems[] = array_merge($old[$itemId] ?? [], [
                'aggregate_template_id' => $id,
                'graph_templates_item_id' => $itemId,
                'sequence' => $item['sequence'],
                'color_template' => $item['color_template'] ?? 0,
                'item_skip' => isset($item['item_skip']) ? 'on' : '',
                'item_total' => isset($item['item_total']) ? 'on' : '',
            ]);
        }
        if ($saveItems !== [] && !aggregate_graph_items_save($saveItems, 'aggregate_graph_templates_item')) {
            throw new RuntimeException('Aggregate item save failed');
        }
        if ($saveItems === []) {
            $connection->prepare('DELETE FROM aggregate_graph_templates_item WHERE aggregate_template_id = ?')->execute([$id]);
        }

        // The legacy propagation mutates dependent graph definitions and item rows.
        // It runs inside this worker-owned primary transaction with SQL failures throwing.
        $changed = $isNew || !hash_equals((string) $beforeRevision, aggregateWorkerRevision($connection, $id));
        if ($changed) {
            $phase = 'dependent graph propagation';
            push_out_aggregates($id);
            if (db_error() !== '' || is_error_message() || !$connection->inTransaction()) {
                throw new RuntimeException('Aggregate propagation could not be confirmed');
            }
        }
        $status = 'ok';
    } else {
        $phase = 'delete validation';
        $revisions = $command['revisions'] ?? null;
        if (!is_array($revisions) || $revisions === [] || count($revisions) > 1000) {
            throw new RuntimeException('Invalid delete selection');
        }
        foreach ($revisions as $key => $revision) {
            if ((!is_int($key) && (!is_string($key) || !ctype_digit($key))) || (int) $key < 1 || !is_string($revision)) {
                throw new RuntimeException('Invalid delete selection');
            }
            $targetIds[] = (int) $key;
        }
        sort($targetIds, SORT_NUMERIC);
        if (count($targetIds) !== count(array_unique($targetIds))) {
            throw new RuntimeException('Duplicate delete selection');
        }
        foreach ($targetIds as $id) {
            $locked = aggregateWorkerRead($connection, 'SELECT id FROM aggregate_graph_templates WHERE id = ? FOR UPDATE', [$id]);
            if (count($locked) !== 1 || !hash_equals(aggregateWorkerRevision($connection, $id), $revisions[(string) $id])) {
                throw new AggregateTemplateWorkerConflict('An aggregate template changed while deletion was being confirmed.');
            }
        }
        $marks = implode(',', array_fill(0, count($targetIds), '?'));
        if (!$connection->prepare("DELETE FROM aggregate_graph_templates WHERE id IN ($marks)")->execute($targetIds)
            || !$connection->prepare("DELETE FROM aggregate_graph_templates_item WHERE aggregate_template_id IN ($marks)")->execute($targetIds)
            || !$connection->prepare("DELETE FROM aggregate_graph_templates_graph WHERE aggregate_template_id IN ($marks)")->execute($targetIds)
            || !$connection->prepare("UPDATE aggregate_graphs SET aggregate_template_id = 0, template_propogation = '' WHERE aggregate_template_id IN ($marks)")->execute($targetIds)) {
            throw new RuntimeException('Aggregate template deletion failed');
        }
        $status = 'ok';
    }
    if (!db_commit_transaction()) {
        throw new RuntimeException('Commit failed');
    }
    $transactionStarted = false;
} catch (AggregateTemplateWorkerConflict) {
    $status = 'conflict';
} catch (AggregateTemplateWorkerDenied) {
    $status = 'denied';
} catch (Throwable $error) {
    // Never expose database diagnostics, SQL, or credentials to the HTTP caller.
    $safeReason = $error instanceof InvalidArgumentException ? ' ' . $error->getMessage() : '';
    cacti_log('Aggregate template worker failed during ' . $phase . ' (' . get_class($error) . ').' . $safeReason, false, 'AGGREGATE');
} finally {
    if ($transactionStarted && $connection instanceof PDO && $connection->inTransaction()) {
        db_rollback_transaction($connection);
    }
}

while (ob_get_level() > 0) {
    ob_end_clean();
}
echo 'KADUPUL_AGGREGATE_RESULT=' . json_encode([
    'actor' => $actor,
    'action' => $action,
    'ids' => $targetIds,
    'status' => $status,
], JSON_THROW_ON_ERROR) . "\n";
exit($status === 'ok' ? 0 : 1);

function aggregateWorkerAuthorize(PDO $connection, int $actorId): void
{
    $read = static function (string $sql, array $params = []) use ($connection): array {
        $query = $connection->prepare($sql);
        if (!$query->execute($params)) {
            throw new RuntimeException('Authorization query failed');
        }
        return $query->fetchAll(PDO::FETCH_ASSOC);
    };
    $auth = $read("SELECT value FROM settings WHERE name = 'auth_method' LOCK IN SHARE MODE");
    if (isset($auth[0]['value']) && !in_array((int) $auth[0]['value'], [1, 2, 3, 4], true)) {
        throw new AggregateTemplateWorkerDenied();
    }
    $users = $read('SELECT id, username, enabled, locked, must_change_password, password_change FROM user_auth WHERE id = ? LOCK IN SHARE MODE', [$actorId]);
    if (count($users) !== 1 || $users[0]['enabled'] !== 'on' || $users[0]['locked'] === 'on'
        || $users[0]['must_change_password'] === 'on') {
        throw new AggregateTemplateWorkerDenied();
    }
    $guest = $read("SELECT value FROM settings WHERE name = 'guest_user' LOCK IN SHARE MODE");
    if ((int) ($guest[0]['value'] ?? 0) === $actorId || (string) ($guest[0]['value'] ?? '') === (string) $users[0]['username']) {
        throw new AggregateTemplateWorkerDenied();
    }
    foreach ([8, 5] as $realm) {
        $direct = $read('SELECT realm_id FROM user_auth_realm WHERE user_id = ? AND realm_id = ? LOCK IN SHARE MODE', [$actorId, $realm]);
        if ($direct !== []) {
            continue;
        }
        $group = $read("SELECT r.realm_id FROM user_auth_group_realm r
            INNER JOIN user_auth_group_members m ON m.group_id = r.group_id
            INNER JOIN user_auth_group g ON g.id = r.group_id AND g.enabled = 'on'
            WHERE m.user_id = ? AND r.realm_id = ? LIMIT 1 LOCK IN SHARE MODE", [$actorId, $realm]);
        if ($group === []) {
            throw new AggregateTemplateWorkerDenied();
        }
    }
}

function aggregateWorkerRead(PDO $connection, string $sql, array $params = []): array
{
    $query = $connection->prepare($sql);
    if (!$query->execute($params)) {
        throw new RuntimeException('Aggregate query failed');
    }
    return $query->fetchAll(PDO::FETCH_ASSOC);
}

function aggregateWorkerRequireTransactionalTables(PDO $connection): void
{
    $tables = [
        'aggregate_graph_templates', 'aggregate_graph_templates_graph', 'aggregate_graph_templates_item',
        'aggregate_graphs', 'aggregate_graphs_graph_item', 'aggregate_graphs_items',
        'graph_local', 'graph_templates_graph', 'graph_templates_item',
    ];
    $marks = implode(',', array_fill(0, count($tables), '?'));
    $query = $connection->prepare("SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ($marks)");
    if (!$query->execute($tables)) {
        throw new RuntimeException('Cannot verify aggregate transaction storage');
    }
    $engines = [];
    foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $engines[$row['TABLE_NAME']] = strtoupper((string) $row['ENGINE']);
    }
    foreach ($tables as $table) {
        if (($engines[$table] ?? '') !== 'INNODB') {
            throw new RuntimeException('Aggregate transaction storage is not fully transactional');
        }
    }
}

function aggregateWorkerRevision(PDO $connection, int $id): string
{
    $template = aggregateWorkerRead($connection, 'SELECT id, name, graph_template_id, gprint_prefix, gprint_format, graph_type, total, total_type, total_prefix, order_type FROM aggregate_graph_templates WHERE id = ?', [$id])[0] ?? null;
    if ($template === null) {
        throw new AggregateTemplateWorkerConflict('Aggregate template no longer exists.');
    }
    $graph = aggregateWorkerRead($connection, 'SELECT * FROM aggregate_graph_templates_graph WHERE aggregate_template_id = ?', [$id])[0] ?? [];
    $items = aggregateWorkerRead($connection, 'SELECT aggregate_template_id, graph_templates_item_id, sequence, color_template, t_graph_type_id, graph_type_id, t_cdef_id, cdef_id, item_skip, item_total FROM aggregate_graph_templates_item WHERE aggregate_template_id = ? ORDER BY graph_templates_item_id', [$id]);
    return AggregateTemplateRevision::fromState($template, $graph, $items);
}

function aggregateWorkerValidate(PDO $connection, array $data, int $id): array
{
    $name = $data['name'] ?? null;
    $sourceId = filter_var($data['graph_template_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $graphType = filter_var($data['graph_type'] ?? null, FILTER_VALIDATE_INT);
    $total = filter_var($data['total'] ?? null, FILTER_VALIDATE_INT);
    $totalType = filter_var($data['total_type'] ?? null, FILTER_VALIDATE_INT);
    $orderType = filter_var($data['order_type'] ?? null, FILTER_VALIDATE_INT);
    if (!is_string($name) || trim($name) === '' || mb_strlen($name) > 64 || $sourceId === false
        || !in_array($graphType, [0, 50, 8, 4, 5, 6, 51, 52, 53], true)
        || !in_array($total, [1, 2, 3], true) || !in_array($totalType, [1, 2], true)
        || !in_array($orderType, [1, 2, 3, 4], true)) {
        throw new InvalidArgumentException('Invalid aggregate template fields.');
    }
    foreach (['gprint_prefix', 'total_prefix'] as $key) {
        if (!is_string($data[$key] ?? '')) {
            throw new InvalidArgumentException('Invalid aggregate prefix.');
        }
        if (mb_strlen($data[$key] ?? '') > 64) {
            throw new InvalidArgumentException('Aggregate prefix is too long.');
        }
    }
    if (!is_bool($data['gprint_format'] ?? false)) {
        throw new InvalidArgumentException('Invalid aggregate prefix option.');
    }
    $source = aggregateWorkerRead($connection, 'SELECT id FROM graph_templates WHERE id = ?', [$sourceId]);
    if (count($source) !== 1) {
        throw new InvalidArgumentException('Source graph template is unavailable.');
    }
    if ($id > 0) {
        $existing = aggregateWorkerRead($connection, 'SELECT graph_template_id FROM aggregate_graph_templates WHERE id = ?', [$id]);
        if (count($existing) !== 1 || (int) $existing[0]['graph_template_id'] !== $sourceId) {
            throw new InvalidArgumentException('Source graph template cannot be changed');
        }
    }
    $post = [
        'alt_y_grid' => '', 'auto_padding' => '', 'auto_scale' => '', 'auto_scale_log' => '', 'auto_scale_opts' => '0',
        'auto_scale_rigid' => '', 'base_value' => '0', 'dynamic_labels' => '0', 'force_rules_legend' => '', 'grouping' => '',
        'height' => '0', 'image_format_id' => '0', 'left_axis_formatter' => '', 'legend_direction' => '', 'legend_position' => '',
        'lower_limit' => '0', 'no_gridfit' => '', 'right_axis' => '', 'right_axis_format' => '', 'right_axis_formatter' => '',
        'right_axis_label' => '', 'scale_log_units' => '', 'slope_mode' => '', 'tab_width' => '', 'unit_exponent_value' => '',
        'unit_length' => '', 'unit_value' => '', 'upper_limit' => '0', 'vertical_label' => '', 'width' => '0',
    ];
    $settings = $data['graphSettings'] ?? [];
    if (!is_array($settings)) {
        throw new InvalidArgumentException('Invalid graph settings');
    }
    foreach ($settings as $field => $setting) {
        if (!is_array($setting)) {
            throw new InvalidArgumentException('Graph setting must be a structured value.');
        }
        if (!array_key_exists($field, $post)) {
            throw new InvalidArgumentException('Graph setting key is not allowed.');
        }
        if (!is_bool($setting['override'] ?? null)) {
            throw new InvalidArgumentException('Graph setting override is invalid.');
        }
        if (!$setting['override']) {
            continue;
        }
        $checkboxFields = ['auto_padding', 'auto_scale', 'auto_scale_log', 'auto_scale_rigid', 'dynamic_labels', 'force_rules_legend', 'no_gridfit', 'scale_log_units', 'slope_mode', 'alt_y_grid'];
        if (in_array($field, $checkboxFields, true)) {
            if (!is_bool($setting['value'] ?? null)) {
                throw new InvalidArgumentException('Graph checkbox value is invalid.');
            }
            $post[$field] = $setting['value'] ? 'on' : '';
        } else {
            if (($setting['value'] ?? null) !== null && !is_string($setting['value']) && !is_int($setting['value']) && !is_float($setting['value'])) {
                throw new InvalidArgumentException('Graph setting value is invalid.');
            }
            $post[$field] = (string) ($setting['value'] ?? '');
        }
        $choices = match ($field) {
            'image_format_id' => [1, 3],
            'auto_scale_opts' => [0, 1, 2, 3, 4],
            'left_axis_formatter', 'right_axis_formatter' => ['', 'numeric', 'timestamp', 'duration'],
            'legend_position' => ['', 'north', 'south', 'west', 'east'],
            'legend_direction' => ['', 'topdown', 'bottomup'],
            default => null,
        };
        if ($choices !== null && !in_array($setting['value'], $choices, false)) {
            throw new InvalidArgumentException('Graph setting choice is invalid.');
        }
        $maxLength = ['vertical_label' => 200, 'right_axis' => 20, 'right_axis_label' => 200, 'unit_value' => 20,
            'unit_exponent_value' => 5, 'unit_length' => 10, 'tab_width' => 20, 'upper_limit' => 20, 'lower_limit' => 20][$field] ?? 255;
        if (is_string($post[$field]) && mb_strlen($post[$field]) > $maxLength) {
            throw new InvalidArgumentException('Graph setting exceeds its maximum length.');
        }
        if ($setting['override']) {
            $post['t_' . $field] = 'on';
        }
    }
    $items = $data['items'] ?? [];
    if (!is_array($items) || count($items) > 10000) {
        throw new InvalidArgumentException('Invalid aggregate items');
    }
    foreach ($items as $item) {
        $itemId = is_array($item) ? filter_var($item['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
        $colorTemplate = is_array($item) ? filter_var($item['colorTemplate'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) : false;
        if (!is_array($item) || $itemId === false
            || !is_bool($item['skip'] ?? null) || !is_bool($item['total'] ?? null)
            || $colorTemplate === false) {
            throw new InvalidArgumentException('Invalid aggregate item');
        }
        $post['agg_color_' . $itemId] = (string) $colorTemplate;
        if ($item['skip']) {
            $post['agg_skip_' . $itemId] = 'on';
        }
        if ($item['total']) {
            $post['agg_total_' . $itemId] = 'on';
        }
    }
    return [
        'post' => $post,
        'template' => [
            'id' => $id,
            'name' => $name,
            'graph_template_id' => $sourceId,
            'gprint_prefix' => $data['gprint_prefix'] ?? '',
            'gprint_format' => ($data['gprint_format'] ?? false) ? 'on' : '',
            'graph_type' => $graphType,
            'total' => $total,
            'total_type' => $totalType,
            'total_prefix' => $data['total_prefix'] ?? '',
            'order_type' => $orderType,
        ],
    ];
}

final class AggregateTemplateWorkerConflict extends RuntimeException {}
final class AggregateTemplateWorkerDenied extends RuntimeException {}
