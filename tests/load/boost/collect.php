<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/*
 * Reads the raw files run.sh fetched into results/<run id>/ and writes
 * summary.md, cells.csv (one row per cell), and per cell
 * db/<cell>/status-deltas.csv and db/<cell>/digests-top20.tsv.
 *
 * Usage: php collect.php <results directory>
 */

$dir = rtrim($argv[1] ?? '', '/');
if ($dir === '' || !is_dir($dir . '/cells')) {
    fwrite(STDERR, "usage: php collect.php <results directory>\n");
    exit(2);
}

/** @return array<string, string> */
function read_env(string $file): array
{
    $values = [];
    foreach (is_file($file) ? file($file, FILE_IGNORE_NEW_LINES) : [] as $line) {
        if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*)=(.*)$/', $line, $match) === 1) {
            $values[$match[1]] = $match[2];
        }
    }

    return $values;
}

/** @return list<array<string, string>> */
function read_table(string $file, string $separator): array
{
    if (!is_file($file)) {
        return [];
    }
    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false || $lines === []) {
        return [];
    }
    $header = explode($separator, array_shift($lines));
    $rows = [];
    foreach ($lines as $line) {
        $fields = explode($separator, $line, count($header));
        if (count($fields) === count($header)) {
            $rows[] = array_combine($header, $fields);
        }
    }

    return $rows;
}

/** @return array<string, string> name => value from a two-column TSV with a header. */
function read_settings(string $file): array
{
    $settings = [];
    foreach (read_table($file, "\t") as $row) {
        $settings[$row['name']] = $row['value'] ?? '';
    }

    return $settings;
}

/**
 * vmstat -n -t 1 rows inside [start, end]: average and peak busy (us+sy) and
 * iowait percentages. The trailing timestamp is UTC on the test hosts.
 *
 * @return array{busy_avg: ?float, busy_max: ?float, wa_avg: ?float, wa_max: ?float}
 */
function cpu_stats(string $file, float $start, float $end): array
{
    $busy = [];
    $wait = [];
    foreach (is_file($file) ? file($file, FILE_IGNORE_NEW_LINES) : [] as $line) {
        $fields = preg_split('/\s+/', trim($line));
        // procps 4 adds a gu column, so the timestamp is read from the end.
        if ($fields === false || count($fields) < 19 || !ctype_digit($fields[0])) {
            continue;
        }
        $time = strtotime($fields[count($fields) - 2] . ' ' . $fields[count($fields) - 1] . ' UTC');
        if ($time === false || $time < floor($start) || $time > ceil($end)) {
            continue;
        }
        $busy[] = (int) $fields[12] + (int) $fields[13];
        $wait[] = (int) $fields[15];
    }
    if ($busy === []) {
        return ['busy_avg' => null, 'busy_max' => null, 'wa_avg' => null, 'wa_max' => null];
    }

    return [
        'busy_avg' => array_sum($busy) / count($busy),
        'busy_max' => (float) max($busy),
        'wa_avg' => array_sum($wait) / count($wait),
        'wa_max' => (float) max($wait),
    ];
}

/** @return array<string, float> class => peak VmHWM in MiB */
function peak_rss(string $file): array
{
    $peaks = [];
    foreach (read_table($file, ',') as $row) {
        $mib = (int) $row['hwm_kb'] / 1024;
        $peaks[$row['class']] = max($peaks[$row['class']] ?? 0.0, $mib);
    }

    return $peaks;
}

/**
 * Deltas between 5 s SHOW GLOBAL STATUS samples. A server restart resets the
 * counters (Uptime falls); that interval is dropped rather than counted as a
 * negative rate.
 *
 * @return array{deltas: list<array<string, float>>, totals: array<string, float>, peaks: array<string, float>, last: array<string, float>}
 */
