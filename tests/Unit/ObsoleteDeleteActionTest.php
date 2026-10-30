<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

function obsolete_delete_dispatch(string $file, string $action): string
{
    $source = file_get_contents(dirname(__DIR__, 2) . '/' . $file);
    expect($source)->toBeString();
    $start = strpos($source, "switch (get_request_var('action'))");
    expect($start)->not->toBeFalse();
    $source = substr($source, $start);
    $depth = 0;
    $end = null;
    // Isolate the actual dispatcher without interpreting braces inside strings.
    foreach (PhpToken::tokenize('<?php ' . $source) as $token) {
        if ($token->text === '{') {
            $depth++;
        } elseif ($token->text === '}' && --$depth === 0) {
            $end = $token->pos + 1 - strlen('<?php ');
            break;
        }
    }
    expect($end)->not->toBeNull();
    $program = <<<'PHP'
function get_request_var($name) { return $GLOBALS['argv'][1]; }
function isset_request_var($name) { return false; }
function top_header() { echo 'header:'; }
function bottom_footer() { echo ':footer'; }
function ds() { echo 'list'; }
function form_actions() { echo 'bulk'; }
PHP;

    $process = proc_open(
        array(PHP_BINARY, '-r', $program . substr($source, 0, $end), $action),
        array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
        $pipes
    );
    expect(is_resource($process))->toBeTrue();
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    expect(proc_close($process))->toBe(0, $error)->and($error)->toBe('');
    return $output;
}

test('obsolete delete actions use the normal fallback and keep bulk actions reachable', function (string $file, string $action, string $expected): void {
    expect(obsolete_delete_dispatch($file, $action))->toBe($expected);
})->with(array(
    'data source obsolete' => array('data_sources.php', 'ds_remove', 'header:list:footer'),
    'data source unknown' => array('data_sources.php', 'unknown', 'header:list:footer'),
    'data source bulk' => array('data_sources.php', 'actions', 'bulk'),
));
