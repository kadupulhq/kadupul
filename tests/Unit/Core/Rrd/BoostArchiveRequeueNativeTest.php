<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

test('production archive requeue drops an archive only after its samples return to the live queue', function () {
    $root = dirname(__DIR__, 4);
    $dir = sys_get_temp_dir() . '/boost-requeue-' . bin2hex(random_bytes(8));
    mkdir($dir, 0700);
    $coverage = $this->getTestResultObject()->getCodeCoverage();
    $bootstrap = '<?php ';
    if ($coverage !== null) {
        $bootstrap .= 'define("RRD_TEST_COVERAGE_DIRECTORY", __DIR__); require ' . var_export($root . '/tests/Fixtures/rrd-process-coverage.php', true) . ';';
    }
    $bootstrap .= 'require ' . var_export($root . '/lib/boost.php', true) . ';';
    $bootstrap .= <<<'SOURCE'
// The database is the boundary; each statement and its outcome is recorded.
function db_execute_prepared($sql, $params = array())
{
    $GLOBALS['statements'][] = strtok($sql, "\n");
    return strpos($sql, $GLOBALS['failing']) !== 0;
}
$results = array();
foreach (array('none' => "\0", 'insert' => 'INSERT', 'drop' => 'DROP') as $case => $failing) {
    $statements = array();
    $results[$case] = array(boost_requeue_archive('poller_output_boost_arch_1700000000'), $statements);
}
$failing = "\0";
$statements = array();
$results['foreign'] = array(boost_requeue_archive('poller_output_boost'), $statements);
echo json_encode($results);
SOURCE;
    file_put_contents($dir . '/probe.php', $bootstrap);
    try {
        $process = proc_open(array(PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'pcov.directory=' . $root, '-d', 'pcov.exclude=~/(include/vendor|tests)/~', $dir . '/probe.php'), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        expect(proc_close($process))->toBe(0, $error)->and($error)->toBe('');
        $results = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        $insert = 'INSERT IGNORE INTO poller_output_boost';
        $drop = 'DROP TABLE IF EXISTS `poller_output_boost_arch_1700000000`';
        expect($results['none'])->toBe(array(true, array($insert, $drop)))
            ->and($results['insert'])->toBe(array(false, array($insert)))
            ->and($results['drop'])->toBe(array(false, array($insert, $drop)))
            ->and($results['foreign'])->toBe(array(false, array()));
        if ($coverage !== null) {
            $reports = glob($dir . '/*.coverage');
            expect($reports)->toHaveCount(1);
            $coverage->merge(unserialize(file_get_contents($reports[0])));
        }
    } finally {
        foreach (glob($dir . '/*') as $file) {
            unlink($file);
        }
        rmdir($dir);
    }
});
