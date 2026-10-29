<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;

/**
 * The queue branch of the Boost master over a real status table: the real
 * counting SQL, the real queue worker and the real child block of
 * poller_boost.php. The message transport and the RRD writer are the only
 * stand-ins, so this does not exercise Messenger or RRDtool.
 */
final class BoostQueueDatabaseTest extends TestCase
{
    private ?PDO $admin = null;
    private string $schema = '';
    /** @var array<string, string> */
    private array $connection = [];

    protected function setUp(): void
    {
        if (!getenv('KADUPUL_TEST_MYSQL_DSN')) {
            self::markTestSkipped('KADUPUL_TEST_MYSQL_DSN is required for database contracts');
        }
        parse_str(str_replace(';', '&', substr((string) getenv('KADUPUL_TEST_MYSQL_DSN'), strlen('mysql:'))), $dsn);
        $prefix = getenv('KADUPUL_TEST_MYSQL_ADMIN_USER') ? 'KADUPUL_TEST_MYSQL_ADMIN_' : 'KADUPUL_TEST_MYSQL_';
        $user = (string) (getenv($prefix . 'USER') ?: 'root');
        $password = (string) (getenv($prefix . 'PASSWORD') ?: '');
        $host = 'mysql:host=' . ($dsn['host'] ?? '127.0.0.1') . ';port=' . ($dsn['port'] ?? '3306') . ';charset=utf8mb4';
        $this->admin = new PDO($host, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->schema = 'boost_queue_' . bin2hex(random_bytes(6));
        $this->admin->exec('CREATE DATABASE `' . $this->schema . '`');
        $this->admin->exec('USE `' . $this->schema . '`');
        // The production definition, so the unique run_partition key is the real one.
        preg_match('/CREATE TABLE poller_output_boost_processes \(.*?\) ENGINE=MEMORY;/s', (string) file_get_contents(dirname(__DIR__, 2) . '/cacti.sql'), $table);
        self::assertNotEmpty($table);
        $this->admin->exec($table[0]);
        $this->admin->exec('CREATE TABLE settings (name VARCHAR(64) PRIMARY KEY, value VARCHAR(255))');
        $this->connection = [
            'BOOST_QUEUE_DSN' => $host . ';dbname=' . $this->schema,
            'BOOST_QUEUE_USER' => $user,
            'BOOST_QUEUE_PASSWORD' => $password,
        ];
    }

    protected function tearDown(): void
    {
        if ($this->admin !== null && $this->schema !== '') {
            $this->admin->exec('DROP DATABASE IF EXISTS `' . $this->schema . '`');
        }
    }

    public function testQueueRunCompletesThroughTheRealWorkerAndChild(): void
    {
        $this->seed('queue');
        $result = $this->master('queue-ok');

        self::assertSame(0, $result['exit']);
        $run = $result['settings']['last_run_id'];
        self::assertSame([[$run, 1, 42], [$run, 2, 42]], $this->rows($result));
        self::assertSame('', $result['settings']['boost_active_run']);
        self::assertStringStartsWith('complete', $result['settings']['boost_poller_status']);
        self::assertGreaterThan(1234, (int) $result['settings']['boost_last_run_time']);
    }

    public function testQueueRunWithoutConsumerFailsAndLeavesNoStatusRows(): void
    {
        $this->seed('queue');
        $result = $this->master('queue-none');

        self::assertSame(1, $result['exit']);
        self::assertSame([], $result['rows']);
        self::assertSame('', $result['settings']['boost_active_run']);
        self::assertStringStartsWith('failed', $result['settings']['boost_poller_status']);
        self::assertSame('1234', $result['settings']['boost_last_run_time']);
        self::assertContains('ERROR: no Boost queue worker reported; run "bin/console messenger:consume boost"', $result['log']);
    }

    public function testWorkerAfterTheTimeoutIsStaleAndNeverWritesStatus(): void
    {
        $this->seed('queue');
        $first = $this->master('queue-none');
        $old = $first['settings']['last_run_id'];

        // The master has cleared boost_active_run, so a late message is refused before any child starts.
        $worker = $this->lateWorker($old);
        self::assertSame('BOOST_PARTITION={"status":"stale"}', $worker);
        self::assertSame(0, (int) $this->admin?->query('SELECT COUNT(*) FROM poller_output_boost_processes')->fetchColumn());
    }

    public function testLateChildOfAnOlderRunDoesNotCountTowardTheNextRun(): void
    {
        $this->seed('queue');
        $first = $this->master('queue-none');
        $old = $first['settings']['last_run_id'];

        // A worker that passed its stale check before the clear starts its child during the next run.
        $this->seed('children');
        $second = $this->master('children-late');

        self::assertSame(0, $second['exit']);
        $rows = $this->rows($second);
        $late = array_values(array_filter($rows, static fn(array $row): bool => $row[0] === $old));
        $current = array_values(array_filter($rows, static fn(array $row): bool => $row[0] !== $old));
        // The late row is in the table, and the next run's verdict ignores it.
        self::assertSame([[$old, 1, 42]], $late);
        self::assertCount(2, $current);
        $new = $current[0][0];
        self::assertSame([[$new, 1, 42], [$new, 2, 42]], $current);
        self::assertStringStartsWith('complete', $second['settings']['boost_poller_status']);
        self::assertSame(84, (int) $this->admin?->query("SELECT SUM(status) FROM poller_output_boost_processes WHERE run_id = '" . $new . "'")->fetchColumn());
    }

    private function seed(string $mode): void
    {
        $settings = ['boost_processing_mode' => $mode, 'boost_parallel' => '2', 'boost_rrd_update_max_runtime' => '1', 'boost_rrd_update_interval' => '120', 'boost_last_run_time' => '1234', 'path_php_binary' => PHP_BINARY];
        $statement = $this->admin?->prepare('REPLACE INTO settings (name, value) VALUES (?, ?)');
        foreach ($settings as $name => $value) {
            $statement?->execute([$name, $value]);
        }
    }

    /** @return array{exit: int, rows: list<array{0: string, 1: int, 2: int}>, settings: array<string, string>, log: list<string>} */
    private function master(string $mode): array
    {
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__) . '/Fixtures/boost-queue-database-native.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            array_merge(getenv(), $this->connection, ['BOOST_QUEUE_MODE' => $mode]),
        );
        $output = (string) stream_get_contents($pipes[1]);
        $error = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        self::assertSame('', $error);
        self::assertSame(1, preg_match('/^RESULT=(.*)$/m', $output, $match), $output);
        $result = json_decode($match[1], true, 512, JSON_THROW_ON_ERROR);
        $result['exit'] = $exit;

