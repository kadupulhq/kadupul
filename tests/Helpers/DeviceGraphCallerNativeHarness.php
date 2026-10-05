<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

final class DeviceGraphCallerNativeHarness
{
    public static function run(array $scenario, ?SebastianBergmann\CodeCoverage\CodeCoverage $coverage = null): array
    {
        $root = dirname(__DIR__, 2);
        $directory = sys_get_temp_dir() . '/device-graph-caller-' . bin2hex(random_bytes(8));
        mkdir($directory . '/include', 0700, true);
        mkdir($directory . '/lib', 0700);
        $process = null;
        try {
            file_put_contents($directory . '/include/auth.php', '<?php require ' . var_export($root . '/tests/Fixtures/device-graph-caller-native.php', true) . ';');
            foreach (['api_aggregate','api_automation','api_data_source','api_device','api_graph','api_tree','data_query','graphs','html_graph','html_form_template','html_tree','ping','poller','reports','rrd','snmp','sort','template','utility','variables'] as $name) file_put_contents($directory . '/lib/' . $name . '.php', '<?php');
            $page = $scenario['page'] ?? 'graphs_new.php';
            if (!in_array($page, ['graphs_new.php','host.php','graphs.php'], true)) throw new RuntimeException('Unsupported fixture controller');
            if (($scenario['api'] ?? false) && !($scenario['api_form'] ?? false)) file_put_contents($directory . '/router.php', '<?php require ' . var_export($root . '/tests/Fixtures/device-graph-caller-native.php', true) . ';');
            else if ($scenario['autosave_wrapper'] ?? false) file_put_contents($directory . '/router.php', '<?php require ' . var_export($root . '/graphs_new.php', true) . '; html_graph_new_graphs("graphs_new.php", ' . (int) $scenario['autosave_host'] . ', 0, ["cg"=>[5=>[]]]);');
            else file_put_contents($directory . '/router.php', '<?php try { require ' . var_export($root . '/' . $page, true) . '; } catch (DeviceGraphFixtureCompleted $done) {}');
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
                $hits = ['lib/html_utility.php',$page];
                // Missing/zero host IDs short-circuit in the controller before policy SQL;
                // positive IDs and bulk selections must physically execute the real policy.
                $action = $fields['action'] ?? '';
                $hostId = $fields[in_array($action, ['edit','save','ping_host'], true) ? 'id' : 'host_id'] ?? 0;
                if ($page !== 'host.php' || $action === 'actions' || $hostId > 0) $hits[] = 'lib/auth.php';
                if ($scenario['api'] ?? false) {
                    $apiId = $scenario['id'] ?? $fields['id'] ?? 12;
                    $hits = ['lib/api_device.php'];
                    if ($apiId > 0) $hits[] = 'lib/auth.php';
                    if ($scenario['api_form'] ?? false) $hits[] = 'host.php';
                    if ($apiId === 0 || $apiId === 12) $hits[] = 'src/Inventory/Infrastructure/Legacy/LegacyDeviceSiteWriter.php';
                }
                $child = NativeChildCoverageEvidence::load($reports[0], $root, 'tests/Fixtures/device-graph-caller-native.php', json_encode($scenario, JSON_THROW_ON_ERROR), DeviceGraphCallerCoverageRegistration::SOURCES, DeviceGraphCallerCoverageRegistration::MARKERS, $hits);
                static $verified = false;
                if (!$verified) {
                    $state['integrity_controls'] = NativeChildCoverageEvidence::verifyRejections($reports[0], $root, 'tests/Fixtures/device-graph-caller-native.php', json_encode($scenario, JSON_THROW_ON_ERROR), DeviceGraphCallerCoverageRegistration::SOURCES, DeviceGraphCallerCoverageRegistration::MARKERS, $hits, 'lib/rrd.php');
                    if ($state['integrity_controls'] !== count(DeviceGraphCallerCoverageRegistration::SOURCES) + 12) throw new RuntimeException('Incomplete coverage rejection controls');
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
    public static function runCliApi(array $scenario, ?SebastianBergmann\CodeCoverage\CodeCoverage $coverage = null): array
    {
        $root = dirname(__DIR__, 2);
        $scenario['api'] = true;
        $directory = sys_get_temp_dir() . '/device-cli-caller-' . bin2hex(random_bytes(8));
        mkdir($directory . '/lib', 0700, true);
        $process = null;
        try {
            foreach (['utility','variables','data_query','rrd'] as $module) file_put_contents($directory . '/lib/' . $module . '.php', '<?php');
            $environment = array_merge(getenv(), ['DEVICE_GRAPH_CALLER_ROOT' => $root,'DEVICE_GRAPH_CALLER_DIRECTORY' => $directory,'DEVICE_GRAPH_CALLER_SCENARIO' => json_encode($scenario, JSON_THROW_ON_ERROR),'DEVICE_GRAPH_CALLER_COVERAGE' => $coverage === null ? '0' : '1']);
            $program = '$_SERVER["REQUEST_METHOD"]="POST";$_SERVER["REMOTE_ADDR"]="127.0.0.1";require ' . var_export($root . '/tests/Fixtures/device-graph-caller-native.php', true) . ';';
            $process = proc_open([PHP_BINARY,'-d','auto_prepend_file=','-d','error_reporting=24575','-d','pcov.directory=' . $root,'-d','pcov.exclude=~/(include/vendor|tests)/~','-r',$program], [0 => ['pipe','r'],1 => ['file',$directory . '/stdout.log','w'],2 => ['file',$directory . '/stderr.log','w']], $pipes, $directory, $environment);
            if (!is_resource($process)) throw new RuntimeException('Native CLI creation failed');
            fclose($pipes[0]);
            $exit = proc_close($process);
            $process = null;
            if ($exit !== 0 || !is_file($directory . '/state.json')) throw new RuntimeException('Native CLI failed: ' . file_get_contents($directory . '/stderr.log'));
            $state = json_decode(file_get_contents($directory . '/state.json'), true, 512, JSON_THROW_ON_ERROR);
            $state += ['stderr' => file_get_contents($directory . '/stderr.log'),'stdout' => file_get_contents($directory . '/stdout.log')];
            if ($coverage !== null) {
                $reports = glob($directory . '/*.coverage');
                if (count($reports) !== 1) throw new RuntimeException('Native CLI coverage missing');
                $coverage->merge(NativeChildCoverageEvidence::load($reports[0], $root, 'tests/Fixtures/device-graph-caller-native.php', json_encode($scenario, JSON_THROW_ON_ERROR), DeviceGraphCallerCoverageRegistration::SOURCES, DeviceGraphCallerCoverageRegistration::MARKERS, ['lib/api_device.php']));
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
