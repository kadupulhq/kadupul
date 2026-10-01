<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/*
 * graph_view.php prints the tree widths and the page refresh setting into a
 * script block that carries the CSP nonce. A stored value that was not a
 * number used to be printed as script. The test runs the shipped print
 * statements with stored values chosen to break out of the assignment.
 */

function graph_view_setting_output(string $setting, string $stored, string $file = 'graph_view.php'): string
{
    $source = file_get_contents(dirname(__DIR__, 4) . '/' . $file);
    $pattern = '/<\?php (print [^?]*read_user_setting\(\'' . preg_quote($setting, '/') . '\'\)[^?]*;)\?>/';

    expect(preg_match_all($pattern, $source, $matches))->toBeGreaterThan(0);

    $program = 'function read_user_setting($name, $default = false, $force = false, $user = 0) { return $GLOBALS["argv"][1]; }' . "\n" . implode("\n", $matches[1]);

    $pipes = array();
    $process = proc_open(
        array(PHP_BINARY, '-d', 'display_errors=stderr', '-r', $program, $stored),
        array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
        $pipes
    );
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    expect($stderr)->toBe('');

    return $stdout;
}

test('a stored tree width is printed only as a number', function (string $setting) {
    expect(graph_view_setting_output($setting, '1;alert(document.domain)//'))->toBe('1')
        ->and(graph_view_setting_output($setting, 'alert(1)'))->toBe('0')
        ->and(graph_view_setting_output($setting, '300'))->toBe('300');
})->with(array('min_tree_width', 'max_tree_width'));

test('a stored page refresh is printed only as a number', function () {
    expect(graph_view_setting_output('page_refresh', '300'))->toBe('300000')
        ->and(graph_view_setting_output('page_refresh', 'x;alert(1)'))->toBe('0');
});

test('all graph refresh outputs tolerate a nonnumeric stored value', function (string $file) {
    expect(graph_view_setting_output('page_refresh', 'invalid', $file))->toBe($file === 'lib/html.php' ? '00' : '0');
})->with(array('graph.php', 'graph_view.php', 'lib/html.php', 'lib/html_graph.php'));
