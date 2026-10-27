<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

test('production Boost handoff quotes collected values on the selected connection', function ($connection) {
    $root = dirname(__DIR__, 4);
    $directory = sys_get_temp_dir() . '/boost-escape-' . bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    $coverage = $this->getTestResultObject()->getCodeCoverage();
    try {
        // db_qstr() and db_execute() are the database boundary; the tuple builder is production code.
        $process = proc_open(
            array(PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'pcov.directory=' . $root,
                '-d', 'pcov.exclude=~/(include/vendor|tests)/~', $root . '/tests/Fixtures/boost-handoff-native.php',
                $root, $directory, 'quoted-success', $connection, $coverage !== null ? '1' : '0'),
            array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
            $pipes
        );
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        expect(proc_close($process))->toBe(0, $error)->and($error)->toBe('');
        $result = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        $selected = $connection === 'online' ? 'primary-database' : false;
        expect($result['result'])->toBeFalse()->and($result['samples_unchanged'])->toBeTrue()
            ->and($result['writes'])->toHaveCount(1)
            ->and($result['writes'][0]['connection'])->toBe($selected)
            ->and($result['quoted'])->toBe(array(
                array('value' => "it's", 'connection' => $selected),
                array('value' => '2026-01-01 00:00:00', 'connection' => $selected),
                array('value' => "1'2\\3", 'connection' => $selected),
                array('value' => 'value', 'connection' => $selected),
                array('value' => '2026-01-01 00:01:00', 'connection' => $selected),
                array('value' => "a\"b\nc", 'connection' => $selected),
            ))
            ->and($result['writes'][0]['sql'])->toBe('INSERT INTO poller_output_boost (local_data_id, rrd_name, time, output) VALUES '
                . "(7,'it\\'s','2026-01-01 00:00:00','1\\'2\\\\3'),"
                . "(8,'value','2026-01-01 00:01:00','a\\\"b\\nc')"
                . ' ON DUPLICATE KEY UPDATE output=VALUES(output)');
        if ($coverage !== null) {
            $reports = glob($directory . '/*.coverage');
            expect($reports)->toHaveCount(1);
            $coverage->merge(unserialize(file_get_contents($reports[0])));
        }
    } finally {
        foreach (glob($directory . '/*') as $file) {
            unlink($file);
        }
        rmdir($directory);
    }
})->with(array('local', 'online', 'offline'));