function status_deltas(string $file): array
{
    $samples = read_table($file, ',');
    $gauges = ['Threads_running', 'Threads_connected', 'Innodb_buffer_pool_pages_dirty', 'Innodb_row_lock_current_waits', 'Max_used_connections', 'Uptime'];
    $deltas = [];
    $totals = [];
    $peaks = [];
    $last = [];
    for ($i = 1; $i < count($samples); $i++) {
        $a = $samples[$i - 1];
        $b = $samples[$i];
        $seconds = (float) $b['epoch'] - (float) $a['epoch'];
        if ($seconds <= 0 || (float) $b['Uptime'] < (float) $a['Uptime']) {
            continue;
        }
        $row = ['epoch' => (float) $b['epoch'], 'seconds' => $seconds];
        foreach ($b as $name => $value) {
            if ($name === 'epoch') {
                continue;
            }
            if (in_array($name, $gauges, true)) {
                $row[$name] = (float) $value;
                $peaks[$name] = max($peaks[$name] ?? 0.0, (float) $value);
                continue;
            }
            $delta = (float) $value - (float) $a[$name];
            $row[$name] = $delta;
            $totals[$name] = ($totals[$name] ?? 0.0) + $delta;
            $peaks[$name . '_per_s'] = max($peaks[$name . '_per_s'] ?? 0.0, $delta / $seconds);
        }
        $totals['seconds'] = ($totals['seconds'] ?? 0.0) + $seconds;
        $deltas[] = $row;
    }
    foreach ($samples === [] ? [] : end($samples) as $name => $value) {
        $last[$name] = (float) $value;
    }

    return ['deltas' => $deltas, 'totals' => $totals, 'peaks' => $peaks, 'last' => $last];
}

function fmt(?float $value, int $decimals = 0): string
{
    return $value === null ? 'n/a' : number_format($value, $decimals, '.', ',');
}

function md_row(array $cells): string
{
    return '| ' . implode(' | ', array_map(static fn($c): string => str_replace('|', '\|', (string) $c), $cells)) . " |\n";
}

/** Statement class for the queue-path share. */
function digest_class(string $text): string
{
    if (stripos($text, 'kadupul_messenger_messages') !== false) {
        return 'messenger transport';
    }
    if (stripos($text, 'poller_output_boost_processes') !== false && stripos($text, 'run_id') !== false) {
        return 'run-scoped status';
    }
    if (preg_match('/^(START TRANSACTION|BEGIN|COMMIT|ROLLBACK)\b/i', trim($text)) === 1) {
        return 'transaction control';
    }
    if (preg_match('/^DELETE FROM `?poller_output_boost_arch_/i', trim($text)) === 1) {
        return 'archive delete';
    }
    if (stripos($text, 'SELECT * FROM ( SELECT `poller_output_boost_arch_') !== false) {
        return 'archive select';
    }
    if (preg_match('/^(RENAME TABLE `?poller_output_boost`?|ANALYZE TABLE `?poller_output_boost_arch_|CREATE TABLE `?poller_output_boost_\d)/i', trim($text)) === 1) {
        return 'rotation';
    }
    if (stripos($text, '`data_template_rrd`') !== false || stripos($text, 'FROM `data_local` WHERE ID') !== false) {
        return 'template lookup';
    }

    return 'other';
}

