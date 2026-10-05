<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

final class DataSourceControllerNativeHarness
{
    public static function run(array $scenario, ?SebastianBergmann\CodeCoverage\CodeCoverage $coverage = null): array
    {
        $root = dirname(__DIR__, 2);
        $directory = sys_get_temp_dir() . '/data-source-controller-' . bin2hex(random_bytes(8));
        mkdir($directory . '/include', 0700, true);
        mkdir($directory . '/lib', 0700);
        $process = null;
        try {
            file_put_contents($directory . '/include/auth.php', '<?php require ' . var_export($root . '/tests/Fixtures/data-source-controller-native.php', true) . ';');
            foreach (['api_aggregate','api_automation','api_data_source','api_device','api_graph','api_tree','data_query','graphs','html_graph','html_form_template','html_tree','ping','poller','reports','rrd','snmp','sort','template','utility','variables'] as $name) file_put_contents($directory . '/lib/' . $name . '.php', '<?php');
            $page = $scenario['page'] ?? 'data_sources.php';
            if (!in_array($page, ['data_sources.php', 'graphs.php'], true)) throw new RuntimeException('Unsupported owned controller');
            file_put_contents($directory . '/router.php', '<?php try { require ' . var_export($root . '/' . $page, true) . '; } catch (DeviceGraphFixtureCompleted $done) {}');
            $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $error);
            if ($socket === false) throw new RuntimeException($error);
            $address = stream_socket_get_name($socket, false);
            fclose($socket);
            $environment = array_merge(getenv(), ['DEVICE_GRAPH_CALLER_ROOT' => $root,'DEVICE_GRAPH_CALLER_DIRECTORY' => $directory,'DEVICE_GRAPH_CALLER_SCENARIO' => json_encode($scenario, JSON_THROW_ON_ERROR),'DEVICE_GRAPH_CALLER_COVERAGE' => $coverage === null ? '0' : '1']);
            $process = proc_open([PHP_BINARY,'-d','auto_prepend_file=','-d','error_reporting=24575','-d','output_buffering=4096','-d','pcov.directory=' . $root,'-d','pcov.exclude=~/(include/vendor|tests)/~','-S',$address,'-t',$directory,$directory . '/router.php'], [0 => ['pipe','r'],1 => ['file',$directory . '/stdout.log','w'],2 => ['file',$directory . '/stderr.log','w']], $pipes, $directory, $environment);
            if (!is_resource($process)) throw new RuntimeException('Cannot start owned native HTTP fixture');
            fclose($pipes[0]);
            $ready = false;
            for ($attempt = 0;$attempt < 100;$attempt++) {
                $prior = null;
                $prior = set_error_handler(static function (int $severity, string $message, string $file, int $line) use (&$prior): bool {
                    if ($severity === E_WARNING && str_starts_with($message, 'fsockopen(): Unable to connect') && str_contains($message, '(Connection refused)')) return true;
                    return $prior === null ? false : (bool) $prior($severity, $message, $file, $line);
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
            if (!$ready) throw new RuntimeException(file_get_contents($directory . '/stderr.log'));
            $method = $scenario['method'] ?? 'POST';
            $fields = $scenario['fields'] ?? [];
            $url = 'http://' . $address . '/cacti/' . $page;
            if ($method === 'GET') $url .= '?' . http_build_query($fields);
            $context = stream_context_create(['http' => ['method' => $method,'header' => 'Content-Type: application/x-www-form-urlencoded','content' => $method === 'POST' ? http_build_query($fields) : '', 'follow_location' => 0,'ignore_errors' => true,'timeout' => 10]]);
            $html = file_get_contents($url, false, $context);
            if ($html === false || !is_file($directory . '/state.json')) throw new RuntimeException('Native request failed: ' . ($html ?: '') . json_encode($http_response_header) . file_get_contents($directory . '/stderr.log'));
            $state = json_decode(file_get_contents($directory . '/state.json'), true, 512, JSON_THROW_ON_ERROR);
            $state += ['html' => $html,'headers' => $http_response_header,'stderr' => file_get_contents($directory . '/stderr.log')];
            if ($coverage !== null) {
                $reports = glob($directory . '/*.coverage');
                if (count($reports) !== 1) throw new RuntimeException('Native caller coverage missing');
                $hits = ['lib/html_utility.php', $page];
                if (($fields['action'] ?? '') === 'actions' || in_array((int) (($fields['id'] ?? 0) ?: ($fields['host_id'] ?? 0)), [12, 13, 21, 22, 31, 32], true)) $hits[] = 'lib/auth.php';
                $child = NativeChildCoverageEvidence::load($reports[0], $root, 'tests/Fixtures/data-source-controller-native.php', json_encode($scenario, JSON_THROW_ON_ERROR), DataSourceControllerCoverageRegistration::SOURCES, DataSourceControllerCoverageRegistration::MARKERS, $hits);
                static $verified = false;
                if (!$verified) {
                    $state['integrity_controls'] = NativeChildCoverageEvidence::verifyRejections($reports[0], $root, 'tests/Fixtures/data-source-controller-native.php', json_encode($scenario, JSON_THROW_ON_ERROR), DataSourceControllerCoverageRegistration::SOURCES, DataSourceControllerCoverageRegistration::MARKERS, $hits, 'lib/rrd.php');
                    if ($state['integrity_controls'] !== count(DataSourceControllerCoverageRegistration::SOURCES) + 12) throw new RuntimeException('Incomplete coverage rejection controls');
                    $verified = true;
                }
                $coverage->merge($child);
            }
            return $state;
        } finally {
            if (is_resource($process)) {
                proc_terminate($process);
                proc_close($process);
            }
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            rmdir($directory);
        }
    }
}
