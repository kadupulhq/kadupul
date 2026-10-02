<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

test('the registered index migration adds a missing index and preserves an existing one', function (string $state) {
    $root = dirname(__DIR__, 3);
    $directory = sys_get_temp_dir() . '/input-index-upgrade-' . bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    $coverage = $this->getTestResultObject()->getCodeCoverage();
    try {
        $command = [PHP_BINARY, '-d', 'auto_prepend_file=', '-d', 'auto_append_file=', '-d', 'pcov.directory=' . $root, '-d', 'pcov.exclude=~/(include/vendor|tests)/~', $root . '/tests/Fixtures/data-input-index-upgrade-native.php', $state];
        if ($coverage !== null) {
            $command[] = $directory;
        }
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        expect(is_resource($process))->toBeTrue();
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        expect(proc_close($process))->toBe(0, $errors . $output)->and($errors)->toBe('');
        $expected = ["ALTER TABLE settings_user MODIFY user_id mediumint(8) unsigned NOT NULL default '0'"];
        if ($state === 'missing') {
            $expected[] = 'ALTER TABLE data_template_rrd ADD INDEX data_input_field_id (data_input_field_id)';
        }
        expect(json_decode($output, true, 16, JSON_THROW_ON_ERROR))->toBe($expected);
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
})->with(['missing', 'present']);
