<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

require_once __DIR__ . '/../../lib/graph_zoom.php';

test('graph zoom reports missing associated RRA data', function () {
    $missing = false;

    $result = graph_zoom_resolve_rra([], 'all', function () {
        throw new RuntimeException('No RRA lookup should happen.');
    }, function () use (&$missing) {
        $missing = true;
    });

    expect($missing)->toBeTrue();
    expect($result)->toBe([]);
});

test('graph zoom reports a requested RRA row that no longer exists', function () {
    $missing = false;
    $fetched = null;

    $result = graph_zoom_resolve_rra(
        [['id' => 7]],
        '12',
        function ($id) use (&$fetched) {
            $fetched = $id;

            return [];
        },
        function () use (&$missing) {
            $missing = true;
        }
    );

    expect($fetched)->toBe(12);
    expect($missing)->toBeTrue();
    expect($result)->toBe([]);
});

test('graph zoom reports when the default RRA row was removed', function () {
    $missing = false;
    $fetched = null;

    graph_zoom_resolve_rra(
        [['id' => 7]],
        'all',
        function ($id) use (&$fetched) {
            $fetched = $id;

            return false;
        },
        function () use (&$missing) {
            $missing = true;
        }
    );

    expect($fetched)->toBe(7);
    expect($missing)->toBeTrue();
});

test('graph zoom returns the selected RRA with its timespan', function () {
    $missing = false;

    $result = graph_zoom_resolve_rra(
        [['id' => 7]],
        'all',
        function ($id) {
            return ['id' => $id, 'step' => 300, 'steps' => 2, 'rows' => 100];
        },
        function () use (&$missing) {
            $missing = true;
        }
    );

    expect($missing)->toBeFalse();
    expect($result)->toBe(['id' => 7, 'step' => 300, 'steps' => 2, 'rows' => 100, 'timespan' => 60000]);
});

test('graph zoom resolves boundary IDs to the stored default or explicit numeric ID', function ($requested) {
    $fetched = null;
    $result = graph_zoom_resolve_rra(
        [['id' => 7]],
        $requested,
        function ($id) use (&$fetched) {
            $fetched = $id;
            return ['id' => $id, 'step' => 300, 'steps' => 2, 'rows' => 100];
        },
        function () {
            throw new RuntimeException('Stored RRA must resolve.');
        }
    );
    expect($fetched)->toBe(7)->and($result['timespan'])->toBe(60000);
})->with(['0', '', 'all', '7']);
