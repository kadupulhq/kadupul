<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

test('supplemental source contract documents fixed graph width controls', function () {
	$root = dirname(__DIR__, 4);
	$graphItems = file_get_contents($root . '/graphs_items.php');
	$templateItems = file_get_contents($root . '/graph_templates_items.php');
	$forms = file_get_contents($root . '/include/global_form.php');

	expect($graphItems)->toBeString()->and($templateItems)->toBeString()->and($forms)->toBeString();
	foreach (array($graphItems, $templateItems) as $source) {
		expect(strpos($source, "case '4': // LINE1"))->not->toBeFalse();
		$fixedTypes = substr($source, strpos($source, "case '4': // LINE1"));
		$fixedTypes = substr($fixedTypes, 0, strpos($fixedTypes, 'break;'));
		expect($fixedTypes)->toContain("$('#row_line_width').hide();")
			->and($fixedTypes)->not->toContain("$('#row_line_width').show();");
	}

	$stackType = substr($graphItems, strpos($graphItems, "case '20': // LINE:STACK"));
	$stackType = substr($stackType, 0, strpos($stackType, 'break;'));
	$templateStackType = substr($templateItems, strpos($templateItems, "case '20': // LINE:STACK"));
	$templateStackType = substr($templateStackType, 0, strpos($templateStackType, 'break;'));
	expect($stackType)->toContain("$('#row_line_width').show();")
		->and($templateStackType)->toContain("$('#row_line_width').show();")
		->and($forms)->toContain('LINE1, LINE2 and LINE3 use fixed widths')
		->and($forms)->toContain('integers or decimal values are supported.');
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
