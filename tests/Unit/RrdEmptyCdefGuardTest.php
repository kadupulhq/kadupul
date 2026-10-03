<?php

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

/*
 * Source-scan tests for the empty CDEF guard fix.
 *
 * Aggregate graphs can produce an empty RPN expression for GPRINT items
 * whose consolidation function does not match the data source.  Without
 * the guard, rrdtool_function_graph() emits a bare "CDEF:cdefX=" line
 * that rrdtool rejects.
 *
 * The fix adds: if ($cdef_string === '') { continue; }
 * before the CDEF name generation line in lib/rrd.php.
 *
 * The cdef_id UPDATE in lib/aggregate.php uses the checked aggregate helper,
 * which prepares the SQL on the selected PDO and binds its parameters.
 */

// --- helpers ---

function getRrdSource(): string
{
    $path = __DIR__ . '/../../lib/rrd.php';
    $src  = file_get_contents($path);
    expect($src)->not->toBeFalse('Failed to read lib/rrd.php');

    return $src;
}

function getAggregateSource(): string
{
    $path = __DIR__ . '/../../lib/aggregate.php';
    $src  = file_get_contents($path);
    expect($src)->not->toBeFalse('Failed to read lib/aggregate.php');

    return $src;
}

// --- lib/rrd.php: empty cdef guard ---

test('rrd.php has empty cdef_string guard before CDEF name generation', function () {
    $src = getRrdSource();

    // The guard: if ($cdef_string === '') { ... continue; }
    // must appear before the CDEF:cdef name generation line
    $guardPos = strpos($src, "\$cdef_string === ''");
    expect($guardPos)->not->toBeFalse(
        "lib/rrd.php must contain the empty cdef_string guard"
    );

    // The CDEF name generation line follows after the guard
    $cdefNamePos = strpos($src, "CDEF:cdef", $guardPos);
    expect($cdefNamePos)->not->toBeFalse(
        "'CDEF:cdef' name generation must appear after the empty cdef guard"
    );
    expect($cdefNamePos)->toBeGreaterThan(
        $guardPos,
        "The empty cdef guard must precede the CDEF name generation"
    );
});

test('empty cdef guard uses strict identity comparison', function () {
    $src = getRrdSource();

    // Must use === not == to avoid false positives on '0' or other falsy strings
    $pattern = '/if\s*\(\s*\$cdef_string\s*===\s*\'\'\s*\)/';
    expect(preg_match($pattern, $src))->toBe(
        1,
        "The empty cdef guard must use strict === comparison"
    );
});

test('empty cdef guard block contains continue statement', function () {
    $src = getRrdSource();

    // The guard block must have a continue to skip the current loop iteration
    $pattern = '/if\s*\(\s*\$cdef_string\s*===\s*\'\'\s*\)\s*\{[^}]*continue;/s';
    expect(preg_match($pattern, $src))->toBe(
        1,
        "The empty cdef guard must contain a continue statement"
    );
});

test('empty cdef guard includes debug logging', function () {
    $src = getRrdSource();

    // The guard should log before continuing, so the skip is traceable
    $pattern = '/if\s*\(\s*\$cdef_string\s*===\s*\'\'\s*\)\s*\{[^}]*cacti_log\([^)]*Empty CDEF/s';
    expect(preg_match($pattern, $src))->toBe(
        1,
        "The empty cdef guard should log a debug message about the empty CDEF"
    );
});

// --- lib/aggregate.php: checked prepared cdef_id UPDATE ---

test('aggregate.php uses the checked prepared helper for cdef_id UPDATE', function () {
    $src = getAggregateSource();

    // Require the checked caller, both placeholders and the exact parameter order.
    $pattern = <<<'REGEX'
/aggregate_graph_execute\s*\(\s*'(UPDATE graph_templates_item\s+SET cdef_id\s*=\s*\?\s+WHERE id\s*=\s*\?)',\s*array\(\s*\$new_cdef_id\s*,\s*\$graph_template_item\['id'\]\s*\)\s*\)/s
REGEX;
    expect(preg_match($pattern, $src))->toBe(
        1,
        "lib/aggregate.php must use the checked prepared helper with ordered CDEF and item parameters"
    );
});

test('checked CDEF update binds values on the selected real PDO', function () {
    require_once __DIR__ . '/../../lib/api_aggregate.php';
    $source = getAggregateSource();
    $pattern = "/aggregate_graph_execute\s*\(\s*'(UPDATE graph_templates_item\s+SET cdef_id\s*=\s*\?\s+WHERE id\s*=\s*\?)'/s";
    expect(preg_match($pattern, $source, $matches))->toBe(1);
    $database = new PDO('sqlite::memory:');
    $other = new PDO('sqlite::memory:');
    foreach ([$database, $other] as $connection) {
        $connection->exec('CREATE TABLE graph_templates_item (id INTEGER PRIMARY KEY, cdef_id INTEGER NOT NULL)');
        $connection->exec('INSERT INTO graph_templates_item VALUES (11, 0), (12, 0)');
    }
    $previous = [];
    foreach (['database_sessions', 'database_hostname', 'database_port', 'database_default'] as $name) {
        $previous[$name] = [array_key_exists($name, $GLOBALS), $GLOBALS[$name] ?? null];
    }
    try {
        $GLOBALS['database_hostname'] = 'rrd-cdef-test';
        $GLOBALS['database_port'] = 0;
        $GLOBALS['database_default'] = 'rrd-cdef-test';
        $GLOBALS['database_sessions'] = ['rrd-cdef-test:0:rrd-cdef-test' => $database, 'other' => $other];
        expect(aggregate_graph_execute($matches[1], [167, 11]))->toBeTrue();
        expect($database->query('SELECT cdef_id FROM graph_templates_item ORDER BY id')->fetchAll(PDO::FETCH_COLUMN))->toBe([167, 0]);
        expect($other->query('SELECT cdef_id FROM graph_templates_item ORDER BY id')->fetchAll(PDO::FETCH_COLUMN))->toBe([0, 0]);
        expect(aggregate_graph_execute($matches[1], [999, '11 OR 1=1']))->toBeTrue();
        expect($database->query('SELECT cdef_id FROM graph_templates_item ORDER BY id')->fetchAll(PDO::FETCH_COLUMN))->toBe([167, 0]);
    } finally {
        foreach ($previous as $name => [$exists, $value]) {
            if ($exists) {
                $GLOBALS[$name] = $value;
            } else {
                unset($GLOBALS[$name]);
            }
        }
    }
});

test('aggregate.php cdef_id UPDATE does not use string interpolation', function () {
    $src = getAggregateSource();

    // Old pattern: "UPDATE graph_templates_item SET cdef_id=$new_cdef_id WHERE id=" . $graph_template_item["id"]
    // This must not exist anymore
    $pattern = '/db_execute\s*\(\s*"UPDATE graph_templates_item\s+SET cdef_id=\$/';
    expect(preg_match($pattern, $src))->toBe(
        0,
        "lib/aggregate.php must not use string-interpolated db_execute for cdef_id UPDATE"
    );
});

test('aggregate.php cdef_id UPDATE uses parameter binding', function () {
    $src = getAggregateSource();

    // The prepared statement should use ? placeholders and an array parameter
    $pattern = "/UPDATE graph_templates_item\s+SET cdef_id\s*=\s*\?\s+WHERE id\s*=\s*\?/s";
    expect(preg_match($pattern, $src))->toBe(
        1,
        "cdef_id UPDATE must use ? placeholders for parameter binding"
    );
});
