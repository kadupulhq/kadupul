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

final class BrowserRealmDenialNativeTest extends TestCase
{
    private const array WORKERS = ['legacy-console-handoff.php'];
    private ?\PDO $database = null;
    private string $schema = '';
    private string $directory = '';
    private string $dsn = '';
    private string $credential;
    private static bool $coverageRejectionsVerified = false;
    private static ?bool $childPcovLoaded = null;
    private string $connectionMode = 'online';
    private array $httpCoverageScenarios = [];
    private string $httpCoverageMainSource = 'include/utility_plugins.php';
    private string $readUser = '';
    private string $readPassword = '';

    protected function setUp(): void
    {
        $dsn = getenv('KADUPUL_TEST_MYSQL_DSN');
        if (!is_string($dsn) || $dsn === '') {
            $this->markTestSkipped('KADUPUL_TEST_MYSQL_DSN is required for authenticated utility workers.');
        }
        $root = dirname(__DIR__, 2);
        $this->database = new \PDO($dsn, getenv('KADUPUL_TEST_MYSQL_ADMIN_USER') ?: 'root', getenv('KADUPUL_TEST_MYSQL_ADMIN_PASSWORD') ?: '', [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $this->schema = 'utility_worker_' . bin2hex(random_bytes(8));
        $this->database->exec('CREATE DATABASE `' . $this->schema . '` CHARACTER SET utf8mb4');
        $this->database->exec('USE `' . $this->schema . '`');
        $this->database->exec("SET SESSION sql_mode = ''");
        $source = file_get_contents($root . '/cacti.sql');
        $this->assertIsString($source);
        preg_match_all('/^CREATE TABLE `?([a-z_]+)`? \(/m', $source, $tables);
        $this->assertGreaterThan(50, count($tables[1]));
        foreach ($tables[1] as $table) {
            $this->assertSame(1, preg_match('/CREATE TABLE `?' . $table . '`? \([^;]*?\)\s+ENGINE\s*=[^;]*;/s', $source, $match), $table);
            $this->assertSame(1, substr_count($match[0], 'CREATE TABLE'), $table);
            $this->database->exec($match[0]);
        }
        $this->directory = (string) realpath(sys_get_temp_dir()) . '/utility-native-worker-' . bin2hex(random_bytes(10));
        foreach (['', '/bin', '/include', '/sessions', '/log'] as $folder) {
            $this->assertTrue(mkdir($this->directory . $folder, 0700));
        }
        foreach (self::WORKERS as $worker) {
            $this->assertTrue(copy($root . '/bin/' . $worker, $this->directory . '/bin/' . $worker));
        }
        $this->assertTrue(copy($root . '/tests/Fixtures/legacy-browser-realm-denial.php', $this->directory . '/bin/legacy-browser-realm-denial.php'));
        $this->assertTrue(copy($root . '/include/auth.php', $this->directory . '/include/auth.php'));
        $this->assertTrue(copy($root . '/include/cacti_version', $this->directory . '/include/cacti_version'));
        $this->assertTrue(copy($root . '/tests/Fixtures/browser-realm-denial-global.php', $this->directory . '/include/global.php'));
        $this->assertTrue(symlink($root . '/include/themes', $this->directory . '/include/themes'));
        $this->assertTrue(symlink($root . '/lib', $this->directory . '/lib'));
        $this->assertTrue(symlink($root . '/mibs', $this->directory . '/mibs'));
        $this->assertTrue(symlink($root . '/locales', $this->directory . '/locales'));
        $this->assertTrue(symlink($root . '/src', $this->directory . '/src'));
        $this->assertNotFalse(file_put_contents($this->directory . '/log/cacti.log', "first\nWARN fixture\nlast\n"));
        $this->credential = hash('sha256', 'local-utility-fixture-password-hash');
        $this->database->exec("INSERT INTO user_auth (id, username, password, enabled, realm, must_change_password, password_change) VALUES (42, 'operator', 'local-utility-fixture-password-hash', 'on', 0, '', 'on')");
        $this->database->exec('INSERT INTO user_auth_realm (user_id, realm_id) VALUES (42, 8), (42, 1)');
        $settings = $this->database->prepare('INSERT INTO settings (name, value) VALUES (?, ?)');
        foreach (['auth_method' => '1', 'guest_user' => '0', 'path_cactilog' => $this->directory . '/log/cacti.log', 'path_stderrlog' => '',
            'num_rows_table' => '25', 'max_display_rows' => '100', 'log_refresh_interval' => '9999999', 'log_destination' => '1', 'log_verbosity' => '2', 'enable_snmp_agent' => 'on', 'i18n_language_support' => '0',
            'storage_location' => '0', 'path_rrdtool' => '', 'path_snmpget' => '', 'path_spine' => ''] as $name => $value) {
            $this->assertTrue($settings->execute([$name, $value]));
        }
        $this->database->exec("INSERT INTO version (cacti) VALUES ('" . trim(file_get_contents($root . '/include/cacti_version')) . "')");
        $this->dsn = preg_replace('/;dbname=[^;]*/', '', $dsn) . ';dbname=' . $this->schema;
    }

    protected function tearDown(): void
    {
        if ($this->database !== null && $this->schema !== '') {
            $this->database->exec('DROP DATABASE `' . $this->schema . '`');
        }
        if ($this->directory !== '') {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) {
                if ($file->isDir() && !$file->isLink()) {
                    rmdir($file->getPathname());
                } else {
                    unlink($file->getPathname());
                }
            }
            rmdir($this->directory);
        }
    }

    public static function backends(): iterable
    {
        yield 'files' => [false];
        yield 'database' => [true];
    }

    private function environment(bool $databaseSessions, string $mode = 'normal', bool $optIn = true): array
    {
        return ['KADUPUL_BROWSER_DENIAL_SOURCE_ROOT' => dirname(__DIR__, 2),'KADUPUL_BROWSER_DENIAL_WORKER_DSN' => $this->dsn,
            'KADUPUL_BROWSER_DENIAL_SESSION_BACKEND' => $databaseSessions ? 'database' : 'files','KADUPUL_BROWSER_DENIAL_HOOK' => $mode,
            'KADUPUL_BROWSER_DENIAL_OPT_IN' => $optIn ? '1' : '0'];
    }

    private function packet(array $environment, bool $databaseSessions): array
    {
        $seed = new Process([PHP_BINARY,dirname(__DIR__) . '/Fixtures/browser-realm-denial-session.php'], null, $environment, json_encode([
            'root' => $this->directory,'actor' => 42,'credential' => $this->credential,'database' => $databaseSessions], JSON_THROW_ON_ERROR));
        $seed->mustRun();
        $session = json_decode($seed->getOutput(), true, 8, JSON_THROW_ON_ERROR)['session_id'];
        return ['actor' => 42,'credential' => $this->credential,'session_id' => $session,'session_path' => $this->directory . '/sessions',
            'page_path' => '/fixture/user_group_admin.php','server' => ['REMOTE_ADDR' => '127.0.0.1','HTTP_HOST' => 'localhost'],
            'cookies' => ['BrowserRealmDenialFixture' => $session],'query' => []];
    }

    private function worker(array $packet, array $environment, bool $import = true): Process
    {
        $options = ['-d','auto_prepend_file='];
        $scenario = hash('sha256', json_encode([$packet,$environment], JSON_THROW_ON_ERROR));
        if (\PHPUnit\Runner\CodeCoverage::instance()->isActive()) {
            if (!is_dir($this->directory . '/coverage')) {
                self::assertTrue(mkdir($this->directory . '/coverage', 0700));
            }
            $options = ['-d','extension=pcov.so','-d','pcov.enabled=1','-d','pcov.directory=/',
                '-d','pcov.exclude=~/(include/vendor|tests)/~','-d','auto_prepend_file=' . dirname(__DIR__) . '/Fixtures/browser-realm-denial-coverage.php'];
            if (self::$childPcovLoaded === null) {
                $probe = new Process([PHP_BINARY,'-r','exit(extension_loaded("pcov") ? 0 : 1);'], timeout: 5);
                $probe->run();
                self::assertContains($probe->getExitCode(), [0,1]);
                self::$childPcovLoaded = $probe->isSuccessful();
            }
            if (self::$childPcovLoaded) {
                array_splice($options, 0, 2);
            }
            $environment += ['KADUPUL_BROWSER_DENIAL_FIXTURE' => $this->directory,'KADUPUL_BROWSER_DENIAL_SCENARIO' => $scenario];
        }
        $child = new Process([PHP_BINARY,...$options,$this->directory . '/bin/legacy-browser-realm-denial.php'], null, $environment, json_encode($packet, JSON_THROW_ON_ERROR), 30);
        $child->run();
        if ($import && \PHPUnit\Runner\CodeCoverage::instance()->isActive()) {
            require_once dirname(__DIR__) . '/Helpers/NativeChildCoverageEvidence.php';
            require_once __DIR__ . '/BrowserRealmDenialCoverageRegistration.php';
            $reports = glob($this->directory . '/coverage/*.coverage');
            self::assertIsArray($reports);
            self::assertCount(1, $reports);
            $r = BrowserRealmDenialCoverageRegistration::class;
            $coverage = \NativeChildCoverageEvidence::load($reports[0], dirname(__DIR__, 2), $r::PRODUCER, $scenario, $r::SOURCES, $r::MARKERS, $r::HITS);
            if (!self::$coverageRejectionsVerified) {
                self::assertSame(count($r::SOURCES) + count($r::MARKERS) + 10, \NativeChildCoverageEvidence::verifyRejections($reports[0], dirname(__DIR__, 2), $r::PRODUCER, $scenario, $r::SOURCES, $r::MARKERS, $r::HITS, 'include/auth.php'));
                self::$coverageRejectionsVerified = true;
            }
            \PHPUnit\Runner\CodeCoverage::instance()->codeCoverage()->merge($coverage);
            unlink($reports[0] . '.json');
            unlink($reports[0]);
        }
        return $child;
    }

    #[DataProvider('backends')]
    public function testActualNativeAdmissionDenialBindingAndPluginExits(bool $databaseSessions): void
    {
        $environment = $this->environment($databaseSessions);
        $packet = $this->packet($environment, $databaseSessions);
        $admitted = $this->worker($packet, $environment);
        self::assertSame(0, $admitted->getExitCode(), $admitted->getErrorOutput());
        self::assertSame(['status' => 'ok','actor' => 42,'outcome' => 'admitted'], json_decode($admitted->getOutput(), true, 8, JSON_THROW_ON_ERROR));
        $this->database->exec("INSERT INTO plugin_hooks(name,hook,`function`,status) VALUES('internal','custom_denied','browser_worker_fixture_custom_denied',1)");
        $this->database->exec('DELETE FROM user_auth_realm WHERE user_id=42 AND realm_id=1');
        foreach (['normal','reskin','exit','binding'] as $mode) {
            $environment = $this->environment($databaseSessions, $mode);
            $packet = $this->packet($environment, $databaseSessions);
            $child = $this->worker($packet, $environment);
            if ($databaseSessions) {
                $stored = $this->database->prepare('SELECT data FROM sessions WHERE id=?');
                $stored->execute([$packet['session_id']]);
                $payload = $stored->fetchColumn();
            } else {
                $payload = file_get_contents($this->directory . '/sessions/sess_' . $packet['session_id']);
            }
            self::assertIsString($payload);
            self::assertTrue(str_contains($payload, 'fixture_denial_hook_count|i:1;'), 'Actual hook executes once before core denial or reskin exit.');
            if ($mode === 'normal') {
                self::assertSame(0, $child->getExitCode(), $child->getErrorOutput());
                self::assertSame(['status' => 'ok','actor' => 42,'outcome' => 'denied'], json_decode($child->getOutput(), true, 8, JSON_THROW_ON_ERROR));
                self::assertSame('', $child->getErrorOutput());
                $unbound = $this->worker(array_replace($packet, ['credential' => str_repeat('f', 64)]), $environment);
                self::assertSame(1, $unbound->getExitCode());
                self::assertSame(['status' => 'failed'], json_decode($unbound->getOutput(), true, 8, JSON_THROW_ON_ERROR));
            } elseif ($mode === 'binding') {
                self::assertSame(1, $child->getExitCode());
                self::assertSame(['status' => 'failed'], json_decode($child->getOutput(), true, 8, JSON_THROW_ON_ERROR));
            } else {
                self::assertStringEndsWith($mode === 'reskin' ? 'fixture-reskin-denial' : 'fixture-hook-exit', $child->getOutput());
                self::assertNull(json_decode($child->getOutput(), true));
                // Same real native code with the descriptor opt-out is the oracle.
                $reference = $this->worker($packet, $this->environment($databaseSessions, $mode, false));
                self::assertSame($reference->getExitCode(), $child->getExitCode());
                self::assertSame($reference->getOutput(), $child->getOutput());
                self::assertSame($reference->getErrorOutput(), $child->getErrorOutput());
            }
            self::assertStringNotContainsString($this->credential, $child->getOutput() . $child->getErrorOutput());
        }
        if (\PHPUnit\Runner\CodeCoverage::instance()->isActive()) {
            foreach (['before','after'] as $stage) {
                $path = $this->directory . '/include/auth.php';
                $original = file_get_contents($path);
                self::assertIsString($original);
                $environment = $this->environment($databaseSessions, $stage === 'after' ? 'tamper' : 'normal');
                $packet = $this->packet($environment, $databaseSessions);
                try {
                    if ($stage === 'before') {
                        self::assertNotFalse(file_put_contents($path, "\n// owned-copy failure injection\n", FILE_APPEND));
                    }
                    $bad = $this->worker($packet, $environment, false);
                    self::assertNotSame(0, $bad->getExitCode());
                    self::assertStringContainsString($stage === 'before' ? 'Additional native source copy differs before measurement' : 'Additional native source copy changed while measuring coverage', $bad->getOutput() . $bad->getErrorOutput());
                    self::assertSame([], glob($this->directory . '/coverage/*.coverage'), 'Unverified copied auth must never produce importable evidence.');
                } finally {
                    file_put_contents($path, $original);
                }
            }
        }

    }
}
