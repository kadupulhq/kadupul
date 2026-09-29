<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

$source = file_get_contents(dirname(__DIR__, 2) . '/aggregate_graphs.php');

test('aggregate graph confirmation escapes the local graph ID attribute', function () use ($source) {
    $start = strpos($source, "name='local_graph_id' value=");
    expect($start)->not->toBeFalse();

    $fragment = substr($source, $start, 160);
    expect($fragment)->toContain('html_escape($local_graph_id)');
    expect($fragment)->not->toContain("get_nfilter_request_var('local_graph_id')");
});

test('aggregate graph confirmation replaces non-scalar IDs before rendering', function () use ($source) {
    $start = strpos($source, "\$local_graph_id = get_nfilter_request_var('local_graph_id', 0);");
    expect($start)->not->toBeFalse();

    $fragment = substr($source, $start, 180);
    expect($fragment)->toContain('if (!is_scalar($local_graph_id))');
    expect($fragment)->toContain('$local_graph_id = 0;');
});