        return $result;
    }

    /** Starts the real worker script as a consumer would, after the master finished. */
    private function lateWorker(string $run): string
    {
        $dir = sys_get_temp_dir() . '/boost-queue-late-' . bin2hex(random_bytes(6));
        mkdir($dir . '/bin', 0700, true);
        mkdir($dir . '/include', 0700);
        copy(dirname(__DIR__, 2) . '/bin/legacy-boost-partition.php', $dir . '/bin/legacy-boost-partition.php');
        file_put_contents($dir . '/include/cli_check.php', '<?php require ' . var_export(dirname(__DIR__) . '/Fixtures/boost-queue-database-bootstrap.php', true) . '; $config = array("base_path" => ' . var_export($dir, true) . ');');
        try {
            $process = proc_open([PHP_BINARY, $dir . '/bin/legacy-boost-partition.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, array_merge(getenv(), $this->connection));
            fwrite($pipes[0], json_encode(['run' => $run, 'partition' => 1], JSON_THROW_ON_ERROR));
            fclose($pipes[0]);
            $output = (string) stream_get_contents($pipes[1]);
            stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
        } finally {
            unlink($dir . '/bin/legacy-boost-partition.php');
            unlink($dir . '/include/cli_check.php');
            rmdir($dir . '/bin');
            rmdir($dir . '/include');
            rmdir($dir);
        }

        return trim($output);
    }

    /** @param array{rows: list<array{0: string, 1: int, 2: int}>} $result */
    private function rows(array $result): array
    {
        return array_map(static fn(array $row): array => [$row[0], (int) $row[1], (int) $row[2]], $result['rows']);
    }
}
