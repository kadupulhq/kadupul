<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

require_once dirname(__DIR__, 3) . '/Helpers/NativeChildCoverageEvidence.php';

test('native predicates preserve rendering redirects and resource replication', function () {
    $root = dirname(__DIR__, 4);
    $directory = realpath(sys_get_temp_dir()) . '/predicate-native-' . bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    try {
        $coverage = $this->getTestResultObject()->getCodeCoverage();
        $command = [PHP_BINARY, '-d', 'auto_prepend_file=', '-d', 'error_reporting=E_ALL & ~E_DEPRECATED', '-d', 'opcache.jit=0', '-d', 'opcache.jit_buffer_size=0', '-d', 'pcov.directory=/', '-d', 'sys_temp_dir=' . $directory, $root . '/tests/Fixtures/string-predicates-native.php', $directory];
        if ($coverage !== null) {
            $command[] = $directory;
        }
        $stderr = tmpfile();
        $this->assertIsResource($stderr);
        try {
            $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => $stderr], $pipes);
            $this->assertIsResource($process);
            fclose($pipes[0]);
            $output = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            $status = proc_close($process);
            rewind($stderr);
            $error = stream_get_contents($stderr);
        } finally {
            fclose($stderr);
        }
        $this->assertSame(0, $status, $error . $output);
        $this->assertSame('', $output . $error);
        $result = json_decode(file_get_contents($directory . '/result.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame([false, 'app.js', 'app.js', ''], $result['include_fallback']);
        $this->assertSame([true, 'app.js', 'app.js', ''], $result['include_resolver']);
        $this->assertStringContainsString("class='odd selectable tableRow' id='12'", $result['rows'][0]);
        $this->assertStringContainsString("class='even tableRow' id='row_12'", $result['rows'][1]);
        $this->assertStringContainsString("class='probe selectable' id='ROW_12'", $result['rows'][2]);
        $this->assertStringContainsString("class='probe'>", $result['rows'][3]);
        $this->assertStringContainsString("class='nowrap highlight'", $result['cells'][0]);
        $this->assertStringContainsString("style='color:red;width:10px;'", $result['cells'][1]);
        $this->assertSame(['0_string-predicates-native' => 'ORDER BY `description` DESC'], $result['sort_update']);
        $this->assertSame('ORDER BY `description` DESC', $result['sort_get']);
        $this->assertSame(['fallback.php', 'fallback.php', '/path', 'relative.php', 'fallback.php'], $result['redirects']);
        $this->assertStringContainsString('semi-color', $result['regex']);
        $this->assertStringContainsString('host.php?page=1', $result['pages'][0]);
        $this->assertStringContainsString('host.php?filter=x&amp;page=1', $result['pages'][1]);
        $this->assertSame(['`name`', 'name(10)', '`name`,value(10)'], $result['indexes']);
        $this->assertSame([true, false, false], $result['quoted']);
        $this->assertSame(['/base/file', '/base/file'], $result['paths']);
        $this->assertSame(0, (int) $result['core_config_cached']);
        $this->assertSame(['out/env.php' => [true, 0755], 'out/direct.php' => [true, 0755], 'lib/poller.php' => [true, 0644]], $result['replicated']);
        $this->assertSame(3, substr_count($result['lint_output'], 'No syntax errors detected'));
        if ($coverage !== null) {
            $reports = glob($directory . '/*.coverage');
            $this->assertCount(1, $reports);
            $sources = ['composer.lock', 'tests/composer.lock', 'tests/Fixtures/rrd-process-coverage.php', 'tests/Helpers/NativeChildCoverageEvidence.php', 'lib/rrd.php', 'src/Graphing/Infrastructure/Rrd/ProxyCipher.php', 'lib/dsdebug.php', 'lib/rrd_maintenance.php', 'lib/poller.php', 'lib/boost.php', 'lib/api_data_source.php', 'lib/rrdcheck.php', 'lib/dsstats.php', 'lib/functions.php', 'include/global_constants.php', 'lib/html_utility.php', 'lib/database.php', 'lib/path_helpers.php', 'lib/html.php', 'tests/Unit/Core/Helpers/StringPredicateNativeTest.php', 'src/Platform/Infrastructure/Legacy/LegacyIncludePathResolver.php'];
            $markers = ['native-result-persisted'];
            $hits = ['lib/functions.php', 'lib/html_utility.php', 'lib/database.php', 'lib/path_helpers.php', 'lib/poller.php', 'src/Platform/Infrastructure/Legacy/LegacyIncludePathResolver.php'];
            $child = NativeChildCoverageEvidence::load($reports[0], $root, 'tests/Fixtures/string-predicates-native.php', 'native-string-predicates', $sources, $markers, $hits);
            $this->assertSame(32, NativeChildCoverageEvidence::verifyRejections($reports[0], $root, 'tests/Fixtures/string-predicates-native.php', 'native-string-predicates', $sources, $markers, $hits, 'lib/boost.php'));
            $coverage->merge($child);
        }
    } finally {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $file) {
            $file->isLink() || !$file->isDir() ? unlink($file->getPathname()) : rmdir($file->getPathname());
        }
        rmdir($directory);
    }
});
