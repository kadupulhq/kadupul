<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

// Prints a PHPUnit --filter expression selecting one shard of the test classes
// in a `pest --list-tests` listing read from standard input.
//
// Pest's own --shard only recognises classes named Tests\..., while this suite
// generates P\Unit\... and native PHPUnit classes in the global namespace. It
// therefore matches nothing and runs the whole suite in every shard. Every
// listed class is assigned to exactly one shard here, and every test file is
// still loaded, so function stubs declared at load time behave as in a serial
// run. Pest matches --filter against the printable class name, which drops the
// generated P\ prefix.

if ($argc !== 3 || preg_match('/^[1-9][0-9]*$/D', $argv[1]) !== 1 || preg_match('/^[1-9][0-9]*$/D', $argv[2]) !== 1) {
    fwrite(STDERR, "Usage: pest_shard_filter.php INDEX TOTAL < pest-list-tests-output\n");
    exit(2);
}
$index = (int) $argv[1];
$total = (int) $argv[2];
if ($index > $total) {
    fwrite(STDERR, "The shard index exceeds the shard count.\n");
    exit(2);
}

$classes = [];
foreach (preg_split('/\R/', (string) stream_get_contents(STDIN)) as $line) {
    if (preg_match('/^ - ([A-Za-z_\x80-\xff\\\\][A-Za-z0-9_\x80-\xff\\\\]*)::/', $line, $match) === 1) {
        $classes[preg_replace('/^P\\\\/', '', $match[1])] = true;
    }
}
$classes = array_keys($classes);
sort($classes, SORT_STRING);
if (count($classes) < $total) {
    fwrite(STDERR, 'Only ' . count($classes) . " test classes were listed; refusing to build an empty shard.\n");
    exit(1);
}

// Round-robin over the sorted list spreads each directory across the shards.
$selected = [];
foreach ($classes as $position => $class) {
    if ($position % $total === $index - 1) {
        $selected[] = preg_quote($class, '/');
    }
}

fwrite(STDERR, sprintf("Shard %d/%d: %d of %d test classes\n", $index, $total, count($selected), count($classes)));
echo '/^(?:' . implode('|', $selected) . ')::/', PHP_EOL;
