<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Platform\Infrastructure\Legacy\ConsoleInstallation;
use PHPUnit\Framework\TestCase;

final class ConsoleInstallationTest extends TestCase
{
    public function testBothNativeQueuesAndFailedReadsKeepTheSelectedCollectorLocal(): void
    {
        $dsn = getenv('KADUPUL_TEST_MYSQL_DSN');
        if (!is_string($dsn) || $dsn === '') {
            $this->markTestSkipped('A disposable MariaDB connection is required.');
        }
        $user = getenv('KADUPUL_TEST_MYSQL_ADMIN_USER') ?: 'root';
        $password = getenv('KADUPUL_TEST_MYSQL_ADMIN_PASSWORD') ?: '';
        $database = new \PDO($dsn, $user, $password, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        preg_match_all('/(\w+)=([^;]*)/', substr($dsn, 6), $pairs, PREG_SET_ORDER);
        $options = array_column($pairs, 2, 1);
        $schemas = [];
        $targets = [];
        $source = file_get_contents(dirname(__DIR__, 2) . '/cacti.sql');
        $this->assertIsString($source);
        try {
            foreach (['local', 'main'] as $role) {
                $schema = 'console_selection_' . bin2hex(random_bytes(8));
                $database->exec('CREATE DATABASE `' . $schema . '`');
                $schemas[] = $schema;
                $targets[$role] = ['host' => $options['host'], 'port' => (int) ($options['port'] ?? 3306),
                    'database' => $schema, 'username' => $user, 'password' => $password, 'ssl' => false];
            }
            $database->exec('USE `' . $targets['local']['database'] . '`');
            foreach (['poller_output', 'poller_output_boost', 'version'] as $table) {
                $this->assertSame(1, preg_match('/CREATE TABLE `?' . $table . '`? \([^;]*;/s', $source, $match));
                $database->exec($match[0]);
            }
            $statement = $database->prepare('INSERT INTO version (cacti) VALUES (?)');
            $version = trim(file_get_contents(dirname(__DIR__, 2) . '/include/cacti_version'));
            $this->assertTrue($statement->execute([$version]));
            $values = $targets['local'] + ['database_sessions' => true];
            foreach (['online', 'boost', 'output', 'unreadable-boost', 'unreadable-output', 'offline'] as $mode) {
                foreach (['poller_output', 'poller_output_boost'] as $table) {
                    $database->exec('DROP TABLE IF EXISTS `' . $table . '`');
                    $this->assertSame(1, preg_match('/CREATE TABLE `?' . $table . '`? \([^;]*;/s', $source, $match));
                    $database->exec($match[0]);
                }
                if ($mode === 'boost' || $mode === 'output') {
                    $table = $mode === 'boost' ? 'poller_output_boost' : 'poller_output';
                    $database->exec('INSERT INTO `' . $table . '` (local_data_id, rrd_name, time, output) VALUES (1, \'fixture\', \'2026-01-01 00:00:00\', \'1\')');
                } elseif (str_starts_with($mode, 'unreadable-')) {
                    $table = $mode === 'unreadable-boost' ? 'poller_output_boost' : 'poller_output';
                    $database->exec('DROP TABLE `' . $table . '`');
                }
                $selected = ConsoleInstallation::select($values, ['collector_id' => 2, 'local' => $targets['local'], 'main' => $mode === 'offline' ? null : $targets['main']]);
                $this->assertSame($mode === 'online' ? 'online' : ($mode === 'offline' ? 'offline' : 'recovery'), $selected['connection_status'], $mode);
                $this->assertSame($targets[$mode === 'online' ? 'main' : 'local']['database'], $selected['database'], $mode);
                $this->assertSame($mode === 'online', $selected['database_sessions'], $mode);
                $this->assertSame($version, $selected['console_schema_version'], $mode);
            }
        } finally {
            foreach ($schemas as $schema) {
                $database->exec('DROP DATABASE `' . $schema . '`');
            }
        }
    }
}
