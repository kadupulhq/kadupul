<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

test('graph editors execute fixed and editable line visibility without changing other rows', function () {
    $root = dirname(__DIR__, 4);
    foreach (array('graphs_items.php', 'graph_templates_items.php') as $editor) {
        $process = proc_open(
            array('node', $root . '/tests/Fixtures/graph-item-visibility.mjs', $root . '/' . $editor),
            array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
            $pipes
        );
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($process), $error);
        expect($error)->toBe('');
        $results = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        $width = '#row_line_width';
        expect($results['20']['rows'][$width])->toBeTrue()
            ->and($results['1']['rows'][$width])->toBeFalse();
        $stackRows = $results['20']['rows'];
        unset($stackRows[$width]);
        foreach (array('4', '5', '6') as $type) {
            expect($results[$type]['rows'][$width])->toBeFalse()
                ->and($results[$type]['writes'][$width])->toBe(1)
                ->and($results['20']['writes'][$width])->toBe(1);
            $fixedRows = $results[$type]['rows'];
            unset($fixedRows[$width]);
            expect($fixedRows)->toBe($stackRows);
        }
    }
    $forms = file_get_contents($root . '/lib/graph_item_editor.php');
    expect($forms)->toContain('LINE1, LINE2 and LINE3 use fixed widths')
        ->and($forms)->toContain('integers or decimal values are supported.');
});
