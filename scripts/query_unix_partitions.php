<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

// Ported from scripts/query_unix_partitions.pl; the usage text is kept byte for byte.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/script_command.php';

const QUERY_UNIX_PARTITIONS_USAGE = "usage:\n\n"
    . "./query_unix_partitions.pl index\n"
    . "./query_unix_partitions.pl num_indexes\n"
    . "./query_unix_partitions.pl query {device,mount,total,used,available,percent}\n"
    . "./query_unix_partitions.pl get {device,mount,total,used,available,percent} DEVICE\n";

/**
 * The allow-listed request, or null when the usage text is due. A value
 * that fails its allow-list becomes null, as it became undef in Perl.
 *
 * @param list<string> $arguments the arguments after the script name
 * @return array{string, ?string, ?string}|null
 */
function query_unix_partitions_request(array $arguments): ?array
{
    $allow = static fn(int $index, string $pattern): ?string => preg_match($pattern, $arguments[$index] ?? '', $match) === 1 ? $match[1] : null;
    $mode = $allow(0, '/^([\w]+)$/');
    if (!in_array($mode, ['query', 'get', 'index', 'num_indexes'], true)) {
        return null;
    }

    return [$mode, $allow(1, '/^([\w]+)$/'), $allow(2, '/^([\w\/-]+)$/')];
}

/** @param array{string, ?string, ?string} $request */
function query_unix_partitions_render(array $request, string|false $df): string
{
    [$mode, $field, $device] = $request;
    $output = '';
    $count = 0;
    $lines = explode("\n", (string) $df);
    foreach ($lines as $index => $line) {
        $line .= $index < count($lines) - 1 ? "\n" : '';
        // /dev/hda2             20157744  18553884    579860  97% /var
        if (preg_match('/^(\/\S+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)%\s+(\/\S*)$/', $line, $match) !== 1) {
            continue;
        }
        $values = ['device' => $match[1], 'mount' => $match[6], 'total' => $match[2], 'used' => $match[3], 'available' => $match[4], 'percent' => $match[5]];
        $value = $values[$field ?? ''] ?? '';

        if ($mode === 'index') {
            $output .= $match[1] . "\n";
        } elseif ($mode === 'num_indexes') {
            $count++;
        } elseif ($mode === 'get' && $device === $match[1]) {
            $output .= $value;
        } elseif ($mode === 'query') {
            $output .= $values['device'] . ':' . $value . "\n";
        }
    }

    return $mode === 'num_indexes' ? $output . $count . "\n" : $output;
}

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    $request = query_unix_partitions_request(array_slice(array_map('strval', $_SERVER['argv']), 1));
    print $request === null ? QUERY_UNIX_PARTITIONS_USAGE : query_unix_partitions_render($request, script_command_output(['/bin/df', '-P', '-k', '-l']));
}
