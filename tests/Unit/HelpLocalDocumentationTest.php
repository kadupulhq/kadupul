<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/*
 * Local page help serves the HTML documentation the Local Page Help Only
 * setting tells administrators to host under docs/.
 *
 * The runtime cases run the shipped help.php in a child PHP process. A
 * temporary directory stands in for base_path so docs/ can be populated per
 * test, and its include/auth.php replaces the real bootstrap: it loads the
 * real request helpers and sanitize_search_string, and seeds the CLI option
 * cache so read_config_option never needs a database.
 */

function _help_make_root()
{
    $root = sys_get_temp_dir() . '/kadupul-help-' . bin2hex(random_bytes(6));

    mkdir($root . '/include', 0700, true);
    mkdir($root . '/docs', 0700);

    $repo = realpath(__DIR__ . '/../..');

    $prelude = "<?php\n"
        . "\$config = array(\n"
        . "    'base_path' => " . var_export($root, true) . ",\n"
        . "    'url_path' => '/kadupul/',\n"
        . "    'is_web' => false,\n"
        . "    'config_options_array' => array(\n"
        . "        'local_documentation' => getenv('HELP_LOCAL_DOCUMENTATION'),\n"
        . "        'log_validation' => '',\n"
        . "    ),\n"
        . ");\n"
        . "\$_REQUEST = json_decode(getenv('HELP_REQUEST'), true);\n"
        . "require " . var_export($repo . '/include/global_constants.php', true) . ";\n"
        . "require " . var_export($repo . '/lib/functions.php', true) . ";\n"
        . "require " . var_export($repo . '/lib/html_utility.php', true) . ";\n"
        . "require " . var_export($repo . '/lib/html_validate.php', true) . ";\n"
        . "function __() {\n"
        . "    \$args = func_get_args();\n"
        . "    return vsprintf(array_shift(\$args), \$args);\n"
        . "}\n";

    file_put_contents($root . '/include/auth.php', $prelude);

    return $root;
}

function _help_remove_root($root)
{
    $entries = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($entries as $entry) {
        if ($entry->isDir()) {
            rmdir($entry->getPathname());
        } else {
            unlink($entry->getPathname());
        }
    }

    rmdir($root);
}

/**
 * Run help.php from $root and return its decoded JSON response.
 */
function _help_request($root, array $request, $local_documentation = 'on')
{
    $command = escapeshellarg(PHP_BINARY)
        . ' -d display_errors=stderr'
        . ' ' . escapeshellarg(realpath(__DIR__ . '/../../help.php'));

    $env = [
        'HELP_LOCAL_DOCUMENTATION' => $local_documentation,
        'HELP_REQUEST'             => json_encode($request),
    ];

    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, $env);

    if (!is_resource($process)) {
        throw new RuntimeException('proc_open failed for help.php');
    }

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);

    fclose($pipes[1]);
    fclose($pipes[2]);

    $exit = proc_close($process);

    if ($exit !== 0) {
        throw new RuntimeException("help.php exited {$exit}: {$stderr}");
    }

    return json_decode($stdout, true);
}

beforeEach(function () {
    $this->root = _help_make_root();

    file_put_contents($this->root . '/docs/Graphs.html', '<html></html>');
});

afterEach(function () {
    _help_remove_root($this->root);
});

test('a hosted docs page is returned by its requested .html name', function () {
    expect(_help_request($this->root, ['page' => 'Graphs.html']))->toBe([
        'status'   => 'Success',
        'location' => '/kadupul/docs/Graphs.html',
    ]);
});

test('a page missing from docs is reported as not reachable', function () {
    $response = _help_request($this->root, ['page' => 'Missing.html']);

    expect($response['status'])->toBe('Not Reachable');
    expect($response)->not->toHaveKey('location');
    expect($response['message'])->toContain("'Missing.html'");
});

test('a traversal attempt is confined to docs by basename', function () {
    file_put_contents($this->root . '/secret.html', '<html></html>');

    $outside = _help_request($this->root, ['page' => '../secret.html']);

    expect($outside['status'])->toBe('Not Reachable');
    expect($outside)->not->toHaveKey('location');

    expect(_help_request($this->root, ['page' => '../../docs/Graphs.html']))->toBe([
        'status'   => 'Success',
        'location' => '/kadupul/docs/Graphs.html',
    ]);
});

test('online help ignores docs and returns the fixed destination', function () {
    expect(_help_request($this->root, ['page' => 'Graphs.html'], ''))->toBe([
        'status'   => 'Success',
        'location' => 'https://kadupul.org/map/',
    ]);
});

test('the local documentation setting describes HTML hosted under docs', function () {
    $settings = file_get_contents(__DIR__ . '/../../include/global_settings.php');
    $start    = strpos($settings, "'local_documentation' => array(");

    expect(substr($settings, $start, 600))->toContain('in HTML format');
});
