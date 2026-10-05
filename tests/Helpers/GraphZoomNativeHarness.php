<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

final class GraphZoomNativeHarness
{
    public static function run(array $scenario, $coverage = null): array
    {
        $root = dirname(__DIR__, 2);
        $directory = sys_get_temp_dir() . '/graph-zoom-native-' . bin2hex(random_bytes(8));
        mkdir($directory . '/include', 0700, true);
        mkdir($directory . '/lib', 0700);
        $process = null;
        try {
            copy($root . '/graph.php', $directory . '/graph.php');
            file_put_contents($directory . '/include/auth.php', '<?php require ' . var_export($root . '/tests/Fixtures/graph-zoom-native.php', true) . ';');
            foreach (array('rrd', 'graph_zoom', 'html_tree') as $name) {
                file_put_contents($directory . '/lib/' . $name . '.php', '<?php');
            }
            file_put_contents($directory . '/include/top_graph_header.php', '<?php echo "NATIVE_PAGE_HEADER" . str_repeat("x", 8192);');
            file_put_contents($directory . '/include/bottom_footer.php', '<?php echo "NATIVE_PAGE_FOOTER";');
            file_put_contents($directory . '/include/global_session.php', '<?php');
            $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $error);
            if ($socket === false) {
                throw new RuntimeException($error);
            }
            $address = stream_socket_get_name($socket, false);
            fclose($socket);
            $environment = array_merge(getenv(), array('GRAPH_ZOOM_NATIVE_ROOT' => $root, 'GRAPH_ZOOM_NATIVE_DIRECTORY' => $directory, 'GRAPH_ZOOM_NATIVE_SCENARIO' => json_encode($scenario, JSON_THROW_ON_ERROR), 'GRAPH_ZOOM_NATIVE_COVERAGE' => $coverage === null ? '0' : '1'));
            $command = array(PHP_BINARY, '-d', 'opcache.jit=0', '-d', 'opcache.jit_buffer_size=0', '-d', 'error_reporting=24575', '-d', 'auto_prepend_file=', '-d', 'output_buffering=0', '-d', 'pcov.directory=/', '-d', 'pcov.exclude=~/(include/vendor|tests)/~', '-d', 'session.save_path=' . $directory, '-S', $address, '-t', $directory);
            $process = proc_open($command, array(0 => array('pipe', 'r'), 1 => array('file', $directory . '/stdout.log', 'w'), 2 => array('file', $directory . '/stderr.log', 'w')), $pipes, $directory, $environment);
            if (!is_resource($process)) {
                throw new RuntimeException('Could not start the native PHP HTTP server');
            }
            fclose($pipes[0]);
            $ready = false;
            for ($attempt = 0; $attempt < 100; $attempt++) {
                $priorHandler = null;
                $priorHandler = set_error_handler(static function (int $severity, string $message, string $file, int $line) use (&$priorHandler): bool {
                    if ($severity === E_WARNING && str_starts_with($message, 'fsockopen(): Unable to connect') && str_contains($message, '(Connection refused)')) {
                        return true;
                    }
                    return $priorHandler === null ? false : (bool) $priorHandler($severity, $message, $file, $line);
                });
                try {
                    $probe = fsockopen('tcp://' . $address, -1, $errorCode, $error, 0.1);
                } finally {
                    restore_error_handler();
                }
                if (is_resource($probe)) {
                    fclose($probe);
                    $ready = true;
                    break;
                }
                usleep(20000);
            }
            if (!$ready) {
                throw new RuntimeException(file_get_contents($directory . '/stderr.log'));
            }
            $context = stream_context_create(array('http' => array('follow_location' => 0, 'ignore_errors' => true, 'timeout' => 5)));
            $before = time();
            $html = file_get_contents('http://' . $address . '/graph.php', false, $context);
            $after = time();
            $state = json_decode(file_get_contents($directory . '/state.json'), true, 512, JSON_THROW_ON_ERROR);
            if ($coverage !== null) {
                $reports = glob($directory . '/*.coverage');
                if (count($reports) !== 1) {
                    throw new RuntimeException('Native HTTP coverage report missing');
                }
                $coverage->merge(unserialize(file_get_contents($reports[0])));
            }
            return array_merge($state, array('html' => $html, 'headers' => $http_response_header, 'before' => $before, 'after' => $after, 'stderr' => file_get_contents($directory . '/stderr.log')));
        } finally {
            if (is_resource($process)) {
                proc_terminate($process);
                proc_close($process);
            }
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($directory);
        }
    }
}
