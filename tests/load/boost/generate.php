<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/*
 * Queues six poll intervals (steps 1 to 6 after the t0 that seed.php wrote
 * into every RRD) in poller_output_boost for the sources in kbl_plan.
 *
 * Rows go in with the poller's own statement (INSERT ... ON DUPLICATE KEY
 * UPDATE output = VALUES(output), from boost_poller_on_demand()), so:
 *  - 1 in 100 samples is inserted twice; 1 in 200 of those with a different
 *    output first, so the later write must win;
 *  - 1 in 100 sources also gets a stale row at t0, which RRDtool already holds
 *    and Boost's --skip-past-updates must drop;
 *  - insert order is shuffled within each block of sources;
 *  - values.php puts 'U' in 1 of 200 fields.
 *
 * Cycle mode (--cycle=C, C >= 1) queues one Boost cycle of
 * KBL_CYCLE_STEPS steps instead: steps (C - 1) * 12 + 1 to C * 12. The sources
 * in kbl_sample always get cycle 1, because run.sh restores their RRD files
 * before every cell so their dumps can be compared across cells.
 *
 * Usage, as the poller account with KBL_APP set:
 *   php generate.php [--cycle=C] [--workers=W]
 */

require __DIR__ . '/values.php';

const KBL_CYCLE_STEPS = 12;

$options = [];
foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/^--([a-z]+)=(\d+)$/D', $argument, $match) === 1) {
        $options[$match[1]] = (int) $match[2];
    }
}
$cycle = $options['cycle'] ?? 0;
$workers = max(1, $options['workers'] ?? 1);
$worker = $options['worker'] ?? null;

$app = getenv('KBL_APP') ?: '/opt/kadupul/app';
chdir($app);
require $app . '/include/cli_check.php';

/** @return list<int> */
function kbl_cycle_steps(int $cycle): array
{
    return $cycle === 0 ? range(1, KBL_STEPS) : range(($cycle - 1) * KBL_CYCLE_STEPS + 1, $cycle * KBL_CYCLE_STEPS);
}

$seed = (int) db_fetch_cell("SELECT value FROM kbl_meta WHERE name = 'seed'");
$t0 = (int) db_fetch_cell("SELECT value FROM kbl_meta WHERE name = 't0'");
$count = (int) db_fetch_cell("SELECT value FROM kbl_meta WHERE name = 'count'");
if ($count < 1 || $t0 < 1) {
    fwrite(STDERR, "generate.php: kbl_meta is missing; run seed.php clone first\n");
    exit(1);
}
if ($worker === null) {
    if ((int) db_fetch_cell('SELECT COUNT(*) FROM poller_output_boost') > 0) {
        fwrite(STDERR, "generate.php: poller_output_boost is not empty\n");
        exit(1);
    }
    if ($workers > 1) {
        $start = microtime(true);
        $processes = [];
        for ($k = 0; $k < $workers; $k++) {
            $processes[] = proc_open([PHP_BINARY, __FILE__, '--cycle=' . $cycle, '--workers=' . $workers, '--worker=' . $k], [1 => STDOUT, 2 => STDERR], $pipes);
        }
        $failed = 0;
        foreach ($processes as $process) {
            $failed += proc_close($process) === 0 ? 0 : 1;
        }
        if ($failed > 0) {
            fwrite(STDERR, "generate.php: $failed workers failed\n");
            exit(1);
        }
        printf("generate: cycle %d, %d workers, %d rows queued in %.1f s\n", $cycle, $workers, db_fetch_cell('SELECT COUNT(*) FROM poller_output_boost'), microtime(true) - $start);
        exit(0);
    }
}

$sample = [];
if ($cycle > 0) {
    foreach (db_fetch_assoc('SELECT n FROM kbl_sample') ?: [] as $row) {
        $sample[(int) $row['n']] = true;
    }
}
$start = microtime(true);
$inserted = 0;
$duplicates = 0;
$stale = 0;
$block = 5000;

for ($from = 1; $from <= $count; $from += $block) {
    if ($worker !== null && intdiv($from - 1, $block) % $workers !== $worker) {
        continue;
    }
    $plan = db_fetch_assoc_prepared('SELECT n, kind, ldid FROM kbl_plan WHERE n BETWEEN ? AND ? ORDER BY n', [$from, $from + $block - 1]);
    $rows = [];
    foreach ($plan as $source) {
        $n = (int) $source['n'];
        $ldid = (int) $source['ldid'];
        foreach (kbl_cycle_steps(isset($sample[$n]) ? 1 : $cycle) as $step) {
            $time = $t0 + $step * KBL_STEP;
            foreach (kbl_rows($seed, $n, $source['kind'], $step) as [$name, $output]) {
                $key = hash('xxh64', "$seed:$ldid:$time:$name");
                if (kbl_hash($seed, $n, $step, 'dup:' . $name) % 100 === 0) {
                    $first = kbl_hash($seed, $n, $step, 'dupdiff:' . $name) % 2 === 0 && is_numeric($output) ? (string) ($output + 1) : $output;
                    $rows[$key . 'a'] = [$ldid, $name, $time, $first];
                    $duplicates++;
                }
                $rows[$key . 'b'] = [$ldid, $name, $time, $output];
            }
        }
        if (kbl_hash($seed, $n, 0, 'stale') % 100 === 0) {
            foreach (kbl_rows($seed, $n, $source['kind'], 0) as [$name, $output]) {
                $rows[hash('xxh64', "$seed:$ldid:$t0:$name") . 'b'] = [$ldid, $name, $t0, $output];
                $stale++;
            }
        }
    }

    // Shuffle by key, but keep each duplicate's first write ahead of its second.
    uksort($rows, static fn(string $a, string $b): int => strcmp($a, $b));

    foreach (array_chunk($rows, 1000) as $chunk) {
        $values = [];
        $params = [];
        foreach ($chunk as [$ldid, $name, $time, $output]) {
            $values[] = '(?, ?, FROM_UNIXTIME(?), ?)';
            array_push($params, $ldid, $name, $time, $output);
        }
        if (db_execute_prepared('INSERT INTO poller_output_boost (local_data_id, rrd_name, time, output) VALUES ' . implode(', ', $values) . ' ON DUPLICATE KEY UPDATE output = VALUES(output)', $params) === false) {
            fwrite(STDERR, "generate.php: insert failed\n");
            exit(1);
        }
        $inserted += count($chunk);
    }
}

if ($worker !== null) {
    exit(0);
}
$rowsInTable = (int) db_fetch_cell('SELECT COUNT(*) FROM poller_output_boost');
printf(
    "generate: %d sources, %d inserts (%d duplicate writes, %d stale rows), %d rows queued in %.1f s\n",
    $count,
    $inserted,
    $duplicates,
    $stale,
    $rowsInTable,
    microtime(true) - $start,
);
