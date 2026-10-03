<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

function test_native_input_string_is_safe(?string $input, ?object $coverage = null): bool
{
    $root = dirname(__DIR__, 2);
    $directory = sys_get_temp_dir() . '/input-validator-' . bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    try {
        $command = [PHP_BINARY, '-d', 'auto_prepend_file=', '-d', 'auto_append_file=', '-d', 'pcov.directory=' . $root, '-d', 'pcov.exclude=~/(include/vendor|tests)/~', $root . '/tests/Fixtures/input-string-validator.php'];
        if ($coverage !== null) {
            $command[] = $directory;
        }
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Native input validator could not start.');
        }
        fwrite($pipes[0], json_encode($input, JSON_THROW_ON_ERROR));
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0 || $errors !== '') {
            throw new RuntimeException('Native input validator failed: ' . $errors . $output);
        }
        if ($coverage !== null) {
            $reports = glob($directory . '/*.coverage');
            if (count($reports) !== 1) {
                throw new RuntimeException('Native validator coverage is missing.');
            }
            $coverage->merge(unserialize(file_get_contents($reports[0])));
        }
        return json_decode($output, true, 16, JSON_THROW_ON_ERROR);
    } finally {
        foreach (glob($directory . '/*') as $file) {
            unlink($file);
        }
        rmdir($directory);
    }
}
