<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/*
 * Builds the data sources for one load-test scale on a fresh install.
 *
 * remote/seed.sh first creates one base data source per template through the
 * product CLI (install_cacti.php, add_device.php, add_graphs.php). Creating
 * 100,000 that way would take hours, so this script copies the rows those
 * commands wrote (data_local, data_template_data, data_template_rrd,
 * data_input_data, graph_local, graph_templates_graph, graph_templates_item,
 * poller_item) with server-side INSERT ... SELECT, changing only identities,
 * paths and, for the DERIVE and ABSOLUTE kinds, data_source_type_id. The RRD
 * files are then made by the product's rrdtool_function_create().
 *
 * Usage, run as the poller account with KBL_APP set to the install:
 *   php seed.php clone --count=N --seed=S [--lean]
 *   php seed.php rrds --workers=W
 *   php seed.php sample-list --size=500 [--special=100] > sample.txt
 *
 * --lean skips data_input_data and the graph items that reference no data
 * source. Boost reads neither; at millions of sources they are most of the
 * rows. --special caps the multi-field and near-wrap sources added to the
 * comparison set (by default all of them are added).
 */

require __DIR__ . '/values.php';

$app = getenv('KBL_APP') ?: '/opt/kadupul/app';
chdir($app);
require $app . '/include/cli_check.php';
require_once $app . '/lib/rrd.php';

const KBL_FIRST_ID = 1000;
const KBL_RRD_SLOTS = 8;
const KBL_ITEM_SLOTS = 40;

$command = $argv[1] ?? '';
$options = [];
foreach (array_slice($argv, 2) as $argument) {
    if (preg_match('/^--([a-z-]+)=(.*)$/D', $argument, $match) === 1) {
        $options[$match[1]] = $match[2];
    } elseif (preg_match('/^--([a-z-]+)$/D', $argument, $match) === 1) {
        $options[$match[1]] = '';
    }
}

switch ($command) {
    case 'clone':
        kbl_clone((int) ($options['count'] ?? 0), (int) ($options['seed'] ?? 1), array_key_exists('lean', $options));
        break;
    case 'rrds':
        kbl_spawn_rrd_workers((int) ($options['workers'] ?? 8));
        break;
    case 'rrd-worker':
        kbl_rrd_worker((int) $options['worker'], (int) $options['workers']);
        break;
    case 'sample-list':
        kbl_sample_list((int) ($options['size'] ?? 500), isset($options['special']) ? (int) $options['special'] : null);
        break;
    default:
        fwrite(STDERR, "usage: seed.php clone|rrds|sample-list [options]\n");
        exit(2);
}

function kbl_fail(string $message): never
{
    fwrite(STDERR, 'seed.php: ' . $message . "\n");
    exit(1);
}

/** The base data sources created through the CLI, keyed as in kbl_kinds(). */
function kbl_bases(): array
{
    $names = [
        'gauge' => 'Unix - Processes',
        'users' => 'Unix - Logged in Users',
        'loadavg' => 'Unix - Load Average',
        'interface' => 'Interface - Traffic',
    ];
    $bases = [];
    foreach ($names as $key => $template) {
        $row = db_fetch_row_prepared(
            'SELECT dl.id AS ldid, MIN(gti.local_graph_id) AS lgid
            FROM data_local AS dl
            INNER JOIN data_template AS dt ON dt.id = dl.data_template_id
            INNER JOIN data_template_rrd AS dtr ON dtr.local_data_id = dl.id
            INNER JOIN graph_templates_item AS gti ON gti.task_item_id = dtr.id
            WHERE dt.name = ? AND dl.id < ?
            GROUP BY dl.id
            ORDER BY dl.id
            LIMIT 1',
            [$template, KBL_FIRST_ID],
        );
        if (!cacti_sizeof($row) || empty($row['lgid'])) {
            kbl_fail("no base data source with a graph for template '$template'; run remote/seed.sh");
        }
        $bases[$key] = ['ldid' => (int) $row['ldid'], 'lgid' => (int) $row['lgid']];
    }

    return $bases;
}

/** Column list of a table, for building INSERT ... SELECT with overrides. */
function kbl_columns(string $table): array
{
    return array_column(db_fetch_assoc_prepared(
        'SELECT COLUMN_NAME FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = SCHEMA() AND TABLE_NAME = ?
        ORDER BY ORDINAL_POSITION',
        [$table],
    ), 'COLUMN_NAME');
}

