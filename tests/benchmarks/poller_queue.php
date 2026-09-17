<?php
// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later
// Synthetic query diagnostics only: not end-to-end poller capacity evidence.
$rows = (int) (getenv('QUEUE_ROWS') ?: 1000000);
if ($rows < 80000 || $rows % 4 !== 0) {
	throw new RuntimeException('QUEUE_ROWS must be a multiple of four, at least 80000');
}
$engine = getenv('QUEUE_ENGINE') ?: 'InnoDB';
if (!in_array($engine, array('InnoDB', 'MEMORY'), true)) {
    throw new RuntimeException('QUEUE_ENGINE must be InnoDB or MEMORY');
}
$db = new PDO('mysql:host=' . (getenv('BOOST_DB_HOST') ?: '127.0.0.1') . ';port=' . (getenv('BOOST_DB_PORT') ?: '3306') . ';dbname=' . (getenv('BOOST_DB_NAME') ?: 'cacti_boost_contract'), getenv('BOOST_DB_USER') ?: 'root', getenv('BOOST_DB_PASSWORD') ?: '', array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
$db->exec("SET SESSION time_zone='+00:00', max_heap_table_size=2147483648");
// Temporary tables cannot replace another connection's application tables.
$db->exec('CREATE TEMPORARY TABLE poller_output (local_data_id INT UNSIGNED NOT NULL, rrd_name VARCHAR(19) NOT NULL, time TIMESTAMP NOT NULL, output VARCHAR(512) NOT NULL, PRIMARY KEY USING BTREE(local_data_id,rrd_name,time)) ENGINE=' . $engine . ' CHARSET=latin1 COLLATE=latin1_swedish_ci');
$db->exec('CREATE TEMPORARY TABLE poller_item (local_data_id INT UNSIGNED NOT NULL, rrd_name VARCHAR(19) NOT NULL, rrd_path VARCHAR(255), rrd_num INT, PRIMARY KEY(local_data_id,rrd_name)) ENGINE=InnoDB CHARSET=latin1 COLLATE=latin1_swedish_ci');
$db->exec('CREATE TEMPORARY TABLE data_local (id INT UNSIGNED PRIMARY KEY, data_template_id INT) ENGINE=InnoDB');
for ($first = 1; $first <= $rows / 4; $first += 1000) {
	$output = $items = $sources = array();
	for ($id = $first; $id < min($first + 1000, $rows / 4 + 1); $id++) {
		$sources[] = "($id,1)";
		foreach (array('in', 'out') as $name) {
			$items[] = "($id,'$name','/rra/$id.rrd',2)";
			foreach (array('00:00:00', '00:01:00') as $time) {
				$output[] = "($id,'$name','2026-01-01 $time','42')";
			}
		}
	}
	$db->exec('INSERT INTO poller_output VALUES ' . implode(',', $output));
	$db->exec('INSERT INTO poller_item VALUES ' . implode(',', $items));
	$db->exec('INSERT INTO data_local VALUES ' . implode(',', $sources));
}
$select = 'SELECT po.output, po.time, UNIX_TIMESTAMP(po.time) AS unix_time, po.local_data_id, dl.data_template_id, pi.rrd_path, pi.rrd_name, pi.rrd_num FROM poller_output po INNER JOIN poller_item pi ON po.local_data_id=pi.local_data_id AND po.rrd_name=pi.rrd_name INNER JOIN data_local dl ON dl.id=po.local_data_id';
$queries = array('first' => $select . ' ORDER BY po.local_data_id, po.time, po.rrd_name LIMIT 40000', 'after_retained_page' => $select . " WHERE (po.local_data_id,po.time) > (10000,'2026-01-01 00:01:00') ORDER BY po.local_data_id,po.time,po.rrd_name LIMIT 40000");
$report = array('kind' => 'synthetic query diagnostic', 'version' => $db->query('SELECT VERSION()')->fetchColumn(), 'rows' => $rows, 'sources' => $rows / 4, 'engine' => $engine, 'charset' => 'latin1', 'page_rows' => 40000, 'runs' => array());
// Keep both fixtures intact so changing variants never rebuilds a timed table.
$db->exec('CREATE TEMPORARY TABLE poller_output_secondary LIKE poller_output');
$db->exec('INSERT INTO poller_output_secondary SELECT * FROM poller_output');
$start = hrtime(true);
$db->exec('ALTER TABLE poller_output_secondary ADD INDEX drain_order USING BTREE(local_data_id,time,rrd_name)');
$report['index_build_seconds'] = (hrtime(true) - $start) / 1e9;
$report['method'] = 'independent queue tables; one warm-up per query and variant; six alternating paired warm rounds';
$report['cold_cache_measured'] = false;
$expected = array();
$variants = array('existing_primary' => 'poller_output', 'matching_secondary' => 'poller_output_secondary');
$measure = function ($index, $name, $query, $phase) use ($db, &$expected, &$report) {
	$start = hrtime(true);
	$data = $db->query($query)->fetchAll(PDO::FETCH_ASSOC);
	$seconds = (hrtime(true) - $start) / 1e9;
	$digest = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
	if (isset($expected[$name]) && $expected[$name] !== $digest) {
		throw new RuntimeException('Index or repetition changed selected samples');
	}
	$expected[$name] = $digest;
	if (count($data) !== 40000 || (int) $data[0]['local_data_id'] !== ($name === 'first' ? 1 : 10001)) {
		throw new RuntimeException('Unexpected page boundary or row count');
	}
	$report['runs'][$index][$name][$phase][] = $seconds;
	$report['runs'][$index][$name]['sha256'] = $digest;
};
foreach ($variants as $index => $table) {
	foreach ($queries as $name => $query) {
		$query = str_replace('FROM poller_output po', 'FROM ' . $table . ' po', $query);
		$measure($index, $name, $query, 'warmup_seconds');
		$report['runs'][$index][$name]['explain'] = $db->query('EXPLAIN ' . $query)->fetchAll(PDO::FETCH_ASSOC);
	}
}
for ($round = 0; $round < 6; $round++) {
	$order = $round % 2 === 0 ? $variants : array_reverse($variants, true);
	$report['round_order'][] = array_keys($order);
	foreach ($queries as $name => $query) {
		foreach ($order as $index => $table) {
			$measure($index, $name, str_replace('FROM poller_output po', 'FROM ' . $table . ' po', $query), 'warm_seconds');
		}
	}
}
echo json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