$code = read_env($dir . '/code.env');
$environment = read_env($dir . '/environment.env');
$cells = [];
foreach (glob($dir . '/cells/*', GLOB_ONLYDIR) ?: [] as $cellDir) {
    $name = basename($cellDir);
    $params = read_env($cellDir . '/params.env');
    if (!isset($params['wall_seconds'])) {
        continue;
    }
    $settings = read_settings($cellDir . '/settings.tsv');
    $start = (float) $params['start'];
    $end = (float) $params['end'];
    $wall = (float) $params['wall_seconds'];
    $stats = $settings['stats_boost'] ?? '';
    $updates = preg_match('/RRDUpdates:(\d+)/', $stats, $m) === 1 ? (int) $m[1] : null;

    $leftoverRows = 0;
    $archives = 0;
    foreach (file($cellDir . '/leftover-rows.tsv', FILE_IGNORE_NEW_LINES) ?: [] as $i => $line) {
        $fields = explode("\t", $line);
        if ($i === 0 || count($fields) < 2) {
            continue;
        }
        $leftoverRows += (int) $fields[1];
        if (str_starts_with($fields[0], 'poller_output_boost_arch_')) {
            $archives++;
        }
    }
    $countLines = static function (string $file): int {
        return is_file($file) ? count(array_filter(file($file, FILE_IGNORE_NEW_LINES), static fn($l) => trim($l) !== '')) : 0;
    };

    $db = status_deltas($dir . '/db/' . $name . '/status.csv');
    $t = $db['totals'];
    $seconds = $t['seconds'] ?? 0.0;
    $deltaFile = $dir . '/db/' . $name . '/status-deltas.csv';
    if ($db['deltas'] !== []) {
        $out = fopen($deltaFile, 'w');
        fputcsv($out, array_keys($db['deltas'][0]), ',', '"', '');
        foreach ($db['deltas'] as $row) {
            fputcsv($out, $row, ',', '"', '');
        }
        fclose($out);
    }

    $messengerPeak = 0;
    $archivePeak = 0;
    $archiveRows = [];
    foreach (read_table($dir . '/db/' . $name . '/tables.csv', ',') as $row) {
        if ($row['table'] === 'kadupul_messenger_messages' && $row['rows_exact'] !== '') {
            $messengerPeak = max($messengerPeak, (int) $row['rows_exact']);
        }
        if (str_starts_with($row['table'], 'poller_output_boost_arch_')) {
            $archiveRows[$row['epoch']] = ($archiveRows[$row['epoch']] ?? 0) + (int) $row['data_bytes'] + (int) $row['index_bytes'];
        }
    }

    $digests = read_table($dir . '/db/' . $name . '/digests.tsv', "\t");
    $byClass = [];
    $appTotal = 0.0;
    $top = [];
    foreach ($digests as $row) {
        // The recorder runs without a default schema; Boost and Messenger use the
        // installation's schema.
        if (($row['SCHEMA_NAME'] ?? '') === 'NULL' || ($row['SCHEMA_NAME'] ?? '') === '') {
            continue;
        }
        $latency = (float) $row['SUM_TIMER_WAIT'] / 1e12;
        $appTotal += $latency;
        $class = digest_class($row['DIGEST_TEXT']);
        $byClass[$class] = ($byClass[$class] ?? 0.0) + $latency;
        if (count($top) < 20) {
            $top[] = $row;
        }
    }
    if ($top !== []) {
        $out = fopen($dir . '/db/' . $name . '/digests-top20.tsv', 'w');
        fwrite($out, "calls\ttotal_s\tmax_s\tlock_s\trows_examined\trows_affected\tclass\tdigest_text\n");
        foreach ($top as $row) {
            fwrite($out, sprintf(
                "%d\t%.3f\t%.3f\t%.3f\t%d\t%d\t%s\t%s\n",
                $row['COUNT_STAR'],
                (float) $row['SUM_TIMER_WAIT'] / 1e12,
                (float) $row['MAX_TIMER_WAIT'] / 1e12,
                (float) $row['SUM_LOCK_TIME'] / 1e12,
                $row['SUM_ROWS_EXAMINED'],
                $row['SUM_ROWS_AFFECTED'],
                digest_class($row['DIGEST_TEXT']),
                substr($row['DIGEST_TEXT'], 0, 300),
            ));
        }
        fclose($out);
    }

    // Doctrine runs the Messenger transport through native prepared
    // statements, which never reach the digest table; count them by event name.
    $recorder = 0.0;
    foreach ($digests as $row) {
        if (($row['SCHEMA_NAME'] ?? '') === 'NULL' || ($row['SCHEMA_NAME'] ?? '') === '') {
            $recorder += (float) $row['SUM_TIMER_WAIT'] / 1e12;
        }
    }
    $eventTotal = 0.0;
    $prepared = 0.0;
    foreach (read_table($dir . '/db/' . $name . '/statement-events.tsv', "\t") as $row) {
        $latency = (float) $row['SUM_TIMER_WAIT'] / 1e12;
        $eventTotal += $latency;
        if (in_array($row['EVENT_NAME'], ['statement/com/Prepare', 'statement/com/Execute', 'statement/com/Close stmt'], true)) {
            $prepared += $latency;
        }
    }
    if ($eventTotal > 0) {
        $byClass['doctrine prepared'] = $prepared;
        $appTotal = $eventTotal - $recorder;
    }

    $errors = [];
    foreach (is_file($cellDir . '/cacti.log') ? file($cellDir . '/cacti.log', FILE_IGNORE_NEW_LINES) : [] as $line) {
        if (preg_match('/ - (?:BOOST |CMDPHP |DBCALL |SYSTEM |POLLER )?(ERROR|WARNING)[: ](.*)$/', $line, $m) === 1) {
            $message = preg_replace('/\d+/', 'N', substr($m[2], 0, 140));
            $errors[$m[1] . ': ' . $message] = ($errors[$m[1] . ': ' . $message] ?? 0) + 1;
        }
    }

    $cells[$name] = [
        'params' => $params,
        'wall' => $wall,
        'updates' => $updates,
        'rows_per_s' => $updates !== null && $wall > 0 ? $updates / $wall : null,
        'files_per_s' => $wall > 0 ? (int) $params['queued_sources'] / $wall : null,
        'rss' => peak_rss($cellDir . '/procs.csv'),
        'cpu' => cpu_stats($cellDir . '/vmstat.log', $start, $end),
        'db_cpu' => cpu_stats($dir . '/db/' . $name . '/vmstat-db.log', $start, $end),
        'qps_avg' => $seconds > 0 ? ($t['Questions'] ?? 0) / $seconds : null,
        'qps_peak' => $db['peaks']['Questions_per_s'] ?? null,
        'row_lock_waits' => $t['Innodb_row_lock_waits'] ?? null,
        'row_lock_ms' => $t['Innodb_row_lock_time'] ?? null,
        'deadlocks' => $t['Innodb_deadlocks'] ?? null,
        'slow' => $t['Slow_queries'] ?? null,
        'tmp_disk' => $t['Created_tmp_disk_tables'] ?? null,
        'rnd_next' => $t['Handler_read_rnd_next'] ?? null,
        'bp_hit' => isset($t['Innodb_buffer_pool_read_requests']) && $t['Innodb_buffer_pool_read_requests'] > 0
            ? 1 - ($t['Innodb_buffer_pool_reads'] ?? 0) / $t['Innodb_buffer_pool_read_requests'] : null,
        'threads_running_peak' => $db['peaks']['Threads_running'] ?? null,
        'fsyncs' => $t['Innodb_data_fsyncs'] ?? null,
        'redo_mib' => isset($t['Innodb_os_log_written']) ? $t['Innodb_os_log_written'] / 1048576 : null,
        'messenger_peak' => $messengerPeak,
        'archive_peak_bytes' => $archiveRows === [] ? null : max($archiveRows),
        'status' => $settings['boost_poller_status'] ?? '',
        'active_run' => $settings['boost_active_run'] ?? '',
        'leftover_rows' => $leftoverRows,
        'archives' => $archives,
        'messages_left' => max(0, $countLines($cellDir . '/messenger-left.tsv') - 1),
        'processes_rows' => max(0, $countLines($cellDir . '/processes-table.tsv') - 1),
        'leftover_procs' => $countLines($cellDir . '/leftover-procs.txt'),
        'consumer_restarts' => $countLines($cellDir . '/consumer-restarts.log'),
        'rrd_not_final' => isset($params['rrd_not_final']) ? $params['rrd_not_final'] . ' of ' . $params['rrd_files'] : 'n/a',
        'deadlock_captures' => count(glob($dir . '/db/' . $name . '/innodb-status-deadlock-*') ?: []),
        'by_class' => $byClass,
        'app_db_seconds' => $appTotal,
        'errors' => $errors,
    ];
}
uksort($cells, static function (string $a, string $b): int {
    return [(int) $a, $a] <=> [(int) $b, $b];
});

