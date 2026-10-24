<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/*
 * Counts `global` statements in first-party PHP and holds them to a committed
 * baseline that may only fall. Shared state is being moved into constructor
 * arguments, and this keeps new code from adding to what has to be moved.
 *
 * PHP rather than a regex: the tokenizer is the parser that runs the file, so
 * `global` inside a comment, a string or a heredoc, `$GLOBALS`, and a method or
 * property named global are never counted. TOKEN_PARSE is required for the
 * last case; without it the lexer reports `function global()` as T_GLOBAL.
 *
 * Usage:
 *   php tests/security/global_statement_inventory.php
 *       print the inventory; redirect it to refresh the baseline
 *   php tests/security/global_statement_inventory.php --check [BASELINE] [--base REF]
 *       fail when a count rises above BASELINE or falls below it; with --base,
 *       also fail when BASELINE allows more than the baseline at REF does
 */

namespace Kadupul\Tests\Security\GlobalStatements;

const BASELINE = 'tests/security/baselines/global_statements.baseline.tsv';
const HEADER = "file\tstatements\tvariables";
const EXCLUDED = ['include/vendor/', 'vendor/', 'tests/'];
const SRC = 'src/';
const REFRESH = 'php tests/security/global_statement_inventory.php > ' . BASELINE;

/**
 * @return array{statements: int, variables: int}
 */
function count_source(string $source): array
{
    $tokens = token_get_all($source, TOKEN_PARSE);
    $statements = 0;
    $variables = 0;
    $count = \count($tokens);
    for ($i = 0; $i < $count; $i++) {
        if (!\is_array($tokens[$i]) || $tokens[$i][0] !== T_GLOBAL) {
            continue;
        }
        $statements++;
        // Each name is separated by a comma at depth zero; `${expr}` names
        // can hold commas of their own inside the braces.
        $names = 1;
        $depth = 0;
        for ($i++; $i < $count; $i++) {
            $token = $tokens[$i];
            $text = \is_array($token) ? $token[1] : $token;
            if ($depth === 0 && ($text === ';' || (\is_array($token) && $token[0] === T_CLOSE_TAG))) {
                break;
            }
            if ($text === '{' || (\is_array($token) && $token[0] === T_DOLLAR_OPEN_CURLY_BRACES)) {
                $depth++;
            } elseif ($text === '}') {
                $depth--;
            } elseif ($text === ',' && $depth === 0) {
                $names++;
            }
        }
        $variables += $names;
    }

    return ['statements' => $statements, 'variables' => $variables];
}

/**
 * Tracked and untracked-but-not-ignored PHP files, so a new file counts
 * before it is staged and ignored build output (var/, include/vendor) never
 * does.
 *
 * @return list<string>
 */
function first_party_files(string $root): array
{
    $command = 'git -C ' . escapeshellarg($root) . ' ls-files -z --cached --others --exclude-standard -- ' . escapeshellarg('*.php');
    exec($command, $output, $status);
    if ($status !== 0) {
        throw new \RuntimeException('git ls-files failed in ' . $root);
    }
    $files = [];
    foreach (explode("\0", implode("\n", $output)) as $file) {
        if ($file === '' || !is_file($root . '/' . $file)) {
            continue;
        }
        foreach (EXCLUDED as $prefix) {
            if (str_starts_with($file, $prefix)) {
                continue 2;
            }
        }
        $files[] = $file;
    }
    $files = array_values(array_unique($files));
    sort($files, SORT_STRING);

    return $files;
}

/**
 * @return array<string, array{statements: int, variables: int}>
 */
function inventory(string $root): array
{
    $rows = [];
    foreach (first_party_files($root) as $file) {
        $source = file_get_contents($root . '/' . $file);
        if ($source === false) {
            throw new \RuntimeException('cannot read ' . $file);
        }
        try {
            $counts = count_source($source);
        } catch (\ParseError $e) {
            throw new \RuntimeException($file . ': ' . $e->getMessage(), 0, $e);
        }
        if ($counts['statements'] > 0) {
            $rows[$file] = $counts;
        }
    }

    return $rows;
}

/**
 * @param array<string, array{statements: int, variables: int}> $rows
 */
function render(array $rows): string
{
    ksort($rows, SORT_STRING);
    $out = HEADER . "\n";
    foreach ($rows as $file => $counts) {
        $out .= $file . "\t" . $counts['statements'] . "\t" . $counts['variables'] . "\n";
    }

    return $out;
}

/**
 * @return array<string, array{statements: int, variables: int}>
 */
function parse_baseline(string $text): array
{
    $lines = preg_split('/\r?\n/', rtrim($text, "\r\n"));
    if ($lines === false || ($lines[0] ?? '') !== HEADER) {
        throw new \RuntimeException('baseline does not start with the header "' . str_replace("\t", '\t', HEADER) . '"');
    }
    $rows = [];
    foreach (\array_slice($lines, 1) as $number => $line) {
        if (preg_match('/\A([^\t]+)\t([1-9][0-9]*)\t([1-9][0-9]*)\z/', $line, $m) !== 1 || isset($rows[$m[1]])) {
            throw new \RuntimeException('baseline line ' . ($number + 2) . ' is malformed or repeats a file: ' . $line);
        }
        $rows[$m[1]] = ['statements' => (int) $m[2], 'variables' => (int) $m[3]];
    }

    return $rows;
}

/**
 * @param array<string, array{statements: int, variables: int}> $rows
 * @return array{files: int, statements: int, variables: int, src_statements: int, src_variables: int}
 */
