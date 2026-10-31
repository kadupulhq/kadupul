<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

function redirect_graph_action_probe(array $input): array
{
    $process = proc_open(
        array(PHP_BINARY, dirname(__DIR__) . '/Fixtures/redirect-graph-action.php', dirname(__DIR__, 2)),
        array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
        $pipes
    );
    if (!is_resource($process)) throw new RuntimeException('Cannot start redirect fixture');
    $payload = json_encode($input, JSON_THROW_ON_ERROR);
    $written = 0;
    while ($written < strlen($payload)) {
        $count = fwrite($pipes[0], substr($payload, $written));
        if ($count === false || $count === 0) throw new RuntimeException('Cannot send redirect fixture input');
        $written += $count;
    }
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    expect(proc_close($process))->toBe(0);
    expect($error)->toBe('');
    return json_decode($output, true, 512, JSON_THROW_ON_ERROR);
}

test('destination URLs keep their own actions and let an absent action select the real graph default', function (array $input, string $url, string $action, ?string $referer = null): void {
    $expected = array('url' => $url, 'source_reads' => 0, 'action' => $action, 'saved_action' => $action);
    if ($referer !== null) $expected['normalized_referer'] = $referer;
    expect(redirect_graph_action_probe($input))->toBe($expected);
})->with(array(
    'relative zoom fallback' => array(array('url' => 'graph_view.php', 'source_action' => 'zoom'), 'graph_view.php', 'tree'),
    'view fallback with filters' => array(array('url' => '/graph_view.php?filter=0', 'source_action' => 'view', 'view_mode' => '2'), '/graph_view.php?filter=0', 'list'),
    'saved preview survives save action' => array(array('url' => 'graph_view.php', 'source_action' => 'save', 'session' => array('sess_graph_view_action' => 'preview')), 'graph_view.php', 'preview'),
    'valid source action still belongs to source' => array(array('url' => 'graph_view.php', 'source_action' => 'tree', 'session' => array('sess_graph_view_action' => 'list')), 'graph_view.php', 'list'),
    'invalid saved mode recovers' => array(array('url' => 'graph_view.php', 'source_action' => 'view', 'session' => array('sess_graph_view_action' => 'zoom')), 'graph_view.php', 'tree'),
    'explicit list destination' => array(array('url' => 'graph_view.php?action=list', 'source_action' => 'zoom'), 'graph_view.php?action=list', 'list'),
    'same-origin subdirectory' => array(array('url' => 'http://unit.test/app/graph_view.php?filter=x', 'source_action' => 'zoom', 'view_mode' => '3'), '/app/graph_view.php?filter=x', 'preview'),
    'encoded relative target' => array(array('url' => rawurlencode('graph_view.php?filter=x'), 'source_action' => 'view', 'view_mode' => '2'), 'graph_view.php?filter=x', 'list'),
    'absent source action' => array(array('url' => 'graph_view.php'), 'graph_view.php', 'tree'),
    'external fallback' => array(array('url' => '//evil.test/graph_view.php', 'source_action' => 'zoom'), 'graph_view.php', 'tree'),
    'empty target keeps default' => array(array('url' => '', 'source_action' => 'zoom', 'session' => array('sess_graph_view_action' => 'list')), 'graph_view.php', 'list'),
    'explicit preview destination' => array(array('url' => 'graph_view.php?action=preview', 'source_action' => 'view', 'view_mode' => '2'), 'graph_view.php?action=preview', 'preview'),
    'foreign absolute host' => array(array('url' => 'http://evil.test/graph_view.php', 'source_action' => 'zoom'), 'graph_view.php', 'tree'),
    'unsafe scheme' => array(array('url' => 'javascript:alert(1)', 'source_action' => 'zoom'), 'graph_view.php', 'tree'),
    'header injection' => array(array('url' => "graph_view.php\r\nX-Test: injected", 'source_action' => 'zoom'), 'graph_view.php', 'tree'),
    'parent traversal' => array(array('url' => '../graph_view.php', 'source_action' => 'zoom'), 'graph_view.php', 'tree'),
    'wrong same-origin port' => array(array('url' => 'http://unit.test:81/graph_view.php', 'source_action' => 'zoom'), 'graph_view.php', 'tree'),
    'userinfo is rejected' => array(array('url' => 'http://user:pass@unit.test/graph_view.php', 'source_action' => 'zoom'), 'graph_view.php', 'tree'),
    'encoded external fallback' => array(array('url' => rawurlencode('//evil.test/graph_view.php'), 'source_action' => 'zoom'), 'graph_view.php', 'tree'),
    'bootstrap referer normalization' => array(array('mode' => 'referer', 'url' => 'graph_view.php', 'source_action' => 'zoom', 'view_mode' => '2'), 'graph_view.php', 'list'),
    'bootstrap explicit action' => array(array('mode' => 'referer', 'url' => 'graph_view.php?action=preview', 'source_action' => 'zoom'), 'graph_view.php?action=preview', 'preview'),
    'bootstrap bracket compatibility' => array(array('mode' => 'referer', 'url' => 'graph_view.php?filter[]=1', 'source_action' => 'view', 'view_mode' => '2', 'capture_referer' => true), 'graph_view.php?filter=1', 'list', 'graph_view.php?filter=1'),
    'rendered cancel destination' => array(array('mode' => 'cancel', 'url' => 'graph_view.php', 'source_action' => 'edit', 'view_mode' => '2'), 'graph_view.php', 'list'),
    'rendered cancel explicit action' => array(array('mode' => 'cancel', 'url' => 'graph_view.php?action=preview', 'source_action' => 'edit'), 'graph_view.php?action=preview', 'preview'),
    'rendered configured refresh' => array(array('mode' => 'refresh_array', 'url' => 'graph_view.php', 'source_action' => 'edit', 'view_mode' => '2'), 'graph_view.php?header=false', 'list'),
    'rendered session refresh' => array(array('mode' => 'session_refresh', 'url' => 'graph_view.php', 'source_action' => 'edit', 'session' => array('sess_graph_view_action' => 'preview')), 'graph_view.php', 'preview'),
    'rendered explicit refresh action' => array(array('mode' => 'refresh_array', 'url' => 'graph_view.php?action=preview', 'source_action' => 'edit'), 'graph_view.php?action=preview&header=false', 'preview'),
));
