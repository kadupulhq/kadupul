<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;

/**
 * A database restart in the middle of a Boost child drops its session and
 * empties the MEMORY work list. The child must then report failure, and no
 * sample may leave the archive unless its RRD update was sent.
 */
final class BoostConnectionLossDatabaseTest extends TestCase
{
    private const ARCHIVE = 'poller_output_boost_arch_1700000000';

    private ?PDO $admin = null;

    private string $schema = '';

    private string $tree = '';

    protected function setUp(): void
    {
        if (!getenv('KADUPUL_TEST_MYSQL_DSN')) {
            self::markTestSkipped('KADUPUL_TEST_MYSQL_DSN is required for database contracts');
        }
        parse_str(str_replace(';', '&', substr((string) getenv('KADUPUL_TEST_MYSQL_DSN'), strlen('mysql:'))), $dsn);
        $prefix = getenv('KADUPUL_TEST_MYSQL_ADMIN_USER') ? 'KADUPUL_TEST_MYSQL_ADMIN_' : 'KADUPUL_TEST_MYSQL_';
        $this->admin = new PDO('mysql:host=' . $dsn['host'] . ';port=' . ($dsn['port'] ?? '3306'), getenv($prefix . 'USER') ?: 'root', getenv($prefix . 'PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->schema = 'kboost_loss_' . bin2hex(random_bytes(5));
        $this->admin->exec("CREATE DATABASE `{$this->schema}`");
        $this->admin->exec("USE `{$this->schema}`");
        $this->seed();

        $this->tree = sys_get_temp_dir() . '/kadupul-boost-loss-' . bin2hex(random_bytes(8));
        mkdir($this->tree . '/include', 0700, true);
        mkdir($this->tree . '/lib', 0700);
        mkdir($this->tree . '/rra', 0700);

        foreach ([42, 43, 44] as $id) {
            touch($this->tree . "/rra/$id.rrd");
            $this->admin->exec("INSERT INTO data_local (id, data_template_id) VALUES ($id, 1)");
            $this->admin->exec("INSERT INTO data_template_rrd (id, local_data_id, data_template_id, data_source_name) VALUES ($id, $id, 1, 'value')");
            $this->admin->exec("INSERT INTO graph_templates_item (local_graph_id, task_item_id) VALUES ($id, $id)");
            $this->admin->prepare("INSERT INTO poller_item (local_data_id, rrd_name, rrd_path) VALUES (?, 'value', ?)")
                ->execute([$id, $this->tree . "/rra/$id.rrd"]);
            foreach ([1699999800, 1699999860] as $time) {
                $this->admin->exec('INSERT INTO ' . self::ARCHIVE . " (local_data_id, rrd_name, time, output) VALUES ($id, 'value', FROM_UNIXTIME($time), '$id')");
            }
            $this->admin->exec("INSERT INTO poller_output_boost_local_data_ids (local_data_id, process_handler) VALUES ($id, 1)");
        }
    }

    protected function tearDown(): void
    {
        $this->admin?->exec("DROP DATABASE IF EXISTS `{$this->schema}`");
        $this->admin = null;
        if ($this->tree !== '' && is_dir($this->tree)) {
            $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->tree, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($entries as $entry) {
                $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
            rmdir($this->tree);
        }
    }

    public function testASessionLostMidRunFailsTheChildAndKeepsUnwrittenSamples(): void
    {
        $run = $this->runChild();

        // The MEMORY work list came back empty, so the child saw no more work.
        self::assertSame(-1, $run['result'], 'a child that lost its session must not report success');

        $written = [];
        foreach ($run['updates'] as $command) {
            self::assertMatchesRegularExpression('~/rra/(\d+)\.rrd~', $command);
            preg_match('~/rra/(\d+)\.rrd~', $command, $match);
            $written[(int) $match[1]] = true;
        }
        $left = array_map('intval', $this->admin->query('SELECT local_data_id FROM ' . self::ARCHIVE . ' ORDER BY local_data_id, time')->fetchAll(PDO::FETCH_COLUMN));
        foreach ([42, 43, 44] as $id) {
            $remaining = count(array_keys($left, $id, true));
            if (isset($written[$id])) {
                self::assertSame(0, $remaining, "data source $id was written, so its samples are acknowledged");
            } else {
                self::assertSame(2, $remaining, "data source $id was not written, so its samples must stay queued");
            }
        }
        self::assertArrayHasKey(42, $written);
        self::assertArrayNotHasKey(44, $written);
    }

    /** @return array{result: int, updates: list<string>, logged: list<string>} */
    private function runChild(): array
    {
        $root = dirname(__DIR__, 2);
        $options = json_encode(['schema' => $this->schema, 'archive' => self::ARCHIVE, 'tree' => $this->tree], JSON_THROW_ON_ERROR);
        $process = proc_open([PHP_BINARY, '-d', 'error_reporting=-1', '-d', 'display_errors=stderr', $root . '/tests/Fixtures/boost-connection-loss-native.php', $options], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->tree);
        self::assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $stderr . $stdout);
        self::assertSame('', $stderr);

        return json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
    }

    /** cacti.sql as the installer reads it, so the work list keeps its MEMORY engine. */
    private function seed(): void
    {
        $delimiter = ';';
        $buffer = '';
        foreach (file(dirname(__DIR__, 2) . '/cacti.sql') ?: [] as $line) {
            if (preg_match('/^DELIMITER (\S+)\s*$/', trim($line), $matches)) {
                $delimiter = $matches[1];
                continue;
            }
            if (str_starts_with(ltrim($line), '--') || trim($line) === '') {
                continue;
            }
            $buffer .= $line;
            if (str_ends_with(rtrim($buffer), $delimiter)) {
                $this->admin->exec(substr(rtrim($buffer), 0, -strlen($delimiter)));
                $buffer = '';
            }
        }
        self::assertSame('MEMORY', $this->admin->query("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = SCHEMA() AND TABLE_NAME = 'poller_output_boost_local_data_ids'")->fetchColumn());
        $this->admin->exec('CREATE TABLE ' . self::ARCHIVE . ' LIKE poller_output_boost');
    }
}