function totals(array $rows): array
{
    $totals = ['files' => \count($rows), 'statements' => 0, 'variables' => 0, 'src_statements' => 0, 'src_variables' => 0];
    foreach ($rows as $file => $counts) {
        $totals['statements'] += $counts['statements'];
        $totals['variables'] += $counts['variables'];
        if (str_starts_with($file, SRC)) {
            $totals['src_statements'] += $counts['statements'];
            $totals['src_variables'] += $counts['variables'];
        }
    }

    return $totals;
}

/**
 * Rises and falls are reported separately because they need different fixes:
 * a rise is removed from the code, a fall is recorded by lowering the baseline.
 *
 * @param array<string, array{statements: int, variables: int}> $baseline
 * @param array<string, array{statements: int, variables: int}> $current
 * @return array{rises: list<string>, falls: list<string>}
 */
function compare(array $baseline, array $current): array
{
    $rises = [];
    $falls = [];
    $files = array_keys($baseline + $current);
    sort($files, SORT_STRING);
    $zero = ['statements' => 0, 'variables' => 0];
    foreach ($files as $file) {
        $was = $baseline[$file] ?? $zero;
        $now = $current[$file] ?? $zero;
        $change = $file . ': ' . $was['statements'] . ' -> ' . $now['statements'] . ' statements, '
            . $was['variables'] . ' -> ' . $now['variables'] . ' variables';
        if ($now['statements'] > $was['statements'] || $now['variables'] > $was['variables']) {
            if (!isset($baseline[$file]) && str_starts_with($file, SRC)) {
                $change .= ' (a new file under src/ may not use global)';
            }
            $rises[] = $change;
        } elseif ($now !== $was) {
            $falls[] = $change;
        }
    }

    return ['rises' => $rises, 'falls' => $falls];
}

/**
 * The committed baseline against the one at the base revision. Totals cannot
 * grow, and src/ cannot gain a file. Other files are compared only through the
 * totals, so a rename or a move of a global between legacy files still passes.
 *
 * @param array<string, array{statements: int, variables: int}> $base
 * @param array<string, array{statements: int, variables: int}> $baseline
 * @return list<string>
 */
function compare_with_base(array $base, array $baseline): array
{
    $errors = [];
    $before = totals($base);
    $after = totals($baseline);
    foreach (['statements', 'variables', 'src_statements', 'src_variables'] as $key) {
        if ($after[$key] > $before[$key]) {
            $errors[] = 'baseline ' . str_replace('_', '/ ', $key) . ' rose from ' . $before[$key] . ' to ' . $after[$key];
        }
    }
    foreach (array_keys($baseline) as $file) {
        if (str_starts_with($file, SRC) && !isset($base[$file])) {
            $errors[] = 'baseline adds ' . $file . '; a new file under src/ may not use global';
        }
    }

    return $errors;
}

function base_baseline(string $root, string $ref): string
{
    $command = 'git -C ' . escapeshellarg($root) . ' show ' . escapeshellarg($ref . ':' . BASELINE) . ' 2>/dev/null';
    exec($command, $output, $status);

    // A base without the file predates the ratchet, so there is nothing to compare.
    return $status === 0 ? implode("\n", $output) . "\n" : '';
}

/**
 * @param list<string> $argv
 */
function main(array $argv, string $root): int
{
    $args = \array_slice($argv, 1);
    try {
        if ($args === []) {
            print render(inventory($root));

            return 0;
        }
        if ($args[0] !== '--check') {
            throw new \InvalidArgumentException('usage: global_statement_inventory.php [--check [BASELINE] [--base REF]]');
        }
        $args = \array_slice($args, 1);
        $ref = null;
        $position = array_search('--base', $args, true);
        if ($position !== false) {
            $ref = $args[$position + 1] ?? throw new \InvalidArgumentException('--base needs a revision');
            array_splice($args, $position, 2);
        }
        if (\count($args) > 1) {
            throw new \InvalidArgumentException('usage: global_statement_inventory.php [--check [BASELINE] [--base REF]]');
        }
        $path = $args[0] ?? $root . '/' . BASELINE;
        $text = is_file($path) ? file_get_contents($path) : false;
        if ($text === false) {
            throw new \RuntimeException('baseline not found: ' . $path);
        }
        $baseline = parse_baseline($text);
        $current = inventory($root);
    } catch (\InvalidArgumentException | \RuntimeException $e) {
        fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n");

        return 2;
    }

    $status = 0;
    $result = compare($baseline, $current);
    if ($result['rises'] !== []) {
        print "ERROR: global statements rose above the baseline:\n  " . implode("\n  ", $result['rises']) . "\n";
        print "Pass the value as an argument or inject the service instead. Do not raise the baseline to make this pass.\n";
        $status = 1;
    }
    if ($result['falls'] !== []) {
        print "ERROR: global statements fell below the baseline, so lower it to keep the ratchet tight:\n  " . implode("\n  ", $result['falls']) . "\n";
        print '  ' . REFRESH . "\n";
        $status = 1;
    }
    if ($ref !== null) {
        $base = base_baseline($root, $ref);
        $errors = [];
        try {
            $errors = $base === '' ? [] : compare_with_base(parse_baseline($base), $baseline);
        } catch (\RuntimeException $e) {
            $errors = ['baseline at ' . $ref . ': ' . $e->getMessage()];
        }
        if ($errors !== []) {
            print 'ERROR: the baseline allows more than it does at ' . $ref . ":\n  " . implode("\n  ", $errors) . "\n";
            $status = 1;
        }
    }

    $totals = totals($current);
    $summary = $totals['statements'] . ' global statements naming ' . $totals['variables'] . ' variables in '
        . $totals['files'] . ' files; src/ has ' . $totals['src_statements'] . ' statements naming '
        . $totals['src_variables'] . ' variables';
    print ($status === 0 ? 'OK: ' : 'Current: ') . $summary . "\n";

    return $status;
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    exit(main(array_values($argv), \dirname(__DIR__, 2)));
}
