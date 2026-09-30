<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;

final class ForceHttpsNativeBootstrapTest extends TestCase
{
    /** @dataProvider requests */
    public function testActualGlobalBootstrapEmitsTheExpectedHttpResponse(array $scenario, int $status, ?string $location): void
    {
        $root = dirname(__DIR__, 2);
        $directory = sys_get_temp_dir() . '/https-native-' . bin2hex(random_bytes(8));
        mkdir($directory . '/include', 0700, true);
        mkdir($directory . '/lib', 0700);
        mkdir($directory . '/log', 0700);
        $coverage = $this->getTestResultObject()->getCodeCoverage();
        $process = null;
        try {
            copy($root . '/include/global.php', $directory . '/include/global.php');
            copy($root . '/include/runtime.php', $directory . '/include/runtime.php');
            copy($root . '/include/cacti_version', $directory . '/include/cacti_version');
            file_put_contents($directory . '/include/config.php', '<?php $url_path = ' . var_export($scenario['url_path'] ?? '/cacti/', true) . ';');
            foreach (array('functions', 'headers_secure', 'html', 'html_utility', 'html_validate') as $module) {
                file_put_contents($directory . '/lib/' . $module . '.php', '<?php require ' . var_export($root . '/lib/' . $module . '.php', true) . ';');
            }
            file_put_contents($directory . '/include/global_constants.php', '<?php require ' . var_export($root . '/include/global_constants.php', true) . ';');
            file_put_contents($directory . '/lib/database.php', '<?php require ' . var_export($root . '/tests/Fixtures/force-https-native-database.php', true) . ';');
            // Stop at the unrelated language bootstrap, after the complete
            // redirect and native session/header handling has run.
            file_put_contents($directory . '/include/global_languages.php', '<?php echo "NATIVE_BOOTSTRAP_CONTINUED"; exit;');
            $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $error);
            self::assertIsResource($socket, $error);
            $address = stream_socket_get_name($socket, false);
            fclose($socket);
            $environment = array_merge(getenv(), array('HTTPS_NATIVE_ROOT' => $root, 'HTTPS_NATIVE_DIRECTORY' => $directory, 'HTTPS_NATIVE_SCENARIO' => json_encode($scenario, JSON_THROW_ON_ERROR), 'HTTPS_NATIVE_COVERAGE' => $coverage === null ? '0' : '1'));
            $command = array(PHP_BINARY, '-d', 'error_reporting=24575', '-d', 'auto_prepend_file=', '-d', 'pcov.directory=/', '-d', 'pcov.exclude=~/(include/vendor|tests)/~', '-d', 'session.save_path=' . $directory, '-S', $address, $root . '/tests/Fixtures/force-https-native-router.php');
            $process = proc_open($command, array(0 => array('pipe', 'r'), 1 => array('file', $directory . '/stdout.log', 'w'), 2 => array('file', $directory . '/stderr.log', 'w')), $pipes, $directory, $environment);
            self::assertIsResource($process);
            fclose($pipes[0]);
            $ready = false;
            for ($attempt = 0; $attempt < 100; $attempt++) {
                $probe = @fsockopen('tcp://' . $address, -1, $errorCode, $error, 0.1);
                if (is_resource($probe)) {
                    fclose($probe);
                    $ready = true;
                    break;
                }
                usleep(20000);
            }
            self::assertTrue($ready, file_get_contents($directory . '/stderr.log'));
            $context = stream_context_create(array('http' => array('follow_location' => 0, 'ignore_errors' => true, 'timeout' => 5)));
            $body = file_get_contents('http://' . $address . '/test', false, $context);
            self::assertNotFalse($body);
            self::assertStringContainsString(' ' . $status . ' ', $http_response_header[0]);
            $locations = array_values(array_filter($http_response_header, fn($header) => str_starts_with(strtolower($header), 'location:')));
            self::assertSame($location === null ? array() : array('Location: ' . $location), $locations);
            self::assertSame($status === 200 ? 'NATIVE_BOOTSTRAP_CONTINUED' : '', $body);
            if ($coverage !== null) {
                $reports = glob($directory . '/*.coverage');
                self::assertCount(1, $reports);
                $coverage->merge(unserialize(file_get_contents($reports[0])));
            }
            self::assertDoesNotMatchRegularExpression('/PHP (Warning|Fatal error|Notice):/', file_get_contents($directory . '/stderr.log'));
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

    public function requests(): array
    {
        $server = array('SERVER_NAME' => 'kadupul.example', 'HTTP_HOST' => 'attacker.example', 'REQUEST_URI' => '/cacti/host.php?q=a%26b%23c%20d&arr[]=1');
        $redirect = 'https://kadupul.example' . $server['REQUEST_URI'];
        return array(
            'HTTPS unset' => array(array('server' => $server), 302, $redirect),
            'HTTPS off' => array(array('server' => $server + array('HTTPS' => 'off')), 302, $redirect),
            'HTTPS empty' => array(array('server' => $server + array('HTTPS' => '')), 302, $redirect),
            'HTTPS on' => array(array('server' => $server + array('HTTPS' => 'on')), 200, null),
            'force disabled' => array(array('server' => $server, 'force' => ''), 200, null),
            'invalid authority' => array(array('server' => array_replace($server, array('SERVER_NAME' => "bad\r\nLocation: https://attacker.example"))), 400, null),
            'missing request target' => array(array('server' => array('SERVER_NAME' => 'kadupul.example')), 302, 'https://kadupul.example/cacti/'),
            'network path request' => array(array('server' => array_replace($server, array('REQUEST_URI' => '//attacker.example/path'))), 302, 'https://kadupul.example/cacti/'),
            'canonical catch-all' => array(array('server' => array_replace($server, array('SERVER_NAME' => '_')), 'canonical' => 'http://public.example:8080/cacti/'), 302, 'https://public.example' . $server['REQUEST_URI']),
            'unconfigured catch-all' => array(array('server' => array_replace($server, array('SERVER_NAME' => '_'))), 400, null),
            'HTTP listener port' => array(array('server' => array_replace($server, array('SERVER_NAME' => 'kadupul.example:8080'))), 302, $redirect),
            'uppercase trailing dot' => array(array('server' => array_replace($server, array('SERVER_NAME' => 'KADUPUL.EXAMPLE.'))), 302, 'https://KADUPUL.EXAMPLE.' . $server['REQUEST_URI']),
            'IPv4 authority' => array(array('server' => array_replace($server, array('SERVER_NAME' => '192.0.2.1'))), 302, 'https://192.0.2.1' . $server['REQUEST_URI']),
            'bare IPv6 authority' => array(array('server' => array_replace($server, array('SERVER_NAME' => '2001:db8::1'))), 302, 'https://[2001:db8::1]' . $server['REQUEST_URI']),
            'IPv6 HTTP listener' => array(array('server' => array_replace($server, array('SERVER_NAME' => '[2001:db8::1]:8080'))), 302, 'https://[2001:db8::1]' . $server['REQUEST_URI']),
            'bracketed DNS refused' => array(array('server' => array_replace($server, array('SERVER_NAME' => '[example.test]'))), 400, null),
            'broken IPv6 brackets refused' => array(array('server' => array_replace($server, array('SERVER_NAME' => '[2001:db8::1'))), 400, null),
            'invalid HTTP port refused' => array(array('server' => array_replace($server, array('SERVER_NAME' => 'kadupul.example:65536'))), 400, null),
            'empty authority uses canonical URL' => array(array('server' => array_replace($server, array('SERVER_NAME' => '')), 'canonical' => 'https://public.example/cacti/'), 302, 'https://public.example' . $server['REQUEST_URI']),
            'canonical URL credentials refused' => array(array('server' => array_replace($server, array('SERVER_NAME' => '_')), 'canonical' => 'https://user:password@public.example/cacti/'), 400, null),
            'canonical URL scheme refused' => array(array('server' => array_replace($server, array('SERVER_NAME' => '_')), 'canonical' => 'ftp://public.example/cacti/'), 400, null),
            'graph view does not gain an action' => array(array('server' => array_replace($server, array('REQUEST_URI' => '/cacti/graph_view.php?q=a%26b'))), 302, 'https://kadupul.example/cacti/graph_view.php?q=a%26b'),
            'external request target refused' => array(array('server' => array_replace($server, array('REQUEST_URI' => 'https://attacker.example/path'))), 302, 'https://kadupul.example/cacti/'),
            'backslash request target refused' => array(array('server' => array_replace($server, array('REQUEST_URI' => '/\\attacker.example/path'))), 302, 'https://kadupul.example/cacti/'),
            'raw header injection refused' => array(array('server' => array_replace($server, array('REQUEST_URI' => "/cacti/\r\nLocation: https://attacker.example"))), 302, 'https://kadupul.example/cacti/'),
            'unsafe configured path falls back to root' => array(array('server' => array_replace($server, array('REQUEST_URI' => '//attacker.example/')), 'url_path' => '//attacker.example/'), 302, 'https://kadupul.example/'),
        );
    }
}
