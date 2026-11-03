<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

require_once __DIR__ . '/InstallerProbeDiagnostics.php';

final class InstallerProbeDiagnosticsTest extends TestCase
{
    public function testOnlyRealExceptionClassesAndContainedCodeFramesSurvive(): void
    {
        $root = sys_get_temp_dir() . '/installer-diagnostic-frames-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($root . '/src', 0700, true));
        self::assertTrue(mkdir($root . '/config', 0700));
        file_put_contents($root . '/src/Kernel.php', '<?php');
        file_put_contents($root . '/config/bootstrap.php', '<?php');
        $outside = sys_get_temp_dir() . '/installer-diagnostic-outside-' . bin2hex(random_bytes(8)) . '.php';
        file_put_contents($outside, '<?php');
        try {
            self::assertTrue(symlink($outside, $root . '/src/Outside.php'));
            $secret = 'probe-password-keep-private';
            $log = "PHP Fatal error: Uncaught RuntimeException: password=$secret SELECT credential FROM users in $root/src/Kernel.php:42\n"
                . "#0 $root/config/bootstrap.php(12): invoke('$secret', 'SELECT credential')\n"
                . "#1 /outside/project/private.php(19): ignored()\n"
                . "#2 $root/../private.php(25): ignored()\n"
                . "#3 $root/src/$secret.php(30): ignored()\n"
                . "#4 $root/src/Outside.php(31): ignored()\n"
                . "GET /?APP_SECRET=$secret HTTP/1.1\n";
            $data = InstallerProbeDiagnostics::server($log, $root);
            self::assertSame(['RuntimeException'], $data['exception_classes']);
            self::assertSame([['file' => 'src/Kernel.php', 'line' => 42], ['file' => 'config/bootstrap.php', 'line' => 12]], $data['frames']);
            self::assertSame(hash('sha256', $log), $data['log_sha256']);
            self::assertSame([['path_sha256' => hash('sha256', 'src/' . $secret . '.php'), 'line' => 30],
                ['path_sha256' => hash('sha256', 'src/Outside.php'), 'line' => 31]], $data['unresolved_frames']);
            foreach ([$secret, 'SELECT', 'credential', 'APP_SECRET', $root, $outside, 'Outside.php', 'invoke('] as $forbidden) {
                self::assertStringNotContainsString($forbidden, json_encode($data, JSON_THROW_ON_ERROR));
            }
        } finally {
            unlink($root . '/src/Kernel.php');
            if (is_link($root . '/src/Outside.php')) unlink($root . '/src/Outside.php');
            unlink($outside);
            unlink($root . '/config/bootstrap.php');
            rmdir($root . '/src');
            rmdir($root . '/config');
            rmdir($root);
        }
    }

    public function testUnknownMessagesAreDigestedAndLogProcessingIsBounded(): void
    {
        $log = str_repeat('x', InstallerProbeDiagnostics::MAX_LOG_BYTES) . ' password=private SQL SELECT private';
        $data = InstallerProbeDiagnostics::server($log, '/owned/project');
        self::assertSame('unclassified', $data['kind']);
        self::assertSame([], $data['exception_classes']);
        self::assertSame([], $data['frames']);
        self::assertTrue($data['truncated']);
        self::assertSame(hash('sha256', substr($log, -InstallerProbeDiagnostics::MAX_LOG_BYTES)), $data['log_sha256']);
        self::assertStringNotContainsString('private', json_encode($data, JSON_THROW_ON_ERROR));
        $frames = '';
        for ($line = 1; $line <= 80; ++$line) $frames .= "#0 /owned/project/src/Kernel.php($line): call()\n";
        self::assertCount(32, InstallerProbeDiagnostics::server($frames, '/owned/project')['unresolved_frames']);
    }

