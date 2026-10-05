<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

test('form help documents fixed widths without imposing a positive limit', function () {
    $forms = file_get_contents(dirname(__DIR__, 4).'/include/global_form.php');
    expect($forms)->toBeString()->toContain('LINE1, LINE2 and LINE3 use fixed widths')
        ->toContain('integers or decimal values are supported.')->not->toContain('enter a positive width');
});

test('native fixed-line saves ignore hidden widths and preserve stored stack widths', function ($editor, $type, $width, $state) {
    $command = [PHP_BINARY, dirname(__DIR__, 3).'/fixtures/line_width_editor_probe.php', $editor, (string) $type, $width, $state];
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    expect(is_resource($process))->toBeTrue();
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    expect(proc_close($process))->toBe(0)->and($error)->toBe('');
    $result = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
    expect($result['errors'])->toBe([])->and($result['saves'])->toHaveCount(1);
    $row = $state === 'existing' ? $result['rows'][0] : $result['rows'][1];
    expect((float) $row['line_width'])->toBe($state === 'existing' ? 2.5 : (float) ($type - 3))
        ->and((int) $row['graph_type_id'])->toBe($type);
    if ($state === 'existing') {
        expect(array_key_exists('line_width', $result['saves'][0]))->toBeFalse();
    }
})->with(['graphs_items.php', 'graph_templates_items.php'])->with([4, 5, 6])
    ->with(['abc', '', '0', '2.50'])->with(['existing', 'new']);

test('native stack saves retain editable width validation', function ($editor, $width, $accepted) {
    $process = proc_open([PHP_BINARY, dirname(__DIR__, 3).'/fixtures/line_width_editor_probe.php', $editor, '20', $width, 'existing'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    expect(is_resource($process))->toBeTrue();
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    expect(proc_close($process))->toBe(0)->and($error)->toBe('');
    $result = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
    if ($accepted) {
        expect($result['errors'])->toBe([])->and($result['saves'])->toHaveCount(1)
            ->and((float) $result['rows'][0]['line_width'])->toBe((float) $width);
    } else {
        expect($result['errors'])->toBe(['line_width' => 'line_width'])->and($result['saves'])->toBe([])
            ->and((float) $result['rows'][0]['line_width'])->toBe(2.5);
    }
})->with(['graphs_items.php', 'graph_templates_items.php'])->with([['2.50', true], ['0', true], ['', true], ['abc', false]]);
