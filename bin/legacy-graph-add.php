<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// Isolate procedural globals, plugin hooks and session messages from Symfony.
define('KADUPUL_REDACT_DATABASE_LOGS', true);
ob_start();
require __DIR__ . '/../include/cli_check.php';
require_once $config['base_path'] . '/lib/api_automation.php';
require_once $config['base_path'] . '/lib/api_data_source.php';
require_once $config['base_path'] . '/lib/api_graph.php';
require_once $config['base_path'] . '/lib/api_device.php';
require_once $config['base_path'] . '/lib/data_query.php';
require_once $config['base_path'] . '/lib/poller.php';
require_once $config['base_path'] . '/lib/snmp.php';
require_once $config['base_path'] . '/lib/template.php';
require_once $config['base_path'] . '/lib/utility.php';
require_once $config['base_path'] . '/lib/variables.php';

$status = 'failed';
$result = [];
try {
    $input = stream_get_contents(STDIN, 65537);
    if (strlen($input) > 65536) {
        throw new RuntimeException('Payload too large');
    }
    $command = json_decode($input, true, 6, JSON_THROW_ON_ERROR);
    $count = static fn(mixed $value, int $minimum): bool => is_int($value) && $value >= $minimum;
    $valid = is_array($command) && in_array($command['database'] ?? null, ['local', 'main'], true);
    $kind = $valid ? ($command['kind'] ?? null) : null;
    if ($kind === 'query') {
        $valid = array_diff(array_keys($command), ['database', 'kind', 'host_id', 'query_id', 'reindex_method']) === []
            && $count($command['host_id'] ?? null, 1) && $count($command['query_id'] ?? null, 1) && $count($command['reindex_method'] ?? null, 0);
    } elseif ($kind === 'title') {
        $valid = array_diff(array_keys($command), ['database', 'kind', 'graph_id', 'title']) === []
            && $count($command['graph_id'] ?? null, 1) && is_string($command['title'] ?? null);
    } elseif ($kind === 'graph') {
        $valid = array_diff(array_keys($command), ['database', 'kind', 'template_id', 'host_id', 'query_id', 'query_type_id', 'query_index', 'custom_data', 'title']) === []
            && $count($command['template_id'] ?? null, 1) && $count($command['host_id'] ?? null, 1) && is_string($command['title'] ?? null)
            && (($command['query_id'] ?? null) === null || $count($command['query_id'], 1))
            && (($command['query_type_id'] ?? null) === null || $count($command['query_type_id'], 1))
            && (($command['query_index'] ?? null) === null || is_string($command['query_index']))
            && (($command['query_id'] ?? null) === null) === (($command['query_index'] ?? null) === null)
            && is_array($command['custom_data'] ?? null) && array_is_list($command['custom_data']);
        foreach ($valid ? $command['custom_data'] : [] as $entry) {
            $valid = $valid && is_array($entry) && count($entry) === 3 && array_is_list($entry) && is_string($entry[0])
                && ($entry[1] === null || is_string($entry[1])) && ($entry[2] === null || is_string($entry[2]));
        }
    } else {
        $valid = false;
    }
    if (!$valid) {
        throw new RuntimeException('Invalid command');
    }
    // The cli/ scripts worked on the main database from a remote collector; --local keeps the local one.
    if ($command['database'] === 'main' && (int) ($config['poller_id'] ?? 1) > 1) {
        db_switch_remote_to_main();
    }
    if ($kind === 'query') {
        // Recache the data query once; it is slow, so it is done only when the device did not have it.
        db_execute_prepared('REPLACE INTO host_snmp_query (host_id, snmp_query_id, reindex_method) VALUES (?, ?, ?)', [$command['host_id'], $command['query_id'], $command['reindex_method']]);
        run_data_query($command['host_id'], $command['query_id']);
        $status = 'ok';
    } elseif ($kind === 'title') {
        db_execute_prepared('UPDATE graph_templates_graph SET title = ? WHERE local_graph_id = ?', [$command['title'], $command['graph_id']]);
        update_graph_title_cache($command['graph_id']);
        $status = 'ok';
    } else {
        $template = $command['template_id'];
        $host = $command['host_id'];
        $title = $command['title'];
        $plain = $command['query_id'] === null;
        if ($plain) {
            $values = [];
            foreach ($command['custom_data'] as [$dataTemplate, $field, $value]) {
                // Array keys coerce as they did when the script filed the values itself.
                $values[$template]['custom_data'][$dataTemplate][$field] = $value;
            }
            $created = create_complete_graph_from_template($template, $host, null, $values);
            $refused = !is_array($created) || !cacti_sizeof($created);
        } else {
            $query = [
                'snmp_query_id'       => $command['query_id'],
                'snmp_index_on'       => get_best_data_query_index_type($host, $command['query_id']),
                'snmp_query_graph_id' => $command['query_type_id'],
                'snmp_index'          => $command['query_index'],
            ];
            $none = [];
            $created = create_complete_graph_from_template($template, $host, $query, $none);
            $refused = $created === false;
        }
        if ($refused) {
            $result = ['refused' => true];
            $status = 'ok';
        } else {
            $graph = $created['local_graph_id'] ?? null;
            if ($title !== '' && $graph !== null) {
                db_execute_prepared('UPDATE graph_templates_graph SET title = ? WHERE local_graph_id = ?', [$title, $graph]);
                update_graph_title_cache($graph);
            }
            $first = null;
            if (!$plain) {
                $found = db_fetch_cell_prepared('SELECT dtr.local_data_id
                    FROM graph_templates_item AS gti
                    INNER JOIN data_template_rrd AS dtr
                    ON gti.task_item_id = dtr.id
                    WHERE gti.local_graph_id = ?
                    LIMIT 1', [$graph]);
                $first = $found === false || $found === null || $found === '' ? null : (int) $found;
            }
            $sources = [];
            foreach ((array) ($created['local_data_id'] ?? []) as $source) {
                push_out_host($host, $source);
                $sources[] = (int) $source;
            }
            if ($plain) {
                // The template is now one of the device's graph templates.
                db_execute_prepared('REPLACE INTO host_graph (host_id, graph_template_id) VALUES (?, ?)', [$host, $template]);
            }
            if (!is_numeric($graph) || (int) $graph < 1) {
                throw new RuntimeException('No graph was created');
            }
            $result = ['graph_id' => (int) $graph, 'first_data_source' => $first, 'data_source_ids' => $sources];
            $status = 'ok';
        }
    }
} catch (Throwable $error) {
    cacti_log('ERROR: Graph add worker failed: ' . get_class($error), false, 'WEBLOG');
}
ob_end_clean();
echo 'KADUPUL_GRAPH_ADD_RESULT=' . json_encode(['status' => $status] + $result, JSON_THROW_ON_ERROR) . PHP_EOL;
exit($status === 'ok' ? 0 : 1);