$csv = fopen($dir . '/cells.csv', 'w');
$columns = ['cell', 'scale', 'tree', 'mode', 'boost_parallel', 'consumers', 'fault', 'master_rc', 'wall_s', 'rrd_updates', 'rows_per_s', 'rrd_files_per_s',
    'rss_master_mib', 'rss_child_mib', 'rss_partition_mib', 'rss_consumer_mib', 'rss_rrdtool_mib', 'app_busy_avg_pct', 'app_busy_max_pct', 'app_iowait_avg_pct',
    'app_iowait_max_pct', 'db_busy_avg_pct', 'db_iowait_avg_pct', 'db_qps_avg', 'db_qps_peak', 'row_lock_waits', 'row_lock_ms', 'deadlocks', 'slow_queries',
    'tmp_disk_tables', 'handler_read_rnd_next', 'buffer_pool_hit', 'threads_running_peak', 'data_fsyncs', 'redo_mib', 'messenger_rows_peak',
    'final_status', 'active_run_cleared', 'leftover_rows', 'archive_tables_left', 'messages_left', 'processes_rows', 'leftover_procs', 'consumer_restarts',
    'db_time_s', 'messenger_db_s', 'run_status_db_s'];
fputcsv($csv, $columns, ',', '"', '');
foreach ($cells as $name => $c) {
    $p = $c['params'];
    fputcsv($csv, [$name, $p['scale'], $p['tree'], $p['mode'], $p['parallel'], $p['consumers'], $p['fault'], $p['master_rc'], $c['wall'], $c['updates'],
        $c['rows_per_s'], $c['files_per_s'], $c['rss']['master'] ?? '', $c['rss']['child'] ?? '', $c['rss']['partition'] ?? '', $c['rss']['consumer'] ?? '',
        $c['rss']['rrdtool'] ?? '', $c['cpu']['busy_avg'], $c['cpu']['busy_max'], $c['cpu']['wa_avg'], $c['cpu']['wa_max'], $c['db_cpu']['busy_avg'],
        $c['db_cpu']['wa_avg'], $c['qps_avg'], $c['qps_peak'], $c['row_lock_waits'], $c['row_lock_ms'], $c['deadlocks'], $c['slow'], $c['tmp_disk'],
        $c['rnd_next'], $c['bp_hit'], $c['threads_running_peak'], $c['fsyncs'], $c['redo_mib'], $c['messenger_peak'], $c['status'],
        $c['active_run'] === '' ? 'yes' : 'no', $c['leftover_rows'], $c['archives'], $c['messages_left'], $c['processes_rows'], $c['leftover_procs'],
        $c['consumer_restarts'], $c['app_db_seconds'], ($c['by_class']['messenger transport'] ?? 0) + ($c['by_class']['doctrine prepared'] ?? 0), $c['by_class']['run-scoped status'] ?? 0], ',', '"', '');
}
fclose($csv);

