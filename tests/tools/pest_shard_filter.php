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

// Mixed PHPUnit/Pest listings can color only the DSL lines even when native
// class lines are plain. Presentation must not remove those classes from CI.
$listing = preg_replace('/\x1B\[[0-9;]*m/', '', (string) stream_get_contents(STDIN));
if ($listing === null) {
    fwrite(STDERR, "Unable to parse the test class listing.\n");
    exit(1);
}
$classes = [];
foreach (preg_split('/\R/', $listing) as $line) {
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

// Greedy longest-first assignment: classes are taken in descending weight (ties
// by name) and each goes to the currently lightest shard (ties to the lowest
// index), so the split is deterministic and every class lands in exactly one
// shard. Weights are the checked-in pest_shard_weights.json, in units of about
// ten seconds of measured test time; a class not listed there weighs 1.
$weights = json_decode((string) file_get_contents(__DIR__ . '/pest_shard_weights.json'), true, 4, JSON_THROW_ON_ERROR);
if (!is_array($weights)) {
    fwrite(STDERR, "The shard weight map must be a JSON object.\n");
    exit(1);
}
$weightOf = static function (string $class) use ($weights): int {
    $weight = $weights[$class] ?? 1;
    return is_int($weight) && $weight > 0 ? $weight : 1;
};
usort($classes, static fn(string $a, string $b): int => [$weightOf($b), $a] <=> [$weightOf($a), $b]);
$loads = array_fill(0, $total, 0);
$selected = [];
foreach ($classes as $class) {
    $lightest = array_search(min($loads), $loads, true);
    $loads[$lightest] += $weightOf($class);
    if ($lightest === $index - 1) {
        $selected[] = preg_quote($class, '/');
    }
}

fwrite(STDERR, sprintf("Shard %d/%d: %d of %d test classes, weight %d\n", $index, $total, count($selected), count($classes), $loads[$index - 1]));
echo '/^(?:' . implode('|', $selected) . ')::/', PHP_EOL;
