<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/** Persisted policy tests use the existing native policy database and complete auth helper. */
function graph_cache_fixture_run(array $scenario): array
{
    global $db, $root, $queries, $querySql;

    if (($scenario['actor'] ?? '') === 'report') {
        $_SESSION['sess_user_id'] = 43;
    }
    if (array_key_exists('reset_cache', $scenario)) {
        $_SESSION['sess_perms_reset_key'] = $scenario['reset_cache'];
    }
    $db->exec("INSERT INTO host(id,description) VALUES(101,'Fixture host');
        INSERT INTO graph_templates VALUES(102,'Fixture template');
        INSERT INTO graph_local VALUES(100,101,102,'interface',1);
        INSERT INTO graph_templates_graph VALUES(100,'Fixture graph',500,120);");
    if (isset($scenario['compatibility'])) {
        $user = $scenario['compatibility'];
        $before = $queries;
        auth_perm_cache_check_reset($user);
        $generationQueries = $queries - $before;
        $total = 0;
        $rows = get_allowed_graphs('', '', '', $total, $user, 100, false);
        return array('ids' => array_column($rows, 'local_graph_id'), 'total' => $total, 'generation_queries' => $generationQueries);
    }
    $initial = array(is_graph_allowed(100, 42), is_tree_allowed(100, 42), get_simple_graph_template_perms(42));
    $before = $queries;
    $repeated = array();
    for ($index = 0; $index < ($scenario['repeat'] ?? 4); $index++) {
        $repeated[] = is_graph_allowed(100, 42);
    }
    $repeatedQueries = $queries - $before;
    if (isset($scenario['failure'])) {
        if ($scenario['failure'] === 'missing') $db->exec('DELETE FROM user_auth WHERE id=42');
        else $GLOBALS['graphCacheReadFailure'] = true;
        $beforeSql = count($querySql);
        $GLOBALS['graphCacheRemoteCalls'] = array();
        try {
            $answer = $scenario['operation'] === 'graph-image-cache' ? graph_cache_image_fixture() : is_graph_allowed(100, 42);
            return array('failed' => false, 'answer' => $answer);
        } catch (RuntimeException $error) {
            return array('failed' => true, 'error' => $error->getMessage(), 'queries_after' => array_slice($querySql, $beforeSql), 'remote_calls' => $GLOBALS['graphCacheRemoteCalls']);
        }
    }
    $db->exec('UPDATE user_auth SET policy_graphs=2,policy_hosts=2,policy_graph_templates=2,policy_trees=2,reset_perms=1 WHERE id=42');
    if (!empty($scenario['keep_allowed'])) {
        $db->exec('INSERT INTO user_auth_perms VALUES(42,1,100),(42,2,100),(42,4,102)');
    }
    $result = array('initial' => $initial, 'repeated' => $repeated, 'repeated_queries' => $repeatedQueries);
    if ($scenario['operation'] === 'graph-image-cache') {
        $result['image'] = graph_cache_image_fixture();
    }
    $result['after'] = array(is_graph_allowed(100, 42), is_tree_allowed(100, 42), get_simple_graph_template_perms(42));
    $result['stored'] = $db->query('SELECT policy_graphs,reset_perms FROM user_auth WHERE id=42')->fetch(PDO::FETCH_ASSOC);
    $result['reset_queries'] = count(array_filter($querySql, static fn(string $sql): bool => str_contains($sql, 'SELECT reset_perms')));
    return $result;
}

/** Actual graph-image collector dispatch; bootstrap/transport are explicit fixture boundaries. */
function graph_cache_image_fixture(): array
{
    global $root, $config, $_CACTI_REQUEST;
    require_once $root . '/include/global_constants.php';
    require_once $root . '/lib/html_utility.php';
    $directory = sys_get_temp_dir() . '/graph-cache-image-' . bin2hex(random_bytes(8));
    $previous = getcwd();
    $savedConfig = $config;
    $bufferLevel = ob_get_level();
    mkdir($directory . '/include', 0700, true);
    mkdir($directory . '/lib', 0700);
    try {
        file_put_contents($directory . '/include/auth.php', '<?php');
        file_put_contents($directory . '/lib/rrd.php', '<?php');
        $config += array('poller_id' => 2, 'url_path' => '/', 'is_web' => false);
        $_REQUEST = array('local_graph_id' => '100', 'image_format' => 'png');
        $_CACTI_REQUEST = array();
        $GLOBALS['graphCacheRemoteCalls'] = array();
        $GLOBALS['graphCacheSessionClosed'] = false;
        chdir($directory);
        ob_start();
        require $root . '/graph_image.php';
        return array('output' => ob_get_clean(), 'remote_calls' => $GLOBALS['graphCacheRemoteCalls'], 'session_closed' => $GLOBALS['graphCacheSessionClosed']);
    } finally {
        while (ob_get_level() > $bufferLevel) ob_end_clean();
        chdir($previous);
        $config = $savedConfig;
        unlink($directory . '/include/auth.php');
        unlink($directory . '/lib/rrd.php');
        rmdir($directory . '/include');
        rmdir($directory . '/lib');
        rmdir($directory);
    }
}

function api_plugin_hook_function($name, $data = '')
{
    return $data;
}

function cacti_session_close(): void
{
    $GLOBALS['graphCacheSessionClosed'] = true;
}

function call_remote_data_collector($poller, $url): string
{
    $GLOBALS['graphCacheRemoteCalls'][] = array($poller, $url);
    return "image = remote\nREMOTE_IMAGE";
}
