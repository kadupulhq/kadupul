<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/*
 * host.php (the device export) and lib/auth.php (the basic auth mapfile) pass
 * the separator, enclosure and escape to fputcsv() and str_getcsv()
 * explicitly, because PHP 8.4 deprecates leaving the escape out. The values
 * are PHP's own defaults, so parsing and output must not change. fputcsv()
 * gets five arguments only: main still declares PHP 8.0, which has no $eol.
 */

$root = dirname(__DIR__, 2);

test('the production CSV calls pass the escape argument explicitly', function () use ($root) {
    $host = file_get_contents($root . '/host.php');
    $auth = file_get_contents($root . '/lib/auth.php');

    expect($host)->toContain("fputcsv(\$stdout, \$columns, ',', '\"', '\\\\');")
        ->and($host)->toContain("fputcsv(\$stdout, \$h, ',', '\"', '\\\\');")
        ->and($auth)->toContain("str_getcsv(\$r, ',', '\"', '\\\\')");
});

test('str_getcsv with explicit defaults parses quotes and backslashes as before', function () {
    $line = "\"CORP\\jdoe\",\"quoted \"\"value\"\",here\",plain";

    expect(str_getcsv($line, ',', '"', '\\'))->toBe(['CORP\jdoe', 'quoted "value",here', 'plain']);
});

test('fputcsv with explicit defaults writes and round-trips the same output', function () {
    $fields = ['CORP\jdoe', 'quoted "value" here', 'a,field,with,commas'];

    $stream = fopen('php://memory', 'r+');
    fputcsv($stream, $fields, ',', '"', '\\');
    rewind($stream);
    $output = stream_get_contents($stream);
    fclose($stream);

    expect($output)->toBe("\"CORP\\jdoe\",\"quoted \"\"value\"\" here\",\"a,field,with,commas\"\n")
        ->and(str_getcsv(rtrim($output, "\n"), ',', '"', '\\'))->toBe($fields);
});
