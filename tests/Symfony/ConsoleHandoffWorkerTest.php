<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * The shared console worker handoff, run as the real child process. Byte
 * identical copies of the workers sit beside a stand-in include/auth.php, so
 * every refusal is reached without a database; each case asserts the stderr
 * line of the guard it targets, not just a failure.
 */
final class ConsoleHandoffWorkerTest extends TestCase
{
    private const string HANDOFF = 'bin/legacy-console-handoff.php';

    private const string SESSION_ID = 'abcdefghijklmnopqrstuvwxyz012345';

    private static bool $rejectionsVerified = false;

    private string $root;

    private string $directory;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        // Canonical, because the worker reports realpath-derived script paths.
        $this->directory = (string) realpath(sys_get_temp_dir()) . '/console-handoff-worker-' . bin2hex(random_bytes(8));
        foreach (['', '/bin', '/include', '/sessions'] as $folder) {
            self::assertTrue(mkdir($this->directory . $folder, 0700));
        }
        foreach ([self::HANDOFF, 'bin/legacy-console-menu.php', 'bin/legacy-console-home.php', 'bin/legacy-permission-denied.php'] as $file) {
            self::assertTrue(copy($this->root . '/' . $file, $this->directory . '/' . $file));
        }
        self::assertTrue(copy($this->root . '/tests/Fixtures/console-handoff-bootstrap.php', $this->directory . '/include/auth.php'));
    }

    protected function tearDown(): void
    {
        foreach (['/sessions/*', '/bin/*', '/include/*', '/coverage/*', '/*'] as $pattern) {
            foreach (glob($this->directory . $pattern) ?: [] as $file) {
                is_dir($file) ? rmdir($file) : unlink($file);
            }
        }
        rmdir($this->directory);
    }

    public function testAnAuthorizedActorReceivesTheMenuWithoutBootstrapOutput(): void
    {
        // Measures the menu worker itself; the shared handoff's success path is
        // measured by the largest accepted command below.
        $run = $this->worker('bin/legacy-console-menu.php', $this->command(), measured: 'bin/legacy-console-menu.php');

        self::assertSame('', $run['stderr']);
        self::assertSame(0, $run['exit']);
        self::assertSame(['status' => 'ok', 'actor' => 7, 'entries' => [
            ['section' => 'Management', 'label' => 'Devices', 'path' => 'host.php', 'realm' => 3],
            ['section' => 'Management', 'label' => 'Fixture', 'path' => 'plugins/fixture/fixture.php', 'realm' => 150],
        ]], json_decode($run['stdout'], true, 8, JSON_THROW_ON_ERROR));
        // The bootstrap saw the presented native page, the handed-off session and the include directory.
        self::assertSame(['/kadupul/about.php', $this->directory . '/about.php', 'GET', self::SESSION_ID, $this->directory . '/sessions', $this->directory . '/include'], $run['state']);
    }

    public function testHandoffDoesNotPolluteTheEarlyNativeSettingsReader(): void
    {
        $run = $this->worker('bin/legacy-console-menu.php', $this->command(), settingsProbe: true);
        self::assertSame(0, $run['exit'], $run['stderr']);
        self::assertSame('', $run['stderr']);
        self::assertSame('ok', json_decode($run['stdout'], true, 8, JSON_THROW_ON_ERROR)['status']);
    }

    public function testOptedInRealmDenialBindsTheNativeSessionAndClearsBootstrapOutput(): void
    {
        $this->realmDenialOptIn();
        $run = $this->worker('bin/legacy-console-menu.php', $this->command(), realmDenied: true);
        self::assertSame(0, $run['exit'], $run['stderr']);
        self::assertSame('', $run['stderr']);
        self::assertSame(['status' => 'ok','actor' => 7,'outcome' => 'denied'], json_decode($run['stdout'], true, 8, JSON_THROW_ON_ERROR));
        $session = file_get_contents($this->directory . '/sessions/sess_' . self::SESSION_ID);
        self::assertIsString($session);
        self::assertStringContainsString('sess_user_id|i:7;', $session);
    }

    public static function deniedBindings(): iterable
    {
        yield 'wrong actor' => [['sess_user_id' => 8,'sess_user_credential' => str_repeat('a', 64)],false];
        yield 'malformed actor' => [['sess_user_id' => '7bad','sess_user_credential' => str_repeat('a', 64)],false];
        yield 'wrong credential' => [['sess_user_id' => 7,'sess_user_credential' => str_repeat('b', 64)],false];
        yield 'missing credential' => [['sess_user_id' => 7],false];
        yield 'released session' => [['sess_user_id' => 7,'sess_user_credential' => str_repeat('a', 64)],true];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('deniedBindings')]
    public function testRealmDenialCannotAuthenticateAnUnboundOrReleasedSession(array $session, bool $released): void
    {
        $this->realmDenialOptIn();
        $run = $this->worker('bin/legacy-console-menu.php', $this->command(), session: $session, hookClosesSession: $released, realmDenied: true);
        self::assertSame(1, $run['exit']);
        self::assertSame(['status' => 'failed'], json_decode($run['stdout'], true, 8, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString(str_repeat('a', 64), $run['stdout'] . $run['stderr']);
        self::assertStringNotContainsString('bootstrap output', $run['stdout'] . $run['stderr']);
        self::assertNotSame('', $run['stderr']);
    }

    public function testDefaultWorkersDoNotOptInToTypedNativeRealmDenials(): void
    {
        $run = $this->worker('bin/legacy-console-menu.php', $this->command(), realmDenied: true);
        self::assertSame(1, $run['exit']);
        self::assertSame(['status' => 'failed'], json_decode($run['stdout'], true, 8, JSON_THROW_ON_ERROR));
    }

    public function testCommandCannotSelectTheNativeRealmDenialMode(): void
    {
        $command = $this->command();
        $command['realm_denial'] = true;
        $run = $this->worker('bin/legacy-console-menu.php', $command, realmDenied: true);
        self::assertSame(1, $run['exit']);
        self::assertNull($run['state'], 'Reject before native session/bootstrap access.');
    }

    public function testNullTrustedRealmDenialConfigurationIsRefused(): void
    {
        $this->realmDenialOptIn();
        $path = $this->directory . '/bin/legacy-console-menu.php';
        $source = file_get_contents($path);
        self::assertIsString($source);
        file_put_contents($path, str_replace("'realm_denial' => true", "'realm_denial' => null", $source));
        $run = $this->worker('bin/legacy-console-menu.php', $this->command());
        self::assertSame(1, $run['exit']);
        self::assertNull($run['state']);
    }

    private function realmDenialOptIn(): void
    {
        $path = $this->directory . '/bin/legacy-console-menu.php';
        $source = file_get_contents($path);
        self::assertIsString($source);
        $replacement = str_replace("    'json' => JSON_INVALID_UTF8_SUBSTITUTE,", "    'json' => JSON_INVALID_UTF8_SUBSTITUTE,\n    'realm_denial' => true,", $source, $count);
        self::assertSame(1, $count);
        self::assertNotFalse(file_put_contents($path, $replacement));
    }

    public function testTrustedMutationInputLimitAcceptsLargeCommands(): void
    {
        $wrapper = (string) file_get_contents($this->directory . '/bin/legacy-console-menu.php');
        $wrapper = str_replace("    'json' => JSON_INVALID_UTF8_SUBSTITUTE,", "    'json' => JSON_INVALID_UTF8_SUBSTITUTE,\n    'input_limit' => 1048576,", $wrapper, $count);
        self::assertSame(1, $count);
        file_put_contents($this->directory . '/bin/legacy-console-menu.php', $wrapper);
        $command = $this->command();
        $command['server']['HTTP_USER_AGENT'] = str_repeat('a', 70000);
        $run = $this->worker('bin/legacy-console-menu.php', $command);
        self::assertSame(0, $run['exit']);
        self::assertSame('', $run['stderr']);
        $command['server']['HTTP_USER_AGENT'] = str_repeat('a', 1048576);
        $run = $this->worker('bin/legacy-console-menu.php', $command);
        self::assertSame(1, $run['exit']);
        self::assertSame(['status' => 'failed'], json_decode($run['stdout'], true, 8, JSON_THROW_ON_ERROR));
    }

    public function testAnExplicitNullInputLimitIsNotTheDefault(): void
    {
        $wrapper = (string) file_get_contents($this->directory . '/bin/legacy-console-menu.php');
        $wrapper = str_replace("    'json' => JSON_INVALID_UTF8_SUBSTITUTE,", "    'json' => JSON_INVALID_UTF8_SUBSTITUTE,\n    'input_limit' => null,", $wrapper, $count);
        self::assertSame(1, $count);
        file_put_contents($this->directory . '/bin/legacy-console-menu.php', $wrapper);
        $run = $this->worker('bin/legacy-console-menu.php', $this->command());
        self::assertSame(1, $run['exit']);
        self::assertSame('LogicException', json_decode($run['stderr'], true, 8, JSON_THROW_ON_ERROR)['class']);
        self::assertSame(['status' => 'failed'], json_decode($run['stdout'], true, 8, JSON_THROW_ON_ERROR));
    }

    /** @return iterable<string, array{string, string, string, string}> */
    public static function refusals(): iterable
    {
        $setting = "throw new LogicException('Invalid console handoff configuration.');";
        $command = "throw new InvalidArgumentException('Invalid console handoff.');";
        yield 'missing realm setting' => ['missing-realm', 'handoff', 'LogicException', $setting];
        yield 'string realm setting' => ['string-realm', 'handoff', 'LogicException', $setting];
        yield 'malformed JSON' => ['malformed', 'handoff', 'JsonException', '$command = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);'];
        yield 'oversized stdin' => ['oversized', 'handoff', 'InvalidArgumentException', $command];
        yield 'unknown command key' => ['unknown-key', 'handoff', 'InvalidArgumentException', $command];
        yield 'page outside about.php' => ['suffix', 'handoff', 'InvalidArgumentException', $command];
        yield 'non-string sections' => ['sections', 'handoff', 'InvalidArgumentException', $command];
        yield 'non-string query string' => ['query-string', 'handoff', 'InvalidArgumentException', "throw new InvalidArgumentException('Invalid console query string.');"];
        $revoked = "throw new RuntimeException('Console actor was revoked.');";
        yield 'session of another actor' => ['other-actor', 'identity', 'RuntimeException', $revoked];
        yield 'rotated credential' => ['credential', 'identity', 'RuntimeException', $revoked];
        yield 'console realm revoked' => ['realm', 'identity', 'RuntimeException', $revoked];
        yield 'permission denied page actor revoked' => ['denied-page', 'identity', 'RuntimeException', $revoked];
        yield 'session closed by a hook' => ['hook-closed-session', 'session', 'RuntimeException', "throw new RuntimeException('Console session changes could not be persisted.');"];
    }

    #[DataProvider('refusals')]
    public function testRefusalsFailClosedWithOnlyFixedDiagnostics(string $case, string $phase, string $class, string $statement): void
    {
        $command = $this->command();
        $script = 'bin/legacy-console-menu.php';
        $session = ['sess_user_id' => 7, 'sess_user_credential' => str_repeat('a', 64)];
        $realms = [8];
        $hookClosesSession = false;
        $input = null;
        switch ($case) {
            case 'missing-realm':
            case 'string-realm':
                $script = 'bin/' . $case . '.php';
                $wrapper = (string) file_get_contents($this->root . '/bin/legacy-console-home.php');
                $wrapper = str_replace("    'realm' => 8,\n", $case === 'string-realm' ? "    'realm' => '8',\n" : '', $wrapper, $count);
                self::assertSame(1, $count);
                file_put_contents($this->directory . '/' . $script, $wrapper);
                unset($command['sections']);
                $command['index_path'] = $command['page_path'];
                unset($command['page_path']);
                break;
            case 'malformed':
                $input = '{"actor":7,';
                break;
            case 'oversized':
                $command['cookies']['padding'] = '';
                $command['cookies']['padding'] = str_repeat('x', 65537 - strlen(json_encode($command, JSON_THROW_ON_ERROR)));
                $input = json_encode($command, JSON_THROW_ON_ERROR);
                self::assertSame(65537, strlen($input));
                break;
            case 'unknown-key':
                $command['index_path'] = '/kadupul/index.php';
                break;
            case 'suffix':
                $command['page_path'] = '/kadupul/index.php';
                break;
            case 'sections':
                $command['sections'] = ['Management', 3];
                break;
            case 'query-string':
                $command['server']['QUERY_STRING'] = 5;
                break;
            case 'other-actor':
                $session['sess_user_id'] = 8;
                break;
            case 'credential':
                $session['sess_user_credential'] = str_repeat('b', 64);
                break;
            case 'denied-page':
                $script = 'bin/legacy-permission-denied.php';
                $session['sess_user_id'] = 8;
                $command['page_path'] = '/kadupul/permission_denied.php';
                unset($command['sections']);
                break;
            case 'realm':
                $script = 'bin/legacy-console-home.php';
                $realms = [];
                $command['index_path'] = '/kadupul/index.php';
                unset($command['page_path'], $command['sections']);
                break;
            case 'hook-closed-session':
                $hookClosesSession = true;
                break;
        }

        // A shipped worker is measured itself; the shared file through the menu worker.
        $measured = in_array($case, ['realm', 'denied-page'], true) ? $script : self::HANDOFF;
        $run = $this->worker($script, $input ?? json_encode($command, JSON_THROW_ON_ERROR), $session, $realms, $hookClosesSession, $measured);

        self::assertSame(1, $run['exit']);
        self::assertSame('{"status":"failed"}', $run['stdout']);
        $diagnostic = json_decode($run['stderr'], true, 4, JSON_THROW_ON_ERROR);
        self::assertSame(['phase', 'class', 'file', 'line'], array_keys($diagnostic));
        self::assertSame([$phase, $class, basename(self::HANDOFF), $this->line($statement)], array_values($diagnostic));
    }

    public function testTheLargestAcceptedCommandStillReachesTheBootstrap(): void
    {
        $command = $this->command();
        $command['cookies']['padding'] = '';
        $command['cookies']['padding'] = str_repeat('x', 65536 - strlen(json_encode($command, JSON_THROW_ON_ERROR)));

        $run = $this->worker('bin/legacy-console-menu.php', $command);

        self::assertSame(0, $run['exit'], $run['stderr']);
        self::assertSame('ok', json_decode($run['stdout'], true, 8, JSON_THROW_ON_ERROR)['status']);
    }

    /** @return iterable<string, array{string}> */
    public static function webEntries(): iterable
    {
        yield 'shared handoff' => [self::HANDOFF];
        yield 'menu worker' => ['bin/legacy-console-menu.php'];
    }

    #[DataProvider('webEntries')]
    public function testAWebSapiRequestIsRefusedBeforeAnyHandoff(string $script): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $error, $message);
        self::assertIsResource($socket, $message);
        $address = stream_socket_get_name($socket, false);
        self::assertIsString($address);
        fclose($socket);
        [$options, $environment] = $this->coverageOptions($script, false, 'cli-server ' . $script);
        $server = new Process([PHP_BINARY, ...$options, '-S', $address, '-t', $this->root], $this->directory, $environment);
        $server->start();
        try {
            $response = false;
            $deadline = microtime(true) + 10;
            do {
                $connection = @stream_socket_client('tcp://' . $address, $error, $message, 0.1);
                if (is_resource($connection)) {
                    stream_set_timeout($connection, 5);
                    fwrite($connection, 'GET /' . $script . " HTTP/1.0\r\nHost: localhost\r\n\r\n");
                    $response = stream_get_contents($connection);
                    fclose($connection);
                    break;
                }
                usleep(10000);
            } while ($server->isRunning() && microtime(true) < $deadline);
        } finally {
            $server->stop();
        }

        self::assertIsString($response, $server->getErrorOutput());
        [$head, $body] = explode("\r\n\r\n", $response, 2);
        self::assertStringStartsWith('HTTP/1.0 404 Not Found', $head);
        self::assertSame('', $body);
        $this->mergeCoverage($script, 'cli-server ' . $script);
    }

    /** @return array<string, mixed> */
    private function command(): array
    {
        return [
            'actor' => 7,
            'credential' => str_repeat('a', 64),
            'session_id' => self::SESSION_ID,
            'session_path' => $this->directory . '/sessions',
            'page_path' => '/kadupul/about.php',
            'sections' => ['Management'],
            'server' => ['HTTP_HOST' => 'example.test'],
            'cookies' => ['Cacti' => self::SESSION_ID],
            'query' => [],
        ];
    }

    /**
     * @param array<string, mixed>|string $input
     * @param array<string, mixed>|null $session
     * @param list<int> $realms
     * @return array{exit: ?int, stdout: string, stderr: string, state: ?list<mixed>}
     */
    private function worker(string $script, array|string $input, ?array $session = null, array $realms = [8], bool $hookClosesSession = false, string $measured = self::HANDOFF, bool $settingsProbe = false, bool $realmDenied = false): array
    {
        $session ??= ['sess_user_id' => 7, 'sess_user_credential' => str_repeat('a', 64)];
        $scenario = ['autoload' => $this->root . '/include/vendor/autoload.php', 'hook_closes_session' => $hookClosesSession, 'settings_probe' => $settingsProbe, 'realm_denied' => $realmDenied, 'session' => $session, 'realms' => $realms];
        file_put_contents($this->directory . '/scenario.json', json_encode($scenario, JSON_THROW_ON_ERROR));
        $label = hash('sha256', $script . ' ' . (is_string($input) ? $input : json_encode($input, JSON_THROW_ON_ERROR)) . ' ' . json_encode($scenario, JSON_THROW_ON_ERROR));
        [$options, $environment] = $this->coverageOptions($measured, true, $label);
        $process = new Process([PHP_BINARY, ...$options, $this->directory . '/' . $script], $this->directory, $environment);
        $process->setInput(is_string($input) ? $input : json_encode($input, JSON_THROW_ON_ERROR));
        $process->setTimeout(30);
        $process->run();
        $this->mergeCoverage($measured, $label);
        $state = is_file($this->directory . '/state.json')
            ? json_decode((string) file_get_contents($this->directory . '/state.json'), true, 4, JSON_THROW_ON_ERROR) : null;

        return ['exit' => $process->getExitCode(), 'stdout' => $process->getOutput(), 'stderr' => $process->getErrorOutput(), 'state' => $state];
    }

    /** @return array{list<string>, array<string, string>} */
    private function coverageOptions(string $measured, bool $copy, string $scenario): array
    {
        if (!\PHPUnit\Runner\CodeCoverage::instance()->isActive()) {
            return [['-d', 'auto_prepend_file='], []];
        }
        if (!is_dir($this->directory . '/coverage')) {
            self::assertTrue(mkdir($this->directory . '/coverage', 0700));
        }
        $environment = [
            'KADUPUL_CONSOLE_HANDOFF_COVERAGE' => $this->directory . '/coverage',
            'KADUPUL_CONSOLE_HANDOFF_MEASURED' => $measured,
            'KADUPUL_CONSOLE_HANDOFF_SCENARIO' => $scenario,
        ];
        if ($copy) {
            $environment['KADUPUL_CONSOLE_HANDOFF_COPY'] = $this->directory;
        }

        return [['-d', 'pcov.directory=/', '-d', 'pcov.exclude=~/(include/vendor|tests)/|^' . preg_quote($this->root . '/var/', '~') . '~',
            '-d', 'auto_prepend_file=' . $this->root . '/tests/Fixtures/console-handoff-native.php'], $environment];
    }

    private function mergeCoverage(string $measured, string $scenario): void
    {
        if (!\PHPUnit\Runner\CodeCoverage::instance()->isActive()) {
            return;
        }
        require_once $this->root . '/tests/Helpers/NativeChildCoverageEvidence.php';
        $reports = glob($this->directory . '/coverage/*.coverage') ?: [];
        self::assertCount(1, $reports);
        $producer = 'tests/Fixtures/console-handoff-native.php';
        $sources = ['composer.lock', 'tests/composer.lock', 'tests/Fixtures/rrd-process-coverage.php', 'tests/Helpers/NativeChildCoverageEvidence.php',
            'tests/Fixtures/console-handoff-bootstrap.php', 'tests/Symfony/ConsoleHandoffWorkerTest.php', 'lib/functions.php',
            'src/Platform/Infrastructure/Legacy/BrowserWorkerRealmDenied.php', $measured];
        $markers = ['console-handoff-worker-terminated'];
        $child = \NativeChildCoverageEvidence::load($reports[0], $this->root, $producer, $scenario, $sources, $markers, [$measured]);
        if (!self::$rejectionsVerified) {
            self::assertSame(count($sources) + count($markers) + 10, \NativeChildCoverageEvidence::verifyRejections($reports[0], $this->root, $producer, $scenario, $sources, $markers, [$measured], $measured));
            self::$rejectionsVerified = true;
        }
        \PHPUnit\Runner\CodeCoverage::instance()->codeCoverage()->merge($child);
        unlink($reports[0] . '.json');
        unlink($reports[0]);
    }

    private function line(string $statement): int
    {
        $lines = array_keys(array_map('trim', file($this->root . '/' . self::HANDOFF, FILE_IGNORE_NEW_LINES)), $statement, true);
        self::assertCount(1, $lines, $statement);

        return $lines[0] + 1;
    }
}