$equivalence = [];
foreach (glob($dir . '/equivalence/*.txt') ?: [] as $file) {
    $equivalence[basename($file, '.txt')] = trim((string) strtok((string) file_get_contents($file), "\n"));
}

$md = '# Boost load test ' . basename($dir) . "\n\n";
$md .= sprintf(
    "Code: feat `%s`, baseline `%s`, harness %s. Hosts: app %s vCPU / %s, database %s vCPU / %s, kernel %s, PHP %s, RRDtool %s, MariaDB %s. The database configuration is in `my.cnf`.\n\n",
    substr($code['feat'] ?? '?', 0, 10),
    substr($code['main'] ?? '?', 0, 10),
    $code['harness'] ?? '?',
    $environment['app_cpus'] ?? '?',
    $environment['app_memory'] ?? '?',
    $environment['db_cpus'] ?? '?',
    $environment['db_memory'] ?? '?',
    $environment['kernel'] ?? '?',
    $environment['php'] ?? '?',
    $environment['rrdtool'] ?? '?',
    $environment['mariadb'] ?? '?',
);
$md .= "Wall time is the master `poller_boost.php --force` from start to exit, and includes the fixed 7 s the master sleeps after rotating the table. "
    . "Rows/s is Boost's own RRDUpdates count (queued rows) over wall time; files/s is RRD files updated over wall time. "
    . "Peak RSS is the largest VmHWM seen for any process of the class, sampled every 0.5 s. CPU and iowait are vmstat averages (and peaks) over the master's run. "
    . "Database figures are `SHOW GLOBAL STATUS` deltas over 5 s samples.\n\n";

$md .= "## Throughput and resources\n\n";
$md .= md_row(['Cell', 'Mode', 'Parallel / consumers', 'Wall s', 'Rows/s', 'Files/s', 'Peak RSS MiB master / child / partition / consumer', 'App CPU avg (max) %', 'App iowait avg (max) %', 'DB CPU avg %', 'DB QPS avg (peak)', 'Row lock waits', 'Deadlocks', 'Slow queries', 'Status']);
$md .= md_row(array_fill(0, 15, '---'));
foreach ($cells as $name => $c) {
    $p = $c['params'];
    if ($p['fault'] !== 'none' || !in_array($p['restore'], ['1', '2'], true)) {
        continue;
    }
    $md .= md_row([
        $name, $p['mode'] . ($p['tree'] === 'main' ? ' (main)' : ''), $p['parallel'] . ' / ' . $p['consumers'], fmt($c['wall'], 1), fmt($c['rows_per_s']), fmt($c['files_per_s']),
        implode(' / ', array_map(static fn($k) => isset($c['rss'][$k]) ? fmt($c['rss'][$k]) : '-', ['master', 'child', 'partition', 'consumer'])),
        fmt($c['cpu']['busy_avg']) . ' (' . fmt($c['cpu']['busy_max']) . ')', fmt($c['cpu']['wa_avg'], 1) . ' (' . fmt($c['cpu']['wa_max']) . ')',
        fmt($c['db_cpu']['busy_avg']), fmt($c['qps_avg']) . ' (' . fmt($c['qps_peak']) . ')', fmt($c['row_lock_waits']), fmt($c['deadlocks']), fmt($c['slow']),
        preg_replace('/ - end time:.*/', '', $c['status']),
    ]);
}

