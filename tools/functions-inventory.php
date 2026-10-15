#!/usr/bin/env php
<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Static inventory of the functions declared in lib/functions.php.
 *
 * Writes one record per function plus an index under
 * docs/migration/functions-inventory/. Each record has generated `static`
 * facts and a `human` block that this tool never overwrites, so the
 * decomposition can track status and target module in the same file.
 *
 * It is separate from tests/Support/Behavior/inventory.py on purpose. That
 * script is a lexical, repository-wide discovery aid stamped with a revision.
 * This one parses a single file with the PHP tokenizer and must be
 * byte-stable, so it carries no revision, timestamp or absolute path.
 *
 * PHP rather than Python: the tokenizer is the parser that runs the file, so
 * the inventory cannot disagree with PHP about where a function starts or ends.
 *
 * Usage:
 *   tools/functions-inventory.php                    write records
 *   tools/functions-inventory.php --check            fail if committed declaration facts are stale (fan_out is not compared)
 *   tools/functions-inventory.php --accept-new-functions
 *                                                    allow functions missing from index.json
 *   tools/functions-inventory.php --external-plugins=DIR
 *                                                    print dead-code candidate references in DIR
 */

namespace Kadupul\Tools\FunctionsInventory;

const SCHEMA_VERSION = 1;
const SOURCE_FILE = 'lib/functions.php';
const OUTPUT_DIR = 'docs/migration/functions-inventory';
const BEHAVIOR_RECORD_DIR = 'tests/Golden/functions/';

const STATUSES = ['not assessed', 'planned', 'implemented', 'verified'];
const LTS_STATUSES = ['not assessed', 'not on lts', 'unchanged on lts', 'diverged on lts'];

/** Dead-code threshold: core references at or below this count make a function a candidate. */
const DEAD_CODE_MAX_FAN_OUT = 2;

/**
 * Paths whose references must not count as fan-out. The generator and its tests
 * name functions by design, and tests/Support/Behavior/ is test-support code and
 * data that lists functions by name, so counting them would change the static
 * facts every time such a file is edited.
 */
const FAN_OUT_EXCLUDED = [
    'include/vendor/',
    'tests/vendor/',
    'tests/Support/Behavior/',
    'tools/functions-inventory.php',
    'tests/Symfony/FunctionsInventoryTest.php',
    'tests/Symfony/FunctionsFreezeTest.php',
];

const SUPERGLOBALS = ['$_SESSION', '$_SERVER', '$_REQUEST', '$_GET', '$_POST', '$_COOKIE'];

const FILE_FUNCTIONS = [
    'chgrp', 'chmod', 'chown', 'clearstatcache', 'closedir', 'copy', 'disk_free_space', 'fclose',
    'feof', 'fflush', 'fgetc', 'fgetcsv', 'fgets', 'file', 'file_exists', 'file_get_contents',
    'file_put_contents', 'fileatime', 'filemtime', 'fileowner', 'fileperms', 'filesize', 'flock',
    'fopen', 'fpassthru', 'fputcsv', 'fputs', 'fread', 'fseek', 'ftell', 'ftruncate', 'fwrite',
    'glob', 'is_dir', 'is_executable', 'is_file', 'is_link', 'is_readable', 'is_writable',
    'is_writeable', 'mkdir', 'opendir', 'parse_ini_file', 'readdir', 'readfile', 'readlink',
    'realpath', 'rename', 'rewind', 'rmdir', 'scandir', 'stat', 'symlink', 'sys_get_temp_dir',
    'tempnam', 'tmpfile', 'touch', 'umask', 'unlink',
];

const PROCESS_FUNCTIONS = [
    'escapeshellarg', 'escapeshellcmd', 'exec', 'passthru', 'pcntl_exec', 'pcntl_fork',
    'pcntl_signal', 'pcntl_waitpid', 'popen', 'posix_getpwuid', 'posix_geteuid', 'posix_kill',
    'proc_close', 'proc_open', 'proc_terminate', 'shell_exec', 'system',
];

/**
 * Header and output-control calls that change what a response looks like.
 */
const HEADER_FUNCTIONS = ['header', 'header_remove', 'http_response_code', 'setcookie', 'setrawcookie'];

/**
 * @return array<int, string> sorted, unique
 */
function sorted_unique(array $values): array
{
    $values = array_values(array_unique($values));
    sort($values, SORT_STRING);

    return $values;
}

/**
 * @return array{0: array<int, array{0: int, 1: string, 2: int}|string>, 1: array<int, int>} tokens with line numbers
 */
function tokenize(string $source): array
{
    $tokens = token_get_all($source);
    $lines  = [];
    $line   = 1;

    foreach ($tokens as $i => $token) {
        $lines[$i] = $line;
        $text      = is_array($token) ? $token[1] : $token;
        $line     += substr_count($text, "\n");
    }

    return [$tokens, $lines];
}

function token_text(array|string $token): string
{
    return is_array($token) ? $token[1] : $token;
}

function is_trivia(array|string $token): bool
{
    return is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true);
}

/**
 * Index of the next token that is not whitespace or a comment, or null.
 *
 * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
 */
function next_significant(array $tokens, int $from): ?int
{
    $count = count($tokens);
    for ($i = $from + 1; $i < $count; $i++) {
        if (!is_trivia($tokens[$i])) {
            return $i;
        }
    }

    return null;
}

function previous_significant(array $tokens, int $from): ?int
{
    for ($i = $from - 1; $i >= 0; $i--) {
        if (!is_trivia($tokens[$i])) {
            return $i;
        }
    }

    return null;
}

/**
 * Source text of a token range with comments dropped and whitespace collapsed.
 *
 * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
 */
function normalized_text(array $tokens, int $from, int $to): string
{
    $text = '';
    for ($i = $from; $i <= $to; $i++) {
        $token = $tokens[$i];
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        $text .= is_array($token) && $token[0] === T_WHITESPACE ? ' ' : token_text($token);
    }

    return trim((string) preg_replace('/\s+/', ' ', $text));
}

/**
 * Finds every named function declaration, with its nesting context.
 *
 * `context` is `top` for a declaration outside any block, `conditional` when it
 * sits inside a plain block such as an if, `nested` inside another function,
 * and `method` inside a class-like body. Closures and arrow functions have no
 * name and are skipped.
 *
 * @return array<int, array<string, mixed>>
 */