    public function testActualHttpHelperAdmits200ThenRetains500WithoutSecretsOrMaskingCleanup(): void
    {
        $root = dirname(__DIR__, 2);
        require_once dirname(__DIR__) . '/Helpers/PhpSource.php';
        $source = file_get_contents($root . '/tests/security/cdef_reference_web_installer_native_probe.php');
        self::assertIsString($source);
        if (!function_exists(__NAMESPACE__ . '\\ProbeHttp\\webInstallerStatus')) {
            $functions = '';
            foreach (['webRequest', 'webInstallerStatus', 'webLifecycleDiagnostics'] as $name) $functions .= \test_php_function_source($source, $name);
            eval('namespace Kadupul\\Tests\\ProbeHttp; use PDO; use RuntimeException; use Throwable; ' . $functions);
        }
        /** @var callable(string,string):array<string,mixed> $readStatus */
        $readStatus = __NAMESPACE__ . '\\ProbeHttp\\webInstallerStatus';
        /** @var callable(\PDO,string,int,?string):void $lifecycle */
        $lifecycle = __NAMESPACE__ . '\\ProbeHttp\\webLifecycleDiagnostics';
        self::assertIsCallable($readStatus);
        self::assertIsCallable($lifecycle);
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        self::assertIsResource($listener);
        $address = stream_socket_get_name($listener, false);
        fclose($listener);
        self::assertIsString($address);
        $directory = sys_get_temp_dir() . '/installer-diagnostics-' . bin2hex(random_bytes(8));
        $router = $directory . '/router.php';
        $cookies = $directory . '/cookies';
        $serverLog = $directory . '/server.log';
        $installerLog = $directory . '/installer.log';
        $cleanup = $directory . '/owned-cleanup';
        $body = 'password=probe-password-keep-private; SELECT credential; APP_SECRET=do-not-log';
        $script = '<?php if (str_starts_with($_SERVER["REQUEST_URI"], "/ok/")) { header("Content-Type: application/json"); echo "{\"state\":\"running\",\"progress\":0,\"schema\":\"1.2.31\"}"; return; } http_response_code(500); error_log("PHP Fatal error: Uncaught RuntimeException: password=probe-password-keep-private SELECT credential in " . getenv("PROBE_PROJECT_ROOT") . "/src/Kernel.php:42"); echo ' . var_export($body, true) . ';';
        $server = null;
        unset($GLOBALS['nativeInstallerStatusFailure']);
        try {
            self::assertTrue(mkdir($directory, 0700));
            self::assertSame(strlen($script), file_put_contents($router, $script));
            foreach ([$cookies, $installerLog, $cleanup] as $file) self::assertSame(0, file_put_contents($file, ''));
            self::assertTrue(chmod($cookies, 0600));
            $server = new Process([PHP_BINARY, '-d', 'auto_prepend_file=', '-d', 'zend.exception_ignore_args=1', '-S', $address, $router], $root, ['PROBE_PROJECT_ROOT' => $root], timeout: 20);
            $server->start();
            $ready = false;
            for ($attempt = 0; $attempt < 100; ++$attempt) {
                $connection = @stream_socket_client('tcp://' . $address, $errno, $error, 0.05);
                if (is_resource($connection)) {
                    fclose($connection);
                    $ready = true;
                    break;
                }
                usleep(10000);
            }
            self::assertTrue($ready, $server->getErrorOutput());
            self::assertSame('running', $readStatus('http://' . $address . '/ok/', $cookies)['state']);
            self::assertArrayNotHasKey('nativeInstallerStatusFailure', $GLOBALS);
            $failure = null;
            try {
                $readStatus('http://' . $address . '/failure/', $cookies);
                self::fail('The unexpected HTTP500 must retain its original refusal.');
            } catch (\RuntimeException $exception) {
                $failure = $exception;
                self::assertSame('The actual readonly installer status did not return its JSON contract: HTTP 500.', $exception->getMessage());
            }
            self::assertInstanceOf(\RuntimeException::class, $failure);
            self::assertSame(500, $GLOBALS['nativeInstallerStatusFailure']['http_status']);
            self::assertSame(hash('sha256', $body), $GLOBALS['nativeInstallerStatusFailure']['response_sha256']);
            self::assertLessThanOrEqual($GLOBALS['nativeInstallerStatusFailure']['request_finished_us'], $GLOBALS['nativeInstallerStatusFailure']['request_started_us']);
            $GLOBALS['nativeInstallerStatusFailure']['injected_secret'] = 'probe-password-keep-private';
            self::assertGreaterThan(0, file_put_contents($serverLog, $server->getErrorOutput()));
            $database = new \PDO('sqlite::memory:');
            $database->exec('CREATE TABLE settings (name TEXT, value TEXT); CREATE TABLE processes (tasktype TEXT, taskname TEXT, taskid INT, pid INT, timeout INT, started TEXT, last_update TEXT)');
            ob_start();
            $lifecycle($database, $installerLog, 0, $serverLog);
            $output = ob_get_clean();
            self::assertIsString($output);
            self::assertSame(1, preg_match('/^WEB_STATUS_FAILURE (.+)$/m', $output, $matches));
            $diagnostic = json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);
            self::assertGreaterThanOrEqual($diagnostic['request']['request_finished_us'], $diagnostic['captured_at_us']);
            self::assertSame(['RuntimeException'], $diagnostic['server']['exception_classes']);
            self::assertContains(['file' => 'src/Kernel.php', 'line' => 42], $diagnostic['server']['frames']);
            foreach (['probe-password-keep-private', 'SELECT credential', 'APP_SECRET', '?view=status'] as $forbidden) self::assertStringNotContainsString($forbidden, $output);
            // Inject a diagnostic serialization failure after the real HTTP500.
            // Finally must preserve that original error and still clean owned work.
            $GLOBALS['nativeInstallerStatusFailure']['request_started_us'] = INF;
            ob_start();
            try {
                try {
                    throw $failure;
                } finally {
                    $lifecycle($database, $installerLog, 0, $serverLog);
                    self::assertTrue(unlink($cleanup));
                }
            } catch (\RuntimeException $preserved) {
                self::assertSame($failure, $preserved);
            }
            $failedDiagnostic = ob_get_clean();
            self::assertStringContainsString('WEB_STATUS_FAILURE {"kind":"diagnostics_unavailable"}', $failedDiagnostic);
            self::assertFileDoesNotExist($cleanup);
        } finally {
            unset($GLOBALS['nativeInstallerStatusFailure']);
            try {
                $server?->stop(1);
            } finally {
                foreach (glob($directory . '/*') ?: [] as $file) unlink($file);
                if (is_dir($directory)) rmdir($directory);
            }
        }
    }
}