$md .= "\n## End state of every cell\n\nEvery cell must end with no rows in `poller_output_boost` or the archive tables, no Boost process alive once its partitions finish, no `processes` row, and `boost_active_run` empty. "
    . "RRDs short of the last step counts the RRD files whose last update is not t0 + 1800 s, the final queued sample; after a cell that should have written everything it must be 0.\n\n";
$md .= md_row(['Cell', 'Master exit', 'Final status', 'Leftover rows', 'Archive tables', 'Queue rows left', 'Peak queue rows', 'processes rows', 'Leftover procs', 'boost_active_run cleared', 'Consumer restarts', 'RRDs short of the last step']);
$md .= md_row(array_fill(0, 12, '---'));
foreach ($cells as $name => $c) {
    $md .= md_row([$name, $c['params']['master_rc'], $c['status'], $c['leftover_rows'], $c['archives'], $c['messages_left'], $c['messenger_peak'], $c['processes_rows'],
        $c['leftover_procs'], $c['active_run'] === '' ? 'yes' : 'no', $c['consumer_restarts'], $c['rrd_not_final']]);
}

$cycles = array_filter($cells, static fn(array $c): bool => ($c['params']['restore'] ?? '') === '2');
if ($cycles !== []) {
    $md .= "\n## One Boost cycle at scale\n\nEach cell is one `boost_rrd_update_interval` (60 minutes, 12 samples per source) of queued samples for every source, flushed by one `poller_boost.php --force`. "
        . "Cycle time is the master's wall time; within interval means it is shorter than the 3,600 s before the next cycle's samples are due. "
        . "The statement shares are of database statement time; rotation is the RENAME, CREATE and ANALYZE of the archive.\n\n";
    $md .= md_row(['Cell', 'Mode', 'Parallel / consumers', 'Rows', 'Queueing s', 'Cycle s', 'Within interval', 'Rows/s', 'Files/s', 'App CPU avg (max) %', 'App iowait avg (max) %', 'DB CPU avg %', 'DB QPS avg', 'Row lock waits', 'Archive SELECT', 'Archive DELETE', 'Template lookups', 'Rotation s', 'Queue path', 'Peak archive GiB']);
    $md .= md_row(array_fill(0, 20, '---'));
    foreach ($cycles as $name => $c) {
        $p = $c['params'];
        $total = $c['app_db_seconds'] > 0 ? $c['app_db_seconds'] : null;
        $share = static fn(float $v): string => $total === null ? 'n/a' : fmt(100 * $v / $total, 1) . ' %';
        $queue = ($c['by_class']['doctrine prepared'] ?? 0) + ($c['by_class']['messenger transport'] ?? 0) + ($c['by_class']['transaction control'] ?? 0) + ($c['by_class']['run-scoped status'] ?? 0);
        $md .= md_row([
            $name, $p['mode'], $p['parallel'] . ' / ' . $p['consumers'], fmt((float) $p['queued_rows']), $p['generate_seconds'] ?? 'n/a', fmt($c['wall'], 0),
            $c['wall'] < 3600 ? 'yes (' . fmt(100 * $c['wall'] / 3600) . ' %)' : 'no (' . fmt(100 * $c['wall'] / 3600) . ' %)',
            fmt($c['rows_per_s']), fmt($c['files_per_s']),
            fmt($c['cpu']['busy_avg']) . ' (' . fmt($c['cpu']['busy_max']) . ')', fmt($c['cpu']['wa_avg'], 1) . ' (' . fmt($c['cpu']['wa_max']) . ')',
            fmt($c['db_cpu']['busy_avg']), fmt($c['qps_avg']), fmt($c['row_lock_waits']),
            $share($c['by_class']['archive select'] ?? 0), $share($c['by_class']['archive delete'] ?? 0), $share($c['by_class']['template lookup'] ?? 0),
            fmt($c['by_class']['rotation'] ?? 0, 1), $share($queue),
            $c['archive_peak_bytes'] === null ? 'n/a' : fmt($c['archive_peak_bytes'] / 1073741824, 1),
        ]);
    }
}