function find_declarations(string $source): array
{
    [$tokens, $lines] = tokenize($source);
    $count            = count($tokens);
    $stack            = [];
    $pending          = null;
    $found            = [];

    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];

        if (!is_array($token)) {
            if ($token === '{') {
                $stack[] = $pending ?? 'block';
                $pending = null;
            } elseif ($token === '}') {
                array_pop($stack);
            } elseif ($token === ';') {
                $pending = null;
            }
            continue;
        }

        switch ($token[0]) {
            case T_CURLY_OPEN:
            case T_DOLLAR_OPEN_CURLY_BRACES:
                $stack[] = 'interpolation';
                break;
            case T_CLASS:
            case T_INTERFACE:
            case T_TRAIT:
            case T_ENUM:
                $previous = previous_significant($tokens, $i);
                // `Foo::class` and `new class` do not open a named declaration body here.
                if ($previous !== null && token_text($tokens[$previous]) === '::') {
                    break;
                }
                $pending = 'class';
                break;
            case T_FUNCTION:
                $name = next_significant($tokens, $i);
                $byRef = false;
                // PHP 8.1+ tokenizes `&` as an array token, so compare its text.
                if ($name !== null && token_text($tokens[$name]) === '&') {
                    $byRef = true;
                    $name  = next_significant($tokens, $name);
                }
                if ($name === null || !is_array($tokens[$name]) || $tokens[$name][0] !== T_STRING) {
                    $pending = 'function';
                    break;
                }
                $found[] = declaration($tokens, $lines, $i, $name, $byRef, $stack);
                $pending = 'function';
                break;
            case T_FN:
                break;
        }
    }

    return $found;
}

/**
 * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
 * @param array<int, int>                                      $lines
 * @param array<int, string>                                   $stack
 *
 * @return array<string, mixed>
 */
