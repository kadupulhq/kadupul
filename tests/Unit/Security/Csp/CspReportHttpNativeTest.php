<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

test('the actual CSP endpoint enforces HTTP methods and report validation', function (string $method, string $body, int $expected, string $response) {
    $root = dirname(__DIR__, 4);
    $directory = realpath(sys_get_temp_dir()) . '/csp-http-' . bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    copy($root . '/lib/csp_report_endpoint.php', $directory . '/endpoint.php');
    $coverage = $this->getTestResultObject()->getCodeCoverage();
    $reservation = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    $this->assertIsResource($reservation, $error);
    $address = stream_socket_get_name($reservation, false);
    fclose($reservation);
    $environment = getenv();
    $environment['HELPER_UNION_HTTP_DIRECTORY'] = $directory;
    $environment['HELPER_UNION_HTTP_COVERAGE'] = $coverage === null ? '0' : '1';
    $command = [PHP_BINARY, '-d', 'pcov.directory=/', '-d', 'error_reporting=24575', '-d', 'sys_temp_dir=' . $directory, '-d', 'log_errors=1', '-d', 'error_log=' . $directory . '/endpoint.log', '-d', 'auto_prepend_file=' . $root . '/tests/Fixtures/csp-report-http-coverage.php', '-S', $address, '-t', $directory];
    $server = null;
    try {
        $server = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', $directory . '/server.log', 'a'], 2 => ['file', $directory . '/server.log', 'a']], $pipes, $root, $environment);
        $this->assertIsResource($server);
        fclose($pipes[0]);
        $ready = false;
        $deadline = microtime(true) + 5;
        do {
            $connection = @stream_socket_client('tcp://' . $address, $errno, $error, 0.05);
            if (is_resource($connection)) {
                fclose($connection);
                $ready = true;
                break;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        $this->assertTrue($ready, file_get_contents($directory . '/server.log'));
        $context = stream_context_create(['http' => ['method' => $method, 'header' => 'Content-Type: application/csp-report', 'content' => $body, 'ignore_errors' => true, 'timeout' => 5]]);
        $output = file_get_contents('http://' . $address . '/endpoint.php', false, $context);
        $this->assertSame($response, $output);
        $this->assertStringContainsString(' ' . $expected . ' ', $http_response_header[0]);
        if ($expected === 405) {
            $this->assertContains('Allow: POST', $http_response_header);
        }
        if ($expected === 204) {
            $this->assertStringContainsString('CSP violation: script-src blocked inline on https://example.test/', file_get_contents($directory . '/endpoint.log'));
        }
        if ($coverage !== null) {
            $deadline = microtime(true) + 5;
            do {
                $reports = glob($directory . '/*.coverage');
                if (count($reports) === 1) {
                    break;
                }
                usleep(10000);
            } while (microtime(true) < $deadline);
            $this->assertCount(1, $reports);
            $coverage->merge(unserialize(file_get_contents($reports[0])));
        }
    } finally {
        if (is_resource($server)) {
            proc_terminate($server);
            proc_close($server);
        }
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $file) {
            $file->isLink() || !$file->isDir() ? unlink($file->getPathname()) : rmdir($file->getPathname());
        }
        rmdir($directory);
    }
})->with([
    'GET requires POST' => ['GET', '', 405, ''],
    'invalid POST is rejected' => ['POST', 'not JSON', 400, 'Invalid JSON'],
    'valid POST is logged' => ['POST', '{"csp-report":{"violated-directive":"script-src","blocked-uri":"inline","document-uri":"https://example.test/"}}', 204, ''],
]);
