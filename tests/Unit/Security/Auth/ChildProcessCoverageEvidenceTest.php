<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

require_once dirname(__DIR__, 3) . '/Helpers/ChildProcessCoverage.php';

test('child coverage refuses an empty report directory before importing evidence', function () {
    $directory = sys_get_temp_dir() . '/child-coverage-missing-' . bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    try {
        expect(fn() => child_coverage_collect($directory))->toThrow(RuntimeException::class);
    } finally {
        if (is_dir($directory)) {
            rmdir($directory);
        }
    }
});