function declaration(array $tokens, array $lines, int $keyword, int $name, bool $byRef, array $stack): array
{
    $count = count($tokens);
    $open  = null;
    $depth = 0;
    for ($i = next_significant($tokens, $name); $i !== null && $i < $count; $i++) {
        $text = token_text($tokens[$i]);
        if ($text === '(' || $text === '[') {
            $depth++;
        } elseif ($text === ')' || $text === ']') {
            $depth--;
        } elseif ($depth === 0 && ($text === '{' || $text === ';')) {
            $open = $i;
            break;
        }
    }

    $parametersOpen = next_significant($tokens, $name);
    $parametersClose = $parametersOpen;
    $depth = 0;
    for ($i = $parametersOpen; $i < $count; $i++) {
        $text = token_text($tokens[$i]);
        if ($text === '(') {
            $depth++;
        } elseif ($text === ')') {
            $depth--;
            if ($depth === 0) {
                $parametersClose = $i;
                break;
            }
        }
    }

    $bodyEnd = $open;
    if ($open !== null && $tokens[$open] === '{') {
        $braces = 0;
        for ($i = $open; $i < $count; $i++) {
            $token = $tokens[$i];
            $text  = token_text($token);
            if ($text === '{' || (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                $braces++;
            } elseif ($text === '}') {
                $braces--;
                if ($braces === 0) {
                    $bodyEnd = $i;
                    break;
                }
            }
        }
    }

    $context = 'top';
    if (in_array('class', $stack, true)) {
        $context = 'method';
    } elseif (in_array('function', $stack, true)) {
        $context = 'nested';
    } elseif ($stack !== []) {
        $context = 'conditional';
    }

    // previous_significant() skips comments, so walk back over whitespace only.
    $previous = $keyword - 1;
    while ($previous >= 0 && is_array($tokens[$previous]) && $tokens[$previous][0] === T_WHITESPACE) {
        $previous--;
    }
    $docblock = $previous >= 0 && is_array($tokens[$previous]) && $tokens[$previous][0] === T_DOC_COMMENT;

    $returnType = null;
    $colon      = next_significant($tokens, $parametersClose);
    if ($colon !== null && $tokens[$colon] === ':' && $open !== null) {
        $returnType = normalized_text($tokens, $colon + 1, $open - 1);
    }

    return [
        'name'       => token_text($tokens[$name]),
        'context'    => $context,
        'keyword'    => $keyword,
        'open'       => $open,
        'end'        => $bodyEnd,
        'start_line' => $lines[$keyword],
        'end_line'   => $lines[$bodyEnd ?? $keyword],
        'docblock'   => $docblock,
        'returns_reference' => $byRef,
        'return_type' => $returnType,
        'parameters' => parse_parameters($tokens, $parametersOpen, $parametersClose),
    ];
}

/**
 * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
 *
 * @return array<int, array<string, mixed>>
 */
function parse_parameters(array $tokens, int $open, int $close): array
{
    $groups = [];
    $group  = [];
    $depth  = 0;
    for ($i = $open + 1; $i < $close; $i++) {
        $text = token_text($tokens[$i]);
        if ($text === '(' || $text === '[') {
            $depth++;
        } elseif ($text === ')' || $text === ']') {
            $depth--;
        }
        if ($text === ',' && $depth === 0) {
            $groups[] = $group;
            $group    = [];
            continue;
        }
        $group[] = $i;
    }
    $groups[] = $group;

    $parameters = [];
    foreach ($groups as $indexes) {
        $indexes = array_values(array_filter($indexes, static fn(int $i): bool => !is_trivia($tokens[$i])));
        if ($indexes === []) {
            continue;
        }

        $type      = '';
        $byRef     = false;
        $variadic  = false;
        $name      = null;
        $default   = null;
        $promoted  = null;
        foreach ($indexes as $position => $i) {
            $token = $tokens[$i];
            $text  = token_text($token);
            if ($name === null) {
                if (is_array($token) && $token[0] === T_VARIABLE) {
                    $name = ltrim($text, '$');
                } elseif ($text === '&' || (is_array($token) && $token[0] === T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG)) {
                    $byRef = true;
                } elseif ($text === '...') {
                    $variadic = true;
                } elseif (is_array($token) && in_array($token[0], [T_PUBLIC, T_PROTECTED, T_PRIVATE, T_READONLY], true)) {
                    $promoted = $text;
                } else {
                    $type .= $text;
                }
            } elseif ($text === '=') {
                $default = normalized_text($tokens, $i + 1, end($indexes));
                break;
            }
        }

        $type = $type === '' ? null : $type;
        $parameters[] = [
            'name'      => $name,
            'type'      => $type,
            'nullable'  => $type !== null && ($type[0] === '?' || preg_match('/(^|\|)null($|\|)/i', $type) === 1 || strtolower($type) === 'mixed'),
            'by_ref'    => $byRef,
            'variadic'  => $variadic,
            'has_default' => $default !== null,
            'default'   => $default,
        ];
    }

    return $parameters;
}

/**
 * First-argument text of the call whose name token is at $index.
 *
 * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
 *
 * @return array{name: ?string, text: string}
 */
function first_argument(array $tokens, int $index): array
{
    $open = next_significant($tokens, $index);
    $count = count($tokens);
    $depth = 0;
    $first = null;
    $last  = null;
    for ($i = $open; $i < $count; $i++) {
        $text = token_text($tokens[$i]);
        if ($text === '(' || $text === '[') {
            $depth++;
            if ($depth === 1) {
                continue;
            }
        } elseif ($text === ')' || $text === ']') {
            $depth--;
            if ($depth === 0) {
                break;
            }
        } elseif ($text === ',' && $depth === 1) {
            break;
        }
        if (!is_trivia($tokens[$i])) {
            $first ??= $i;
            $last    = $i;
        }
    }

    if ($first === null) {
        return ['name' => null, 'text' => ''];
    }
    $name = null;
    if ($first === $last && is_array($tokens[$first]) && $tokens[$first][0] === T_CONSTANT_ENCAPSED_STRING) {
        $literal = $tokens[$first][1];
        if ($literal[0] === "'" || !str_contains($literal, '$')) {
            $name = substr($literal, 1, -1);
        }
    }

    return ['name' => $name, 'text' => normalized_text($tokens, $first, $last)];
}

/**
 * Facts read from a function body.
 *
 * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
 * @param array<int, int>                                      $lines
 * @param array<string, true>                                  $known lower-case names defined in the file
 *
 * @return array<string, mixed>
 */
function analyze_body(array $tokens, array $lines, array $declaration, array $known): array
{
    $globals    = [];
    $statics    = [];
    $sessionKeys = [];
    $sessionRefs = 0;
    $superglobals = [];
    $db         = [];
    $headers    = [];
    $exits      = [];
    $output     = [];
    $files      = [];
    $processes  = [];
    $hooks      = [];
    $calls      = [];

    $from = $declaration['open'];
    $to   = $declaration['end'];
    if ($from === null || $to === null || $tokens[$from] !== '{') {
        return empty_body();
    }

    for ($i = $from + 1; $i < $to; $i++) {
        $token = $tokens[$i];
        if (!is_array($token)) {
            continue;
        }
        [$kind, $text] = $token;

        if ($kind === T_GLOBAL) {
            for ($j = $i + 1; $j < $to && token_text($tokens[$j]) !== ';'; $j++) {
                if (is_array($tokens[$j]) && $tokens[$j][0] === T_VARIABLE) {
                    $globals[] = ltrim($tokens[$j][1], '$');
                }
            }
        } elseif ($kind === T_STATIC) {
            $next = next_significant($tokens, $i);
            if ($next !== null && is_array($tokens[$next]) && $tokens[$next][0] === T_VARIABLE) {
                $statics[] = ltrim($tokens[$next][1], '$');
                // `static $a, $b;` declares several; collect the rest of the statement.
                for ($j = $next + 1; $j < $to && token_text($tokens[$j]) !== ';'; $j++) {
                    $previous = previous_significant($tokens, $j);
                    if (is_array($tokens[$j]) && $tokens[$j][0] === T_VARIABLE && $previous !== null && token_text($tokens[$previous]) === ',') {
                        $statics[] = ltrim($tokens[$j][1], '$');
                    }
                }
            }
        } elseif ($kind === T_VARIABLE && in_array($text, SUPERGLOBALS, true)) {
            $superglobals[] = $text;
            if ($text === '$_SESSION') {
                $sessionRefs++;
                $open = next_significant($tokens, $i);
                if ($open !== null && $tokens[$open] === '[') {
                    $key = next_significant($tokens, $open);
                    $end = $key === null ? null : next_significant($tokens, $key);
                    if ($key !== null && $end !== null && $tokens[$end] === ']' && is_array($tokens[$key]) && $tokens[$key][0] === T_CONSTANT_ENCAPSED_STRING) {
                        $sessionKeys[] = substr($tokens[$key][1], 1, -1);
                    } else {
                        $sessionKeys[] = '(dynamic)';
                    }
                }
            }
        } elseif ($kind === T_EXIT) {
            $exits[] = strtolower($text);
        } elseif (in_array($kind, [T_ECHO, T_PRINT, T_INLINE_HTML, T_OPEN_TAG_WITH_ECHO], true)) {
            $output[] = $kind === T_INLINE_HTML ? 'inline html' : ($kind === T_OPEN_TAG_WITH_ECHO ? 'echo' : strtolower($text));
        } elseif ($kind === T_STRING) {
            $previous = previous_significant($tokens, $i);
            $next     = next_significant($tokens, $i);
            $before   = $previous === null ? '' : token_text($tokens[$previous]);
            $isCall   = $next !== null && $tokens[$next] === '('
                && !in_array($before, ['->', '?->', '::', 'function', 'new'], true)
                && !($previous !== null && is_array($tokens[$previous]) && in_array($tokens[$previous][0], [T_FUNCTION, T_NEW], true));
            if (!$isCall) {
                continue;
            }
            $lower = strtolower($text);

            if (str_starts_with($lower, 'db_')) {
                $db[] = $lower;
            }
            if (in_array($lower, HEADER_FUNCTIONS, true)) {
                $headers[] = $lower;
            }
            if (in_array($lower, ['printf', 'vprintf', 'print_r', 'var_dump', 'var_export', 'fpassthru', 'readfile'], true)) {
                $output[] = $lower . '()';
            }
            if (in_array($lower, FILE_FUNCTIONS, true)) {
                $files[] = $lower;
            }
            if (in_array($lower, PROCESS_FUNCTIONS, true)) {
                $processes[] = $lower;
            }
            if ($lower === 'api_plugin_hook' || $lower === 'api_plugin_hook_function') {
                $argument = first_argument($tokens, $i);
                $hooks[]  = [
                    'call'       => $lower,
                    'name'       => $argument['name'],
                    'resolved'   => $argument['name'] !== null,
                    'expression' => $argument['name'] === null ? $argument['text'] : null,
                    'line'       => $lines[$i],
                ];
            }
            if (isset($known[$lower])) {
                $calls[] = $lower;
            }
        }
    }

    usort($hooks, static fn(array $a, array $b): int => [$a['line'], $a['call']] <=> [$b['line'], $b['call']]);

    $dbCounts = array_count_values($db);
    ksort($dbCounts, SORT_STRING);

    return [
        'uses_global'     => sorted_unique($globals),
        'static_variables' => sorted_unique($statics),
        'superglobals'    => sorted_unique($superglobals),
        'session'         => ['references' => $sessionRefs, 'keys' => sorted_unique($sessionKeys)],
        // An empty PHP array encodes as a JSON list; keep the shape an object.
        'db_calls'        => $dbCounts === [] ? new \stdClass() : $dbCounts,
        'headers'         => sorted_unique($headers),
        'exits'           => sorted_unique($exits),
        'output'          => sorted_unique($output),
        'file_functions'  => sorted_unique($files),
        'process_functions' => sorted_unique($processes),
        'plugin_hooks'    => $hooks,
        'calls'           => sorted_unique($calls),
    ];
}

/**
 * @return array<string, mixed>
 */
function empty_body(): array
{
    return [
        'uses_global' => [], 'static_variables' => [], 'superglobals' => [],
        'session' => ['references' => 0, 'keys' => []], 'db_calls' => new \stdClass(), 'headers' => [],
        'exits' => [], 'output' => [], 'file_functions' => [], 'process_functions' => [],
        'plugin_hooks' => [], 'calls' => [],
    ];
}

/**
 * Tracked PHP files, sorted.
 *
 * @return array<int, string>
 */
function tracked_php_files(string $root): array
{
    // An argument array runs git without a shell, so no quoting of $root is involved.
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process     = proc_open(['git', '-C', $root, 'ls-files', '-z', '--', '*.php'], $descriptors, $pipes);
    if (!is_resource($process)) {
        throw new \RuntimeException('git ls-files failed to start; run the inventory from a git checkout with git on PATH');
    }
    fclose($pipes[0]);

    // Drain both pipes together: a full stderr pipe would otherwise block git
    // while this side waits for stdout to close.
    $open    = [1 => $pipes[1], 2 => $pipes[2]];
    $buffers = [1 => '', 2 => ''];
    foreach ($open as $stream) {
        stream_set_blocking($stream, false);
    }
    while ($open !== []) {
        $read   = array_values($open);
        $write  = null;
        $except = null;
        if (stream_select($read, $write, $except, null) === false) {
            foreach ($open as $stream) {
                fclose($stream);
            }
            proc_close($process);

            throw new \RuntimeException('git ls-files failed; could not read its output');
        }
        foreach ($open as $fd => $stream) {
            $chunk = fread($stream, 65536);
            if (is_string($chunk)) {
                $buffers[$fd] .= $chunk;
            }
            if (feof($stream)) {
                fclose($stream);
                unset($open[$fd]);
            }
        }
    }
    $status = proc_close($process);
    if ($status !== 0) {
        throw new \RuntimeException('git ls-files failed (exit ' . $status . '); run the inventory from a git checkout: ' . trim($buffers[2]));
    }
    $files = array_values(array_filter(explode("\0", $buffers[1]), static fn(string $f): bool => $f !== '' && is_file($root . '/' . $f)));
    sort($files, SORT_STRING);

    return $files;
}

function fan_out_area(string $path): string
{
    if (str_starts_with($path, 'tests/')) {
        return 'tests';
    }
    if (str_starts_with($path, 'plugins/')) {
        return 'plugins';
    }

    return 'core';
}

/**
 * Word-boundary reference counts per function and area, definitions excluded.
 *
 * Counting words rather than calls is deliberate. Cacti passes function names
 * as strings to hooks, callbacks and call_user_func, and a call-only scan would
 * report those as unused. The cost is that comments and unrelated identifiers
 * that happen to match are counted too, so a count is an upper bound.
 *
 * @param array<int, string> $names
 * @param array<int, string> $files
 *
 * @return array<string, array{core: int, core_outside_functions_php: int, tests: int, plugins: int}>
 */
function count_fan_out(string $root, array $names, array $files, array $declarations): array
{
    $wanted = array_fill_keys($names, true);
    $counts = [];
    foreach ($names as $name) {
        $counts[$name] = ['core' => 0, 'core_outside_functions_php' => 0, 'tests' => 0, 'plugins' => 0];
    }

    foreach ($files as $file) {
        foreach (FAN_OUT_EXCLUDED as $excluded) {
            if ($file === $excluded || (str_ends_with($excluded, '/') && str_starts_with($file, $excluded))) {
                continue 2;
            }
        }
        $source = file_get_contents($root . '/' . $file);
        if ($source === false) {
            continue;
        }
        $area = fan_out_area($file);
        if (preg_match_all('/\b[A-Za-z_][A-Za-z0-9_]*\b/', $source, $matches) === false) {
            continue;
        }
        foreach (array_count_values($matches[0]) as $word => $n) {
            if (!isset($wanted[$word])) {
                continue;
            }
            $counts[$word][$area] += $n;
            if ($area === 'core' && $file !== SOURCE_FILE) {
                $counts[$word]['core_outside_functions_php'] += $n;
            }
        }
    }

    foreach ($declarations as $declaration) {
        $counts[$declaration['name']]['core']--;
    }

    return $counts;
}

/**
 * Seed values from seed.json, matched by exact name or prefix.
 *
 * @return array{names: array<string, array{module: string, wave: int, note: ?string}>, prefixes: array<int, array{prefix: string, module: string, wave: int, note: ?string}>}
 */
function load_seed(string $root): array
{
    $path = $root . '/' . OUTPUT_DIR . '/seed.json';
    $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    $names    = [];
    $prefixes = [];
    foreach ($data['groups'] as $group) {
        foreach ($group['names'] ?? [] as $name) {
            $names[$name] ??= ['module' => $group['module'], 'wave' => $group['wave'], 'note' => $group['note'] ?? null];
        }
        foreach ($group['prefixes'] ?? [] as $prefix) {
            $prefixes[] = ['prefix' => $prefix, 'module' => $group['module'], 'wave' => $group['wave'], 'note' => $group['note'] ?? null];
        }
    }

    return ['names' => $names, 'prefixes' => $prefixes];
}

/**
 * @return array<string, mixed>
 */
function seed_human(string $name, array $seed): array
{
    $match = $seed['names'][$name] ?? null;
    if ($match === null) {
        foreach ($seed['prefixes'] as $candidate) {
            if (str_starts_with($name, $candidate['prefix'])) {
                $match = $candidate;
                break;
            }
        }
    }

    return [
        'target_module'   => $match['module'] ?? 'unassigned',
        'wave'            => $match['wave'] ?? 'unassigned',
        'status'          => 'not assessed',
        'lts_status'      => 'not assessed',
        'behavior_record' => null,
        'notes'           => $match['note'] ?? '',
    ];
}

/**
 * Builds the static facts for every function, keyed by name.
 *
 * With $keepFanOut the committed fan_out of each existing record is reused
 * instead of rescanning tracked PHP. --check uses it so that an unrelated
 * change elsewhere in the repository that happens to mention a function name
 * cannot make the declaration facts look stale.
 *
 * @return array{records: array<string, array<string, mixed>>, conditional: array<int, array<string, mixed>>, other: array<int, array<string, mixed>>}
 */
function build_static(string $root, bool $keepFanOut = false): array
{
    $source = file_get_contents($root . '/' . SOURCE_FILE);
    if ($source === false) {
        throw new \RuntimeException('Cannot read ' . SOURCE_FILE);
    }
    [$tokens, $lines] = tokenize($source);
    $declarations     = find_declarations($source);

    $top = array_values(array_filter($declarations, static fn(array $d): bool => $d['context'] === 'top'));
    $known = [];
    foreach ($declarations as $declaration) {
        $known[strtolower($declaration['name'])] = true;
    }

    $names = [];
    foreach ($top as $declaration) {
        if (isset($names[$declaration['name']])) {
            throw new \RuntimeException('Duplicate top-level declaration of ' . $declaration['name']);
        }
        $names[$declaration['name']] = true;
    }
    $names = array_keys($names);
    sort($names, SORT_STRING);

    $fanOut = count_fan_out($root, $names, tracked_php_files($root), $top);
    if ($keepFanOut) {
        $fanOut = array_merge($fanOut, array_intersect_key(read_committed_fan_out($root), $fanOut));
    }

    $records = [];
    foreach ($top as $declaration) {
        $body = analyze_body($tokens, $lines, $declaration, $known);
        $calls = array_values(array_diff($body['calls'], [strtolower($declaration['name'])]));
        $recursive = in_array(strtolower($declaration['name']), $body['calls'], true);
        unset($body['calls']);

        $records[$declaration['name']] = [
            'name'       => $declaration['name'],
            'file'       => SOURCE_FILE,
            'start_line' => $declaration['start_line'],
            'end_line'   => $declaration['end_line'],
            'line_count' => $declaration['end_line'] - $declaration['start_line'] + 1,
            'signature'  => [
                'parameters'        => $declaration['parameters'],
                'return_type'       => $declaration['return_type'],
                'returns_reference' => $declaration['returns_reference'],
            ],
            'docblock'   => $declaration['docblock'],
            'fan_out'    => $fanOut[$declaration['name']],
            'body'       => $body,
            'calls'      => ['functions' => $calls, 'recursive' => $recursive],
            'called_by'  => [],
        ];
    }

    // Callers include calls made from conditional and nested declarations only when they resolve to a record.
    foreach ($records as $name => $record) {
        foreach ($record['calls']['functions'] as $callee) {
            foreach ($records as $other => $unused) {
                if (strtolower($other) === $callee) {
                    $records[$other]['called_by'][] = $name;
                }
            }
        }
    }
    foreach ($records as $name => $record) {
        $records[$name]['called_by'] = sorted_unique($records[$name]['called_by']);
        // Stored as the declared spelling so callers read naturally.
        $records[$name]['calls']['functions'] = array_map(
            static function (string $lower) use ($records): string {
                foreach ($records as $other => $unused) {
                    if (strtolower($other) === $lower) {
                        return $other;
                    }
                }

                return $lower;
            },
            $record['calls']['functions']
        );
        sort($records[$name]['calls']['functions'], SORT_STRING);
    }
    ksort($records, SORT_STRING);

    $conditional = [];
    $other       = [];
    foreach ($declarations as $declaration) {
        if ($declaration['context'] === 'top') {
            continue;
        }
        $entry = ['name' => $declaration['name'], 'context' => $declaration['context'], 'start_line' => $declaration['start_line'], 'end_line' => $declaration['end_line']];
        if ($declaration['context'] === 'conditional') {
            $conditional[] = $entry;
        } else {
            $other[] = $entry;
        }
    }

    return ['records' => $records, 'conditional' => $conditional, 'other' => $other];
}

const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION;
const JSON_WIDTH = 100;

/**
 * Compact layout: a container whose one-line form fits in JSON_WIDTH columns
 * (indentation, key and trailing comma included) stays on one line, anything
 * larger gets one element per line. Fully expanded records ran to ~20k lines
 * of short scalars, which made the generated diff unreviewable.
 */
function encode(array $data): string
{
    return encode_value($data, 0, 0, 0) . "\n";
}

function encode_value(mixed $value, int $depth, int $lead, int $trailing): string
{
    if (!is_array($value)) {
        return encode_flat($value);
    }
    $flat = encode_flat($value);
    if ($value === [] || $lead + mb_strlen($flat, 'UTF-8') + $trailing <= JSON_WIDTH) {
        return $flat;
    }
    $isList = array_is_list($value);
    $pad    = str_repeat('    ', $depth + 1);
    $last   = array_key_last($value);
    $lines  = [];
    foreach ($value as $key => $item) {
        $prefix  = $isList ? '' : json_encode((string) $key, JSON_FLAGS) . ': ';
        $lines[] = $pad . $prefix . encode_value($item, $depth + 1, strlen($pad) + strlen($prefix), $key === $last ? 0 : 1);
    }

    return ($isList ? '[' : '{') . "\n" . implode(",\n", $lines) . "\n" . str_repeat('    ', $depth) . ($isList ? ']' : '}');
}

function encode_flat(mixed $value): string
{
    if ($value instanceof \stdClass) {
        // Only the empty object is used; it is how an empty map avoids encoding as [].
        if (get_object_vars($value) !== []) {
            throw new \InvalidArgumentException('Non-empty objects are not supported by the compact encoder');
        }

        return '{}';
    }
    if (!is_array($value)) {
        return json_encode($value, JSON_FLAGS);
    }
    $isList = array_is_list($value);
    $parts  = [];
    foreach ($value as $key => $item) {
        $parts[] = ($isList ? '' : json_encode((string) $key, JSON_FLAGS) . ': ') . encode_flat($item);
    }

    return ($isList ? '[' : '{') . implode(', ', $parts) . ($isList ? ']' : '}');
}

/**
 * @return array<string, array<string, mixed>> existing human blocks keyed by function name
 */
function read_existing_human(string $root): array
{
    $human = [];
    foreach (glob($root . '/' . OUTPUT_DIR . '/*.json') ?: [] as $path) {
        $base = basename($path, '.json');
        if ($base === 'index' || $base === 'seed') {
            continue;
        }
        $data = json_decode((string) file_get_contents($path), true);
        if (is_array($data) && isset($data['human']) && is_array($data['human'])) {
            $human[$data['name'] ?? $base] = $data['human'];
        }
    }

    return $human;
}

/**
 * @return array<string, array<string, int>> committed fan_out keyed by function name
 */
function read_committed_fan_out(string $root): array
{
    $fanOut = [];
    foreach (glob($root . '/' . OUTPUT_DIR . '/*.json') ?: [] as $path) {
        $base = basename($path, '.json');
        if ($base === 'index' || $base === 'seed') {
            continue;
        }
        $data = json_decode((string) file_get_contents($path), true);
        if (is_array($data) && isset($data['static']['fan_out']) && is_array($data['static']['fan_out'])) {
            $fanOut[$data['name'] ?? $base] = $data['static']['fan_out'];
        }
    }

    return $fanOut;
}

/**
 * @return array<int, string> names listed in the committed index, or [] when there is none
 */
function read_baseline_names(string $root): array
{
    $path = $root . '/' . OUTPUT_DIR . '/index.json';
    if (!is_file($path)) {
        return [];
    }
    $data = json_decode((string) file_get_contents($path), true);
    if (!is_array($data)) {
        return [];
    }
    $names = array_column($data['functions'] ?? [], 'name');
    foreach ($data['conditional_definitions'] ?? [] as $entry) {
        $names[] = $entry['name'];
    }

    return $names;
}

/**
 * Human-field problems that make a record invalid regardless of staleness.
 *
 * @return array<int, string>
 */
function validate_human(string $root, string $name, array $human): array
{
    $problems = [];
    $expected = ['target_module', 'wave', 'status', 'lts_status', 'behavior_record', 'notes'];
    $keys     = array_keys($human);
    sort($keys);
    $sortedExpected = $expected;
    sort($sortedExpected);
    if ($keys !== $sortedExpected) {
        $problems[] = $name . ': human fields must be exactly ' . implode(', ', $expected);

        return $problems;
    }
    if (!in_array($human['status'], STATUSES, true)) {
        $problems[] = $name . ': status "' . (string) $human['status'] . '" is not one of ' . implode(', ', STATUSES);
    }
    if (!in_array($human['lts_status'], LTS_STATUSES, true)) {
        $problems[] = $name . ': lts_status "' . (string) $human['lts_status'] . '" is not one of ' . implode(', ', LTS_STATUSES);
    }
    if (!is_string($human['target_module']) || $human['target_module'] === '') {
        $problems[] = $name . ': target_module must be a non-empty string or "unassigned"';
    }
    if ($human['wave'] !== 'unassigned' && !(is_int($human['wave']) && $human['wave'] >= 0 && $human['wave'] <= 5)) {
        $problems[] = $name . ': wave must be an integer 0 to 5 or "unassigned"';
    }
    if (!is_string($human['notes'])) {
        $problems[] = $name . ': notes must be a string';
    }
    $record = $human['behavior_record'];
    if ($record !== null && !is_string($record)) {
        $problems[] = $name . ': behavior_record must be a path or null';
    }
    if (in_array($human['status'], ['implemented', 'verified'], true)) {
        if (!is_string($record) || $record === '') {
            $problems[] = $name . ': status "' . $human['status'] . '" requires a behavior_record';
        } elseif (str_contains($record, '..') || str_starts_with($record, '/') || !is_file($root . '/' . $record)) {
            $problems[] = $name . ': behavior_record "' . $record . '" is not a file in the repository';
        } elseif (!str_starts_with($record, BEHAVIOR_RECORD_DIR)) {
            $problems[] = $name . ': behavior_record "' . $record . '" must be under ' . BEHAVIOR_RECORD_DIR;
        } else {
            // Any valid JSON file would otherwise satisfy the gate, including one that records a different function.
            $data = json_decode((string) file_get_contents($root . '/' . $record), true);
            if (!is_array($data)) {
                $problems[] = $name . ': behavior_record "' . $record . '" is not valid JSON';
            } elseif (($data['function'] ?? null) !== $name) {
                $problems[] = $name . ': behavior_record "' . $record . '" records function "' . (is_string($data['function'] ?? null) ? $data['function'] : '(none)') . '", not ' . $name;
            }
        }
        if ($human['target_module'] === 'unassigned' || $human['wave'] === 'unassigned') {
            $problems[] = $name . ': status "' . $human['status'] . '" requires target_module and wave';
        }
    }

    return $problems;
}

/**
 * Computes every file the generator owns, as path => contents.
 *
 * $keepFanOut is for --check; see build_static().
 *
 * @return array{files: array<string, string>, problems: array<int, string>, new_functions: array<int, string>, records: array<string, array<string, mixed>>}
 */
function generate(string $root, bool $keepFanOut = false): array
{
    $static   = build_static($root, $keepFanOut);
    $seed     = load_seed($root);
    $existing = read_existing_human($root);
    $baseline = read_baseline_names($root);
    $problems = [];

    $records = [];
    $files   = [];
    foreach ($static['records'] as $name => $facts) {
        $human = $existing[$name] ?? seed_human($name, $seed);
        $problems = array_merge($problems, validate_human($root, $name, $human));
        $record = [
            'schema' => SCHEMA_VERSION,
            'name'   => $name,
            'static' => array_diff_key($facts, ['name' => true]),
            'human'  => [
                'target_module'   => $human['target_module'] ?? null,
                'wave'            => $human['wave'] ?? null,
                'status'          => $human['status'] ?? null,
                'lts_status'      => $human['lts_status'] ?? null,
                'behavior_record' => $human['behavior_record'] ?? null,
                'notes'           => $human['notes'] ?? null,
            ],
        ];
        $records[$name] = $record;
        $files[OUTPUT_DIR . '/' . $name . '.json'] = encode($record);
    }

    $newFunctions = $baseline === [] ? [] : array_values(array_diff(array_keys($records), $baseline));

    $files[OUTPUT_DIR . '/index.json']                 = encode(build_index($records, $static));
    $files[OUTPUT_DIR . '/dead-code-candidates.md']    = dead_code_report($records);

    $readme = $root . '/' . OUTPUT_DIR . '/README.md';
    if (is_file($readme)) {
        $files[OUTPUT_DIR . '/README.md'] = splice_counts((string) file_get_contents($readme), counts_markdown($records));
    }

    return ['files' => $files, 'problems' => $problems, 'new_functions' => $newFunctions, 'records' => $records];
}

const COUNTS_START = '<!-- inventory-counts:start -->';
const COUNTS_END   = '<!-- inventory-counts:end -->';

/**
 * @param array<string, array<string, mixed>> $records
 */
function counts_markdown(array $records): string
{
    $byModule = [];
    $byWave   = [];
    foreach ($records as $record) {
        $human  = $record['human'];
        $module = $human['target_module'];
        $byModule[$module] ??= array_fill_keys(STATUSES, 0);
        $byModule[$module][$human['status']]++;
        $wave = (string) $human['wave'];
        $byWave[$wave] ??= array_fill_keys(STATUSES, 0);
        $byWave[$wave][$human['status']]++;
    }
    ksort($byModule, SORT_STRING);
    ksort($byWave, SORT_STRING);

    $table = static function (string $label, array $rows): string {
        $out  = '| ' . $label . ' | ' . implode(' | ', STATUSES) . " | Total |\n";
        $out .= '| --- | ' . str_repeat('---: | ', count(STATUSES)) . "---: |\n";
        $totals = array_fill_keys(STATUSES, 0);
        foreach ($rows as $name => $counts) {
            $out .= '| ' . $name . ' | ' . implode(' | ', $counts) . ' | ' . array_sum($counts) . " |\n";
            foreach ($counts as $status => $n) {
                $totals[$status] += $n;
            }
        }

        return $out . '| **Total** | ' . implode(' | ', $totals) . ' | ' . array_sum($totals) . " |\n";
    };

    return "\n" . $table('Target module', $byModule) . "\n" . $table('Wave', $byWave) . "\n";
}

function splice_counts(string $readme, string $counts): string
{
    $start = strpos($readme, COUNTS_START);
    $end   = strpos($readme, COUNTS_END);
    if ($start === false || $end === false || $end < $start) {
        throw new \RuntimeException('README.md must contain ' . COUNTS_START . ' and ' . COUNTS_END);
    }

    return substr($readme, 0, $start + strlen(COUNTS_START)) . $counts . substr($readme, $end);
}

/**
 * @param array<string, array<string, mixed>> $records
 *
 * @return array<string, mixed>
 */
function build_index(array $records, array $static): array
{
    $functions = [];
    $byStatus  = array_fill_keys(STATUSES, 0);
    $byModule  = [];
    $byWave    = [];
    foreach ($records as $name => $record) {
        $human = $record['human'];
        $functions[] = [
            'name'       => $name,
            'start_line' => $record['static']['start_line'],
            'end_line'   => $record['static']['end_line'],
            'fan_out_core' => $record['static']['fan_out']['core'],
            'target_module' => $human['target_module'],
            'wave'       => $human['wave'],
            'status'     => $human['status'],
            'lts_status' => $human['lts_status'],
            'behavior_record' => $human['behavior_record'],
        ];
        if (isset($byStatus[$human['status']])) {
            $byStatus[$human['status']]++;
        }
        $byModule[$human['target_module']] = ($byModule[$human['target_module']] ?? 0) + 1;
        $byWave[(string) $human['wave']]   = ($byWave[(string) $human['wave']] ?? 0) + 1;
    }
    ksort($byModule, SORT_STRING);
    ksort($byWave, SORT_STRING);

    return [
        'schema'         => SCHEMA_VERSION,
        'source'         => SOURCE_FILE,
        'function_count' => count($functions),
        'counts'         => ['by_status' => $byStatus, 'by_module' => $byModule, 'by_wave' => $byWave],
        'conditional_definitions' => $static['conditional'],
        'functions'      => $functions,
    ];
}

/**
 * @param array<string, array<string, mixed>> $records
 */
function dead_code_report(array $records): string
{
    $rows = [];
    foreach ($records as $name => $record) {
        $fan = $record['static']['fan_out'];
        if ($fan['core'] <= DEAD_CODE_MAX_FAN_OUT) {
            $rows[] = [
                'name'    => $name,
                'core'    => $fan['core'],
                'outside' => $fan['core_outside_functions_php'],
                'tests'   => $fan['tests'],
                'plugins' => $fan['plugins'],
                'total'   => $fan['core'] + $fan['tests'] + $fan['plugins'],
                'module'  => $record['human']['target_module'],
            ];
        }
    }
    usort($rows, static fn(array $a, array $b): int => [$a['core'], $a['total'], $a['name']] <=> [$b['core'], $b['total'], $b['name']]);
    $unreferenced = array_filter($rows, static fn(array $row): bool => $row['total'] === 0);

    $out  = "<!--\nSPDX-FileCopyrightText: 2026 The Kadupul project and contributors\nSPDX-License-Identifier: GPL-3.0-or-later\n-->\n\n";
    $out .= "# Dead-code candidates in lib/functions.php\n\n";
    $out .= "Generated by `tools/functions-inventory.php`. Do not edit.\n\n";
    $out .= 'These are candidates only. A function is listed when its core reference count is ' . DEAD_CODE_MAX_FAN_OUT . " or fewer,\n";
    $out .= "not counting its own definition.\n";
    $out .= "The count is a word-boundary match over tracked PHP files, so it includes comments, strings and\n";
    $out .= "unrelated identifiers, and it counts callers inside `lib/functions.php` itself. A function whose only\n";
    $out .= "caller is another dead function still shows a reference.\n\n";
    $out .= "Third-party plugins are not in this repository. The `Plugins` column only covers tracked files under\n";
    $out .= "`plugins/`, which holds no plugin code, so a zero there is not evidence that no plugin calls the function.\n";
    $out .= "Audit a plugin checkout with `tools/functions-inventory.php --external-plugins=DIR` before deleting\n";
    $out .= "or deprecating anything. Do not remove a function on the count alone.\n\n";
    $out .= "## No references anywhere in tracked PHP\n\n";
    $out .= implode(', ', array_map(static fn(array $row): string => '`' . $row['name'] . '`', $unreferenced)) . "\n\n";
    $out .= "## Core references of " . DEAD_CODE_MAX_FAN_OUT . " or fewer\n\n";
    $out .= "| Function | Core | Core outside functions.php | Tests | Plugins | Target module |\n";
    $out .= "| --- | ---: | ---: | ---: | ---: | --- |\n";
    foreach ($rows as $row) {
        $out .= '| `' . $row['name'] . '` | ' . $row['core'] . ' | ' . $row['outside'] . ' | ' . $row['tests'] . ' | ' . $row['plugins'] . ' | ' . $row['module'] . " |\n";
    }

    return $out;
}

/**
 * Compares generated output with disk.
 *
 * @param array<string, string> $files
 *
 * @return array<int, string> one message per stale, missing or orphaned file
 */
function diff_against_disk(string $root, array $files): array
{
    $messages = [];
    foreach ($files as $path => $contents) {
        $full = $root . '/' . $path;
        if (!is_file($full)) {
            $messages[] = 'missing: ' . $path;
            continue;
        }
        $current = (string) file_get_contents($full);
        if ($current === $contents) {
            continue;
        }
        $messages[] = 'stale: ' . $path . "\n" . first_difference($current, $contents);
    }
    foreach (glob($root . '/' . OUTPUT_DIR . '/*.json') ?: [] as $path) {
        $relative = OUTPUT_DIR . '/' . basename($path);
        if (!isset($files[$relative]) && basename($path) !== 'seed.json') {
            $messages[] = 'orphan: ' . $relative . ' (function no longer declared in ' . SOURCE_FILE . ')';
        }
    }
    sort($messages, SORT_STRING);

    return $messages;
}

function first_difference(string $current, string $expected): string
{
    $a = explode("\n", $current);
    $b = explode("\n", $expected);
    $n = max(count($a), count($b));
    for ($i = 0; $i < $n; $i++) {
        if (($a[$i] ?? null) !== ($b[$i] ?? null)) {
            return '  line ' . ($i + 1) . ":\n    committed: " . trim((string) ($a[$i] ?? '(end of file)')) . "\n    generated: " . trim((string) ($b[$i] ?? '(end of file)'));
        }
    }

    return '  (differs in trailing bytes)';
}

function write_all(string $root, array $files): void
{
    $dir = $root . '/' . OUTPUT_DIR;
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new \RuntimeException('Cannot create ' . OUTPUT_DIR);
    }
    foreach ($files as $path => $contents) {
        if (file_put_contents($root . '/' . $path, $contents) === false) {
            throw new \RuntimeException('Cannot write ' . $path);
        }
    }
    foreach (glob($dir . '/*.json') ?: [] as $path) {
        $relative = OUTPUT_DIR . '/' . basename($path);
        if (!isset($files[$relative]) && basename($path) !== 'seed.json') {
            unlink($path);
            fwrite(STDERR, 'removed record for function no longer declared: ' . $relative . "\n");
        }
    }
}