/**
 * INSERT INTO $table SELECT every column from alias b, except the
 * overridden ones, joined to the plan as $from.
 *
 * @param array<string, string> $overrides column => SQL expression
 */
function kbl_copy(string $table, string $from, array $overrides): void
{
    // Copy in ranges of the plan so no single statement holds millions of
    // rows of undo.
    $chunk = 250000;
    $count = (int) db_fetch_cell('SELECT MAX(n) FROM kbl_plan');
    $columns = kbl_columns($table);
    $select = [];
    foreach ($columns as $column) {
        $select[] = ($overrides[$column] ?? "b.`$column`") . " AS `$column`";
    }
    $sql = "INSERT INTO `$table` (`" . implode('`, `', $columns) . '`) SELECT ' . implode(', ', $select) . ' ' . $from;
    $start = microtime(true);
    $rows = 0;
    for ($low = 1; $low <= $count; $low += $chunk) {
        if (db_execute_prepared($sql . ' WHERE p.n BETWEEN ? AND ?', [$low, $low + $chunk - 1]) === false) {
            kbl_fail("copy into $table failed");
        }
        $rows += db_affected_rows();
    }
    printf("seed: %-22s %10d rows in %.1f s\n", $table, $rows, microtime(true) - $start);
}

function kbl_clone(int $count, int $seed, bool $lean): void
{
    global $config;

    if ($count < 1) {
        kbl_fail('--count must be positive');
    }
    if ((int) db_fetch_cell('SELECT COUNT(*) FROM data_local WHERE id >= ' . KBL_FIRST_ID) > 0) {
        kbl_fail('clones already exist; seed a fresh install');
    }

    $bases = kbl_bases();
    $kinds = kbl_kinds();

    foreach ($bases as $key => $base) {
        $slots = (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM data_template_rrd WHERE local_data_id = ?', [$base['ldid']]);
        $items = (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM graph_templates_item WHERE local_graph_id = ?', [$base['lgid']]);
        if ($slots > KBL_RRD_SLOTS || $items > KBL_ITEM_SLOTS) {
            kbl_fail("base $key has $slots data source items and $items graph items; raise the slot constants");
        }
    }
    foreach ($kinds as $name => $kind) {
        $have = array_column(db_fetch_assoc_prepared('SELECT data_source_name FROM data_template_rrd WHERE local_data_id = ?', [$bases[$kind['base']]['ldid']]), 'data_source_name');
        $missing = array_diff(array_values($kind['fields']), $have);
        if (cacti_sizeof($missing)) {
            kbl_fail("base for kind $name lacks data sources " . implode(', ', $missing));
        }
    }

    db_execute('DROP TABLE IF EXISTS kbl_plan');
    db_execute('CREATE TABLE kbl_plan (
        n int unsigned NOT NULL PRIMARY KEY,
        kind varchar(16) NOT NULL,
        ldid int unsigned NOT NULL,
        lgid int unsigned NOT NULL,
        base_ldid int unsigned NOT NULL,
        base_lgid int unsigned NOT NULL,
        type_id tinyint unsigned DEFAULT NULL,
        UNIQUE KEY ldid (ldid)
    ) ENGINE=InnoDB');
    db_execute('DROP TABLE IF EXISTS kbl_meta');
    db_execute('CREATE TABLE kbl_meta (name varchar(32) NOT NULL PRIMARY KEY, value varchar(64) NOT NULL) ENGINE=InnoDB');

    $values = [];
    for ($n = 1; $n <= $count; $n++) {
        $kind = kbl_kind($seed, $n);
        $base = $bases[$kinds[$kind]['base']];
        $type = $kinds[$kind]['type'] === null ? 'NULL' : (string) $kinds[$kind]['type'];
        $id = KBL_FIRST_ID + $n;
        $values[] = "($n, '$kind', $id, $id, {$base['ldid']}, {$base['lgid']}, $type)";
        if (count($values) === 2000 || $n === $count) {
            db_execute('INSERT INTO kbl_plan VALUES ' . implode(', ', $values));
            $values = [];
        }
    }

    // Step 0 is written into each RRD at creation; the queue holds steps 1-6.
    $t0 = intdiv(time(), KBL_STEP) * KBL_STEP - 2 * 3600;
    db_execute_prepared('INSERT INTO kbl_meta VALUES (?, ?), (?, ?), (?, ?)', ['count', $count, 'seed', $seed, 't0', $t0]);

    db_execute('DROP TABLE IF EXISTS kbl_rank');
    db_execute('CREATE TABLE kbl_rank (old_id int unsigned NOT NULL PRIMARY KEY, r int unsigned NOT NULL) ENGINE=InnoDB');
    db_execute('INSERT INTO kbl_rank SELECT id, ROW_NUMBER() OVER (PARTITION BY local_data_id ORDER BY id) - 1
        FROM data_template_rrd WHERE local_data_id IN (SELECT DISTINCT base_ldid FROM kbl_plan)');

    $rra = db_qstr($config['rra_path'] . '/');
    $rrd = "CONCAT('kbl_', p.kind, '_', p.ldid, '.rrd')";

    kbl_copy('data_local', 'FROM kbl_plan AS p INNER JOIN data_local AS b ON b.id = p.base_ldid', [
        'id' => 'p.ldid',
    ]);
    kbl_copy('data_template_data', 'FROM kbl_plan AS p INNER JOIN data_template_data AS b ON b.local_data_id = p.base_ldid', [
        'id' => 'p.ldid',
        'local_data_id' => 'p.ldid',
        'name_cache' => "CONCAT(b.name_cache, ' #', p.n)",
        'data_source_path' => "CONCAT('<path_rra>/', $rrd)",
    ]);
    if (!$lean) {
        kbl_copy('data_input_data', 'FROM kbl_plan AS p INNER JOIN data_template_data AS bd ON bd.local_data_id = p.base_ldid INNER JOIN data_input_data AS b ON b.data_template_data_id = bd.id', [
            'data_template_data_id' => 'p.ldid',
        ]);
    }
    kbl_copy('data_template_rrd', 'FROM kbl_plan AS p INNER JOIN data_template_rrd AS b ON b.local_data_id = p.base_ldid INNER JOIN kbl_rank AS k ON k.old_id = b.id', [
        'id' => '1000000 + p.n * ' . KBL_RRD_SLOTS . ' + k.r',
        'local_data_id' => 'p.ldid',
        'data_source_type_id' => 'COALESCE(p.type_id, b.data_source_type_id)',
    ]);
    kbl_copy('graph_local', 'FROM kbl_plan AS p INNER JOIN graph_local AS b ON b.id = p.base_lgid', [
        'id' => 'p.lgid',
    ]);
    kbl_copy('graph_templates_graph', 'FROM kbl_plan AS p INNER JOIN graph_templates_graph AS b ON b.local_graph_id = p.base_lgid', [
        'id' => 'p.lgid',
        'local_graph_id' => 'p.lgid',
        'title_cache' => "CONCAT(b.title_cache, ' #', p.n)",
    ]);
    kbl_copy('graph_templates_item', 'FROM kbl_plan AS p INNER JOIN (SELECT gti.*, ROW_NUMBER() OVER (PARTITION BY local_graph_id ORDER BY id) - 1 AS kbl_r FROM graph_templates_item AS gti WHERE local_graph_id IN (SELECT DISTINCT base_lgid FROM kbl_plan)) AS b ON b.local_graph_id = p.base_lgid ' . ($lean ? 'INNER' : 'LEFT') . ' JOIN kbl_rank AS k ON k.old_id = b.task_item_id', [
        'id' => '2000000 + p.n * ' . KBL_ITEM_SLOTS . ' + b.kbl_r',
        'local_graph_id' => 'p.lgid',
        'task_item_id' => 'IF(k.old_id IS NULL, b.task_item_id, 1000000 + p.n * ' . KBL_RRD_SLOTS . ' + k.r)',
    ]);
    kbl_copy('poller_item', 'FROM kbl_plan AS p INNER JOIN poller_item AS b ON b.local_data_id = p.base_ldid', [
        'local_data_id' => 'p.ldid',
        'rrd_path' => "CONCAT($rra, $rrd)",
    ]);

    foreach (['data_local', 'data_template_data', 'data_template_rrd', 'graph_local', 'graph_templates_graph', 'graph_templates_item', 'poller_item'] as $table) {
        db_execute("ANALYZE TABLE `$table`");
    }

    $kindsSeen = db_fetch_assoc('SELECT kind, COUNT(*) AS sources FROM kbl_plan GROUP BY kind ORDER BY kind');
    foreach ($kindsSeen as $row) {
        printf("seed: kind %-9s %8d data sources\n", $row['kind'], $row['sources']);
    }
}

function kbl_spawn_rrd_workers(int $workers): void
{
    $workers = max(1, $workers);
    $start = microtime(true);
    $processes = [];
    for ($k = 0; $k < $workers; $k++) {
        $process = proc_open([PHP_BINARY, __FILE__, 'rrd-worker', '--worker=' . $k, '--workers=' . $workers], [1 => STDOUT, 2 => STDERR], $pipes);
        if (!is_resource($process)) {
            kbl_fail('could not start an RRD worker');
        }
        $processes[] = $process;
    }
    $failed = 0;
    foreach ($processes as $process) {
        if (proc_close($process) !== 0) {
            $failed++;
        }
    }
    if ($failed > 0) {
        kbl_fail("$failed RRD workers failed");
    }
    printf("seed: created RRD files with %d workers in %.1f s\n", $workers, microtime(true) - $start);
}

/** Creates this worker's share of the RRD files and writes step 0 into each. */
function kbl_rrd_worker(int $worker, int $workers): void
{
    $seed = (int) db_fetch_cell("SELECT value FROM kbl_meta WHERE name = 'seed'");
    $t0 = (int) db_fetch_cell("SELECT value FROM kbl_meta WHERE name = 't0'");
    $kinds = kbl_kinds();
    $plan = db_fetch_assoc_prepared('SELECT n, kind, ldid FROM kbl_plan WHERE MOD(n, ?) = ? ORDER BY n', [$workers, $worker]);

    $pipe = rrd_init(true, false, true);
    if ($pipe === false) {
        kbl_fail('rrd_init failed');
    }
    foreach ($plan as $row) {
        $n = (int) $row['n'];
        $ldid = (int) $row['ldid'];
        if (rrdtool_function_create($ldid, false, $pipe) === false) {
            kbl_fail("rrdtool_function_create failed for local_data_id $ldid");
        }
        $path = get_data_source_path($ldid, true);
        $names = [];
        $values = [];
        foreach ($kinds[$row['kind']]['fields'] as $field => $dsName) {
            $names[] = $dsName;
            $values[] = kbl_value($seed, $n, $row['kind'], $field, 0);
        }
        $update = 'update ' . rrdtool_command_path($path) . ' --template ' . implode(':', $names) . ' ' . $t0 . ':' . implode(':', $values);
        if (rrdtool_execute($update, false, RRDTOOL_OUTPUT_BOOLEAN, $pipe, 'POLLER') !== true) {
            kbl_fail("step 0 update failed for $path");
        }
    }
    rrd_close($pipe);
    printf("seed: worker %d created %d RRD files\n", $worker, count($plan));
}

/**
 * The RRD files compared between runs: a deterministic spread of $size
 * sources plus every multi-field and every near-wrap 64-bit source, or the
 * first $special of each when $special is given. The same sources go into
 * kbl_sample, which generate.php reads in cycle mode.
 */
function kbl_sample_list(int $size, ?int $special): void
{
    $count = (int) db_fetch_cell("SELECT value FROM kbl_meta WHERE name = 'count'");
    $stride = max(1, intdiv($count, max(1, $size)));
    db_execute('DROP TABLE IF EXISTS kbl_sample');
    db_execute('CREATE TABLE kbl_sample (n int unsigned NOT NULL PRIMARY KEY) ENGINE=InnoDB');
    db_execute_prepared('INSERT INTO kbl_sample SELECT n FROM kbl_plan WHERE MOD(n, ?) = 0', [$stride]);
    foreach (['multi', 'wrap64'] as $kind) {
        $limit = $special === null ? '' : ' ORDER BY n LIMIT ' . max(0, $special);
        db_execute_prepared("INSERT IGNORE INTO kbl_sample SELECT n FROM kbl_plan WHERE kind = ?$limit", [$kind]);
    }
    $rows = db_fetch_assoc(
        'SELECT DISTINCT pi.rrd_path
        FROM kbl_sample AS s
        INNER JOIN kbl_plan AS p ON p.n = s.n
        INNER JOIN poller_item AS pi ON pi.local_data_id = p.ldid
        ORDER BY pi.rrd_path',
    );
    foreach ($rows as $row) {
        print $row['rrd_path'] . "\n";
    }
}
