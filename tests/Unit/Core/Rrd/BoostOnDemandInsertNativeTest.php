<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/**
 * Runs lib/boost.php in a child process so its global functions do not leak
 * into the Pest process. $body receives the library already loaded and must
 * echo one JSON document.
 *
 * @return array<mixed>
 */
function boostOnDemandProbe(string $body, array $environment = array()): array
{
    $root = dirname(__DIR__, 4);
    $directory = sys_get_temp_dir() . '/boost-on-demand-' . bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    $script = '<?php require ' . var_export($root . '/lib/boost.php', true) . ';' . $body;
    file_put_contents($directory . '/probe.php', $script);
    try {
        $process = proc_open(
            array(PHP_BINARY, $directory . '/probe.php'),
            array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
            $pipes,
            null,
            $environment === array() ? null : array_merge(getenv(), $environment)
        );
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        expect(proc_close($process))->toBe(0)->and($error)->toBe('');

        return json_decode($output, true, 512, JSON_THROW_ON_ERROR);
    } finally {
        foreach (glob($directory . '/*') as $file) {
            unlink($file);
        }
        rmdir($directory);
    }
}

function boostOnDemandBatchPattern(): string
{
    return '/^INSERT INTO poller_output_boost \(local_data_id, rrd_name, time, output\) VALUES '
        . '(\(\?, \?, \?, \?\)(, )?)+ ON DUPLICATE KEY UPDATE output=VALUES\(output\)$/';
}

test('on-demand rows with quotes and backslashes are stored verbatim', function () {
    $dsn = getenv('KADUPUL_TEST_MYSQL_DSN');
    if (!$dsn) {
        $this->markTestSkipped('KADUPUL_TEST_MYSQL_DSN is required for database contracts');
    }

    $outputs = array("in:1'x out:2", 'a\\\\b', 'tail\\', '"; DROP', "multi\nline");
    $body = <<<'PROBE'
$config = array('poller_id' => 1, 'connection' => 'online');
$pdo = new PDO(getenv('KADUPUL_TEST_MYSQL_DSN'), getenv('KADUPUL_TEST_MYSQL_USER'), getenv('KADUPUL_TEST_MYSQL_PASSWORD'), array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
$pdo->exec('DROP TABLE IF EXISTS poller_output_boost');
$pdo->exec('CREATE TABLE poller_output_boost (local_data_id int unsigned NOT NULL DEFAULT 0, rrd_name varchar(19) NOT NULL DEFAULT "", time timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP, output varchar(512) NOT NULL, PRIMARY KEY (local_data_id, time, rrd_name)) ENGINE=InnoDB');
function cacti_sizeof($value) { return is_array($value) ? count($value) : 0; }
function read_config_option($key) { return $key === 'boost_redirect' ? '' : 'on'; }
function set_config_option($key, $value) {}
function db_fetch_row($sql) { return array('Variable_name' => 'max_allowed_packet', 'Value' => '16777216'); }
function db_execute($sql, ...$args) { return true; }
function db_execute_prepared($sql, $params, $log = true, $conn = false)
{
    $GLOBALS['pdo']->prepare($sql)->execute($params);
    return true;
}
$results = array();
foreach (json_decode(getenv('BOOST_PROBE_OUTPUTS'), true) as $index => $output) {
    $results[] = array('local_data_id' => 7, 'rrd_name' => 'ds' . $index, 'time' => '2026-01-01 00:00:00', 'output' => $output);
}
$return = boost_poller_on_demand($results);
$stored = $pdo->query('SELECT rrd_name, output FROM poller_output_boost ORDER BY rrd_name')->fetchAll(PDO::FETCH_KEY_PAIR);
$pdo->exec('DROP TABLE poller_output_boost');
echo json_encode(array($return, $stored));
PROBE;

    [$return, $stored] = boostOnDemandProbe($body, array('BOOST_PROBE_OUTPUTS' => json_encode($outputs)));

    expect($return)->toBeFalse()
        ->and(array_values($stored))->toBe($outputs)
        ->and($stored)->toHaveCount(count($outputs));
});

test('on-demand batches split before max_allowed_packet', function () {
    $body = <<<'PROBE'
$results = array();
for ($i = 1; $i <= 5; $i++) {
    // 1 + 2 + 19 + 38 = 60 bytes of bound values per row
    $results[] = array('local_data_id' => $i, 'rrd_name' => 'ds', 'time' => '2026-01-01 00:00:00', 'output' => str_repeat('x', 37) . $i);
}
echo json_encode(boost_on_demand_batches($results, 200));
PROBE;

    $batches = boostOnDemandProbe($body);

    expect(count($batches))->toBeGreaterThan(1);
    $ids = array();
    foreach ($batches as $batch) {
        expect(strlen($batch['sql']) + array_sum(array_map(static fn($value) => strlen((string) $value), $batch['params'])))
            ->toBeLessThanOrEqual(200)
            ->and($batch['sql'])->toMatch(boostOnDemandBatchPattern())
            ->and(count($batch['params']))->toBe(4 * substr_count($batch['sql'], '(?, ?, ?, ?)'));
        for ($i = 0; $i < count($batch['params']); $i += 4) {
            $ids[] = $batch['params'][$i];
        }
    }
    expect($ids)->toBe(array(1, 2, 3, 4, 5));
});

test('a single oversized row is still written alone', function () {
    $body = <<<'PROBE'
$row = static fn ($id, $output) => array('local_data_id' => $id, 'rrd_name' => 'ds', 'time' => '2026-01-01 00:00:00', 'output' => $output);
echo json_encode(boost_on_demand_batches(array($row(1, 'a'), $row(2, str_repeat('z', 500)), $row(3, 'b')), 200));
PROBE;

    $batches = boostOnDemandProbe($body);

    expect($batches)->toHaveCount(3)
        ->and(array_column($batches, 'params'))->toBe(array(
            array(1, 'ds', '2026-01-01 00:00:00', 'a'),
            array(2, 'ds', '2026-01-01 00:00:00', str_repeat('z', 500)),
            array(3, 'ds', '2026-01-01 00:00:00', 'b'),
        ));
    foreach ($batches as $batch) {
        expect($batch['sql'])->toMatch(boostOnDemandBatchPattern());
    }
});
