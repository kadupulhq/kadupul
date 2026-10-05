<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-2.0-or-later

function automation_tree_parent_probe(string $mode, int $parent, string $failure = ''): array
{
    $process = proc_open(
        [PHP_BINARY,dirname(__DIR__, 3).'/fixtures/automation_tree_parent_probe.php',$mode,(string)$parent,$failure],
        [1 => ['pipe','w'],2 => ['pipe','w']],
        $pipes
    );
    expect(is_resource($process))->toBeTrue();
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    expect(proc_close($process))->toBe(0, $stderr.$stdout)->and($stderr)->toBe('');
    return json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
}

test('tree automation stops after a rejected parent without creating root headers or leaves', function ($mode, $parent) {
    $state = automation_tree_parent_probe($mode, $parent);
    expect($state['after'])->toBe($state['before'])->and($state['calls'])->toBe([])
     ->and(implode('\n', $state['logs']))->toContain('Not Added');
})->with(['device','graph','all','multi'])->with([999,20,30]);

test('tree automation stops a later header failure and preserves earlier successful work', function ($mode) {
    $state = automation_tree_parent_probe($mode, 10, 'second');
    expect($state['calls'])->toHaveCount(2)->and(count($state['after']))->toBe(count($state['before']) + 1)
     ->and($state['after'][count($state['before'])]['title'])->toBe('first')->and((int)$state['after'][count($state['before'])]['parent'])->toBe(10);
    expect((int)$state['after'][count($state['before'])]['host_id'])->toBe(0)->and((int)$state['after'][count($state['before'])]['local_graph_id'])->toBe(0);
    expect(array_column($state['calls'], 'title'))->toBe(['first','second']);
})->with(['device','graph','all','multi']);

test('tree automation still creates nested headers and leaves under admitted parents', function ($mode, $parent) {
    $state = automation_tree_parent_probe($mode, $parent);
    expect(count($state['after']))->toBe(count($state['before']) + 3)
     ->and((int)$state['after'][count($state['before'])]['parent'])->toBe($parent)
     ->and((int)$state['after'][count($state['before']) + 1]['parent'])->toBe((int)$state['after'][count($state['before'])]['id'])
     ->and((int)$state['after'][count($state['before']) + 2]['parent'])->toBe((int)$state['after'][count($state['before']) + 1]['id']);
    expect((int)$state['after'][count($state['before']) + 2][$mode === 'device' ? 'host_id' : 'local_graph_id'])->toBe(7);
})->with(['device','graph'])->with([0,10]);


test('the shared tree API rejects every invalid parent and a missing destination before writes', function ($mode, $parent) {
    $state = automation_tree_parent_probe($mode, $parent);
    expect($state['result'])->toBeFalse()->and($state['after'])->toBe($state['before'])
        ->and($state['calls'])->toBe([])->and($state['logs'])->toContain('message:2');
})->with([['api',999], ['api',20], ['api',30], ['api',40], ['api',50], ['api',60], ['api',70], ['missing-tree',0]]);

test('the shared tree API admits roots and headers including the name zero', function ($parent) {
    $state = automation_tree_parent_probe('api', $parent);
    expect($state['result'])->toBeGreaterThan(0)->and($state['calls'])->toHaveCount(1)
        ->and((int)$state['calls'][0]['parent'])->toBe($parent);
})->with([0,10,80]);

test('the rendered parent selector offers only parents admitted by the shared API', function () {
    $state = automation_tree_parent_probe('dropdown', 0);
    $document = new DOMDocument();
    expect($document->loadHTML($state['result']))->toBeTrue();
    $options = [];
    foreach ($document->getElementsByTagName('option') as $option) {
        $options[] = $option->getAttribute('value');
    }
    expect($options)->toBe(['0','10','80']);
});
