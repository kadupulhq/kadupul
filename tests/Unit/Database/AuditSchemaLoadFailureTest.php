<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

test('LTS schema import preserves the actual baseline until verified publication', function (string $case, string $option, int $expected) {
    $root = dirname(__DIR__, 3);
    if (str_starts_with($case, 'discovery-') && array_filter(['/usr/bin/mariadb', '/usr/bin/mysql', '/usr/local/bin/mariadb', '/usr/local/bin/mysql'], 'file_exists')) {
        $this->markTestSkipped('A fixed system client overrides isolated PATH discovery');
    }
    $directory = sys_get_temp_dir() . '/audit native ' . bin2hex(random_bytes(8));
    foreach (array('', '/cli', '/include', '/docs', '/lib') as $part) {
        mkdir($directory . $part, 0700);
    }
    try {
        $path = $directory . '/database.sqlite';
        $db = new PDO('sqlite:' . $path);
        $columns = 'table_name TEXT, table_sequence INTEGER, table_field TEXT, table_type TEXT, table_null TEXT, table_key TEXT, table_default TEXT, table_extra TEXT';
        $indexes = 'idx_table_name TEXT, idx_non_unique INTEGER, idx_key_name TEXT, idx_seq_in_index INTEGER, idx_column_name TEXT, idx_collation TEXT, idx_cardinality INTEGER, idx_sub_part TEXT, idx_packed TEXT, idx_null TEXT, idx_index_type TEXT, idx_comment TEXT';
        $db->exec('CREATE TABLE table_columns (' . $columns . ')');
        $db->exec('CREATE TABLE table_indexes (' . $indexes . ')');
        $db->exec("INSERT INTO table_columns VALUES ('old baseline',1,'id','int','YES','','','')");
        $db->exec("INSERT INTO table_indexes VALUES ('old baseline',0,'PRIMARY',1,'id','A',1,NULL,NULL,'','BTREE','')");
        $db->exec('CREATE TABLE probe (id INTEGER)');
        $db->exec('CREATE TABLE plugin_db_changes (`table` TEXT, `column` TEXT, method TEXT)');
        $schema = 'DROP TABLE IF EXISTS `table_columns`; CREATE TABLE `table_columns` (' . $columns . ');'
            . 'DROP TABLE IF EXISTS `table_indexes`; CREATE TABLE `table_indexes` (' . $indexes . ');';
        if ($case !== 'empty-import') {
            $schema .= "INSERT INTO `table_columns` VALUES ('probe',1,'id','varchar(32)','YES','','','');"
                . "INSERT INTO `table_indexes` VALUES ('other',0,'PRIMARY',1,'id','A',1,NULL,NULL,'','BTREE','');";
        }
        if ($case === 'truncated-import') {
            $candidate = new PDO('sqlite::memory:');
            $candidate->exec($schema);
            $this->assertSame(1, (int) $candidate->query('SELECT COUNT(*) FROM table_columns')->fetchColumn());
            $this->assertSame(1, (int) $candidate->query('SELECT COUNT(*) FROM table_indexes')->fetchColumn());
        } else {
            $schema .= "\n-- Dump completed on 2026-10-01 00:00:00\n";
        }
        file_put_contents($directory . '/docs/audit_schema.sql', $schema);
        if ($case === 'unreadable') {
            unlink($directory . '/docs/audit_schema.sql');
            mkdir($directory . '/docs/audit_schema.sql', 0700);
        }
        if ($case === 'missing') {
            unlink($directory . '/docs/audit_schema.sql');
        }
        if ($case === 'missing-docs') {
            unlink($directory . '/docs/audit_schema.sql');
            rmdir($directory . '/docs');
        }
        $copy = $directory . '/cli/audit_database.php';
        copy($root . '/cli/audit_database.php', $copy);
        copy($root . '/lib/audit.php', $directory . '/lib/audit.php');
        copy($root . '/tests/fixtures/audit-baseline-bootstrap.php', $directory . '/include/cli_check.php');
        $client = $directory . '/client.php';
        file_put_contents($client, str_replace('#!/usr/bin/env php', '#!' . PHP_BINARY, file_get_contents($root . '/tests/fixtures/audit-baseline-client.php')));
        chmod($client, 0700);
        $command = array(PHP_BINARY, '-d', 'error_reporting=24575');
        $command[] = $copy;
        $command[] = $option;
        if (str_starts_with($case, 'upgrade-')) {
            $command[] = '--report';
        }
        $environment = array_merge(getenv(), array('AUDIT_TEST_SQLITE' => $path, 'AUDIT_TEST_CASE' => $case, 'AUDIT_TEST_VERSION' => trim(file_get_contents($root . '/include/cacti_version')), 'CACTI_MYSQL_CLIENT' => $client));
        if (str_starts_with($case, 'discovery-')) {
            unset($environment['CACTI_MYSQL_CLIENT']);
            if ($case === 'discovery-empty') {
                $environment['CACTI_MYSQL_CLIENT'] = '';
            }
            mkdir($directory . '/bin', 0700);
            file_put_contents($directory . '/bin/which', "#!/bin/sh\nexit 0\n");
            chmod($directory . '/bin/which', 0700);
            $environment['PATH'] = $directory . '/bin';
        }
        $process = proc_open($command, array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, $directory . '/cli', $environment);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame($expected, proc_close($process), $error . $output);
        if ($expected === 0) {
            $this->assertSame('', $error);
        } else {
            $this->assertStringContainsString('FATAL:', $output . $error);
        }
        if (str_starts_with($case, 'discovery-')) {
            $this->assertStringContainsString('mysql or mariadb command not found', $output . $error);
        }
        if ($option === '--create' || in_array($case, array('partial-import', 'import-failure', 'empty-import', 'swap-failure', 'missing'), true)) {
            $old = $expected !== 0 && !str_starts_with($case, 'cleanup-');
            $this->assertSame($old ? 'old baseline' : 'probe', $db->query('SELECT table_name FROM table_columns')->fetchColumn());
            $this->assertSame($old ? 'old baseline' : 'other', $db->query('SELECT idx_table_name FROM table_indexes')->fetchColumn());
        }
        $mutations = is_file($directory . '/db-mutations') ? file_get_contents($directory . '/db-mutations') : '';
        $this->assertStringNotContainsString('TRUNCATE', $mutations);
        $this->assertStringNotContainsString('ALTER TABLE', $mutations);
        if ($expected !== 0 && $case !== 'swap-failure') {
            $this->assertStringNotContainsString('RENAME TABLE', $mutations);
        }
        if (is_file($directory . '/credential-path')) {
            $this->assertFileDoesNotExist(file_get_contents($directory . '/credential-path'));
        }
        $this->assertSame([], $db->query("SELECT name FROM sqlite_master WHERE name LIKE 'audit_%'")->fetchAll(PDO::FETCH_COLUMN));
    } finally {
        $remove = function ($path) use (&$remove) {
            if (is_dir($path)) {
                foreach (scandir($path) as $entry) {
                    if ($entry !== '.' && $entry !== '..') {
                        $remove($path . '/' . $entry);
                    }
                } rmdir($path);
            } else {
                unlink($path);
            }
        };
        $remove($directory);
    }
})->with([
    'missing' => ['missing', '--create', 1],
    'unreadable' => ['unreadable', '--create', 1],
    'truncated-import' => ['truncated-import', '--create', 1],
    'partial-import' => ['partial-import', '--create', 1],
    'partial-success' => ['partial-success', '--create', 1],
    'import-failure' => ['import-failure', '--create', 1],
    'empty-import' => ['empty-import', '--create', 1],
    'swap-failure' => ['swap-failure', '--create', 1],
    'discovery-unset' => ['discovery-unset', '--create', 1],
    'discovery-empty' => ['discovery-empty', '--create', 1],
    'valid' => ['valid', '--create', 0],
]);