$md .= "\n## Equivalence\n\nEach comparison hashes `rrdtool dump` of the same RRD files after both cells: 500 spread across the scale, plus every multi-field and near-wrap 64-bit source.\n\n";
$md .= md_row(['Comparison', 'Result']);
$md .= md_row(['---', '---']);
foreach ($equivalence as $pair => $result) {
    $md .= md_row([$pair, $result]);
}

$md .= "\n## Database time by statement class\n\nStatement latency over each cell (performance_schema, reset before the cell, the recorder's own statements excluded). "
    . "Doctrine runs the Messenger transport through native prepared statements, which MariaDB counts only by event name (`statement/com/Prepare`, `Execute`, `Close stmt`), so that column comes from "
    . "`events_statements_summary_global_by_event_name`. Legacy Cacti code uses emulated prepares and never produces those events. Transaction control is `START TRANSACTION` and `COMMIT`, "
    . "which only the Doctrine transport issues during a Boost run. The run-scoped status is the master's `COUNT(*) ... WHERE run_id = ?` poll.\n\n";
$md .= md_row(['Cell', 'DB time s', 'Doctrine prepared s', 'Transaction control s', 'Run-scoped status s', 'Queue path share', 'Buffer pool hit', 'Tmp disk tables', 'Peak threads running']);
$md .= md_row(array_fill(0, 9, '---'));
foreach ($cells as $name => $c) {
    if ($c['params']['fault'] !== 'none' || !in_array($c['params']['restore'], ['1', '2'], true)) {
        continue;
    }
    $prepared = ($c['by_class']['doctrine prepared'] ?? 0) + ($c['by_class']['messenger transport'] ?? 0);
    $txn = $c['by_class']['transaction control'] ?? 0;
    $status = $c['by_class']['run-scoped status'] ?? 0;
    $queue = $prepared + $txn + $status;
    $md .= md_row([$name, fmt($c['app_db_seconds'], 2), fmt($prepared, 3), fmt($txn, 3), fmt($status, 3),
        $c['app_db_seconds'] > 0 ? fmt(100 * $queue / $c['app_db_seconds'], 2) . ' %' : 'n/a', $c['bp_hit'] === null ? 'n/a' : fmt(100 * $c['bp_hit'], 3) . ' %',
        fmt($c['tmp_disk']), fmt($c['threads_running_peak'])]);
}

$largest = '';
foreach ($cells as $name => $c) {
    if ($c['params']['mode'] === 'queue' && $c['params']['fault'] === 'none' && in_array($c['params']['restore'], ['1', '2'], true)) {
        $largest = $name;
    }
}
if ($largest !== '' && is_file($dir . '/db/' . $largest . '/digests-top20.tsv')) {
    $md .= "\nTop statements by total latency in cell $largest (full list in `db/$largest/digests-top20.tsv`; slow log report in `db/$largest/pt-query-digest.txt`):\n\n";
    $md .= md_row(['Calls', 'Total s', 'Max s', 'Class', 'Statement']);
    $md .= md_row(['---', '---', '---', '---', '---']);
    foreach (array_slice(read_table($dir . '/db/' . $largest . '/digests-top20.tsv', "\t"), 0, 10) as $row) {
        $md .= md_row([$row['calls'], $row['total_s'], $row['max_s'], $row['class'], '`' . substr($row['digest_text'], 0, 120) . '`']);
    }
}

$md .= "\n## Log messages\n\nWARNING and ERROR lines in cacti.log, numbers replaced by N.\n\n";
foreach ($cells as $name => $c) {
    if ($c['errors'] === []) {
        continue;
    }
    $md .= "**$name**\n\n";
    foreach ($c['errors'] as $message => $count) {
        $md .= '- ' . $count . ' x `' . $message . "`\n";
    }
    $md .= "\n";
}

file_put_contents($dir . '/summary.md', $md);
printf("collect: %d cells -> %s/summary.md\n", count($cells), $dir);