/**
 * Functions declared in lib/functions.php that index.json does not list.
 *
 * Methods are ignored. Conditional and nested declarations count, because a
 * function defined inside an if block grows the global function table just as
 * a top-level one does. Names compare case-insensitively, as PHP resolves them.
 *
 * @return array<int, string>
 */
function find_unlisted_functions(string $root): array
{
    $source = file_get_contents($root . '/' . SOURCE_FILE);
    if ($source === false) {
        throw new \RuntimeException('Cannot read ' . SOURCE_FILE);
    }
    $listed = array_fill_keys(array_map('strtolower', read_baseline_names($root)), true);
    if ($listed === []) {
        throw new \RuntimeException(OUTPUT_DIR . '/index.json is missing or lists no functions');
    }
    $unlisted = [];
    foreach (find_declarations($source) as $declaration) {
        if ($declaration['context'] !== 'method' && !isset($listed[strtolower($declaration['name'])])) {
            $unlisted[] = $declaration['name'];
        }
    }

    return sorted_unique($unlisted);
}

/**
 * @param array<int, string> $argv
 */
function main(array $argv, ?string $root = null): int
{
    $root  ??= dirname(__DIR__);
    $check   = false;
    $accept  = false;
    $plugins = null;
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--check') {
            $check = true;
        } elseif ($arg === '--accept-new-functions') {
            $accept = true;
        } elseif (str_starts_with($arg, '--external-plugins=')) {
            $plugins = substr($arg, strlen('--external-plugins='));
        } else {
            fwrite(STDERR, 'unknown argument: ' . $arg . "\n");

            return 2;
        }
    }

    $result = generate($root, $check);

    if ($result['problems'] !== []) {
        fwrite(STDERR, "invalid human fields:\n  " . implode("\n  ", $result['problems']) . "\n");

        return 1;
    }

    if ($plugins !== null) {
        return report_external_plugins($result['records'], $plugins);
    }

    if ($check) {
        $messages = diff_against_disk($root, $result['files']);
        if ($messages === []) {
            print 'functions inventory is current (' . count($result['records']) . " functions)\n";

            return 0;
        }
        fwrite(STDERR, "functions inventory is stale. Run tools/functions-inventory.php and commit the result.\n" . implode("\n", $messages) . "\n");

        return 1;
    }

    if ($result['new_functions'] !== [] && !$accept) {
        fwrite(STDERR, SOURCE_FILE . " gained functions that index.json does not list: " . implode(', ', $result['new_functions']) . "\n"
            . "New code belongs in a module under src/. If the function really must be added here, re-run with --accept-new-functions.\n");

        return 1;
    }

    write_all($root, $result['files']);
    print 'wrote ' . count($result['records']) . " function records\n";

    return 0;
}

/**
 * @param array<string, array<string, mixed>> $records
 */
function report_external_plugins(array $records, string $directory): int
{
    if (!is_dir($directory)) {
        fwrite(STDERR, 'not a directory: ' . $directory . "\n");

        return 2;
    }
    $candidates = [];
    foreach ($records as $name => $record) {
        if ($record['static']['fan_out']['core'] <= DEAD_CODE_MAX_FAN_OUT) {
            $candidates[$name] = 0;
        }
    }
    $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
    $paths = [];
    foreach ($files as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $paths[] = $file->getPathname();
        }
    }
    sort($paths, SORT_STRING);
    foreach ($paths as $path) {
        preg_match_all('/\b[A-Za-z_][A-Za-z0-9_]*\b/', (string) file_get_contents($path), $matches);
        foreach (array_count_values($matches[0]) as $word => $n) {
            if (isset($candidates[$word])) {
                $candidates[$word] += $n;
            }
        }
    }
    ksort($candidates, SORT_STRING);
    foreach ($candidates as $name => $n) {
        print $name . "\t" . $n . "\n";
    }

    return 0;
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    exit(main($argv));
}
