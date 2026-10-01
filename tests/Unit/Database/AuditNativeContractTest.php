<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;

final class AuditNativeContractTest extends TestCase
{
    /** @dataProvider cases */
    public function testCompleteCliPreservesBaselineAndReportsFailures(string $case, string $option, int $expected): void
    {
        $root = dirname(__DIR__, 3);
        $directory = sys_get_temp_dir() . '/audit native ' . bin2hex(random_bytes(8));
        foreach (array('', '/cli', '/include', '/docs') as $part) {
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
            if (str_starts_with($case, 'report-')) {
                $type = $case === 'report-type' ? 'varchar(32)' : 'int';
                $schema = 'DROP TABLE IF EXISTS `table_columns`; CREATE TABLE `table_columns` (' . $columns . ');DROP TABLE IF EXISTS `table_indexes`; CREATE TABLE `table_indexes` (' . $indexes . ');'
                    . "INSERT INTO `table_columns` VALUES ('probe',1,'id','$type','YES','','','');";
                if ($case === 'report-missing-column') {
                    $schema .= "INSERT INTO `table_columns` VALUES ('probe',2,'value','text','YES','','','');";
                }
                if ($case === 'report-unexpected-column' || $case === 'report-index-reordered') {
                    $db->exec('ALTER TABLE probe ADD COLUMN value TEXT');
                }
                if ($case === 'report-no-baseline') {
                    $schema = str_replace("'probe'", "'unknown'", $schema);
                }
                if (str_contains($case, 'index')) {
                    $key = $case === 'report-primary-index' ? 'PRIMARY' : 'probe_key';
                    $unique = $case === 'report-unique-index' || $case === 'report-primary-index' ? 0 : 1;
                    $schema .= "INSERT INTO `table_indexes` VALUES ('probe',$unique,'$key',1,'id','A',1,NULL,NULL,'','BTREE','');";
                    if ($case === 'report-index-reordered') {
                        $schema .= "INSERT INTO `table_indexes` VALUES ('probe',1,'probe_key',2,'value','A',1,NULL,NULL,'','BTREE','');";
                        $db->exec('CREATE INDEX probe_key ON probe(value,id)');
                    } elseif ($case === 'report-index-clean') {
                        $db->exec('CREATE INDEX probe_key ON probe(id)');
                    } elseif ($case === 'report-unexpected-index') {
                        $db->exec('CREATE INDEX unexpected_key ON probe(id)');
                    }
                } else {
                    $schema .= "INSERT INTO `table_indexes` VALUES ('other',0,'PRIMARY',1,'id','A',1,NULL,NULL,'','BTREE','');";
                }
            }
            if ($case === 'load-index-failure') {
                $db->exec('CREATE INDEX probe_index ON probe(id)');
            }
            if ($case === 'truncated-import') {
                $candidate = new PDO('sqlite::memory:');
                $candidate->exec($schema);
                self::assertSame(1, (int) $candidate->query('SELECT COUNT(*) FROM table_columns')->fetchColumn());
                self::assertSame(1, (int) $candidate->query('SELECT COUNT(*) FROM table_indexes')->fetchColumn());
            } else {
                $schema .= "\n-- Dump completed on 2026-10-01 00:00:00\n";
            }
            file_put_contents($directory . '/docs/audit_schema.sql', $schema);
            if ($case === 'missing') {
                unlink($directory . '/docs/audit_schema.sql');
            }
            if ($case === 'missing-docs') {
                unlink($directory . '/docs/audit_schema.sql');
                rmdir($directory . '/docs');
            }
            if (str_starts_with($case, 'upgrade-')) {
                $db->exec('CREATE TABLE plugin_config (directory TEXT, version TEXT, status INTEGER)');
                $db->exec('CREATE TABLE plugin_hooks (name TEXT)');
                $db->exec('CREATE TABLE plugin_realms (plugin TEXT)');
                $db->exec('ALTER TABLE plugin_db_changes ADD COLUMN plugin TEXT');
                $db->exec('CREATE TABLE upgrade_events (name TEXT)');
                mkdir($directory . '/plugins', 0700);
                foreach (array('standard', 'alternate', 'current', 'nosetup', 'legacy', 'ghost') as $plugin) {
                    $db->exec("INSERT INTO plugin_config VALUES ('$plugin','1',1)");
                    if ($plugin === 'ghost') {
                        $db->exec("INSERT INTO plugin_hooks VALUES ('ghost')");
                        $db->exec("INSERT INTO plugin_realms VALUES ('ghost')");
                        $db->exec("INSERT INTO plugin_db_changes (plugin) VALUES ('ghost')");
                        continue;
                    }
                    mkdir($directory . '/plugins/' . $plugin, 0700);
                    if ($plugin !== 'legacy') {
                        file_put_contents($directory . '/plugins/' . $plugin . '/INFO', "[info]\nversion=" . ($plugin === 'current' ? '1' : '2') . "\n");
                    }
                    if ($plugin !== 'nosetup') {
                        $setup = '<?php';
                        if ($plugin === 'standard') {
                            $setup .= ' function plugin_standard_upgrade() { $GLOBALS["db"]->exec("INSERT INTO upgrade_events VALUES (\'standard\')"); }';
                        } elseif ($plugin === 'alternate') {
                            $setup .= ' function alternate_setup_table_new($upgrade) { $GLOBALS["db"]->exec("INSERT INTO upgrade_events VALUES (\'setup\')"); } function alternate_upgrade_database($upgrade) { $GLOBALS["db"]->exec("INSERT INTO upgrade_events VALUES (\'alternate\')"); }';
                        }
                        file_put_contents($directory . '/plugins/' . $plugin . '/setup.php', $setup);
                    }
                }
                $failure = $case === 'upgrade-failure' ? 1 : 0;
                file_put_contents($directory . '/cli/upgrade_database.php', '<?php echo "core upgrade reached"; exit(' . $failure . ');');
                file_put_contents($directory . '/plugins/standard/database_upgrade.php', '<?php file_put_contents(__DIR__ . "/script-args", json_encode($argv)); echo "plugin upgrade reached"; exit(' . $failure . ');');
            }
            $copy = $directory . '/cli/audit_database.php';
            copy($root . '/cli/audit_database.php', $copy);
            copy($root . '/tests/Fixtures/audit-native-bootstrap.php', $directory . '/include/cli_check.php');
            $client = $directory . '/client.php';
            file_put_contents($client, str_replace('#!/usr/bin/env php', '#!' . PHP_BINARY, file_get_contents($root . '/tests/Fixtures/audit-native-client.php')));
            chmod($client, 0700);
            $coverage = $this->getTestResultObject()->getCodeCoverage();
            $command = array(PHP_BINARY, '-d', 'opcache.jit=0', '-d', 'opcache.jit_buffer_size=0', '-d', 'error_reporting=24575', '-d', 'pcov.directory=/');
            if ($coverage !== null) {
                $bootstrap = '<?php define("RRD_TEST_COVERAGE_DIRECTORY", __DIR__); define("RRD_TEST_CLI_COVERAGE_COPY", ' . var_export($copy, true) . '); define("RRD_TEST_CLI_COVERAGE_SOURCE", ' . var_export($root . '/cli/audit_database.php', true) . '); require ' . var_export($root . '/tests/Fixtures/rrd-process-coverage.php', true) . ';';
                file_put_contents($directory . '/coverage.php', $bootstrap);
                $command[] = '-d';
                $command[] = 'auto_prepend_file=' . $directory . '/coverage.php';
            }
            $command[] = $copy;
            $command[] = $option;
            if (str_starts_with($case, 'upgrade-')) {
                $command[] = '--report';
            }
            $environment = array_merge(getenv(), array('AUDIT_TEST_SQLITE' => $path, 'AUDIT_TEST_CASE' => $case, 'AUDIT_TEST_VERSION' => trim(file_get_contents($root . '/include/cacti_version')), 'CACTI_MYSQL_CLIENT' => $client));
            $process = proc_open($command, array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, $directory . '/cli', $environment);
            $output = stream_get_contents($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame($expected, proc_close($process), $error . $output);
            self::assertSame('', $error);
            if ($option === '--create' || in_array($case, array('partial-import', 'import-failure', 'empty-import', 'swap-failure', 'missing'), true)) {
                $old = $expected !== 0;
                self::assertSame($old ? 'old baseline' : 'probe', $db->query('SELECT table_name FROM table_columns')->fetchColumn());
                self::assertSame($old ? 'old baseline' : 'other', $db->query('SELECT idx_table_name FROM table_indexes')->fetchColumn());
            }
            if ($case === 'repair-failure') {
                self::assertStringContainsString('0 Alters succeeded and 1 failed', $output);
            }
            if ($case === 'repair-success') {
                self::assertStringContainsString('All 1 Alters succeeded', $output);
            }
            if ($option === '--alters') {
                self::assertStringContainsString('Proposed Alter', $output);
            }
            if (str_starts_with($case, 'load-')) {
                self::assertStringContainsString('Failed to populate Audit Schema', $output);
                self::assertFileDoesNotExist($directory . '/dump-called');
            } elseif ($option === '--load') {
                self::assertStringContainsString($case === 'missing-docs' ? 'Docs directory does not exist' : 'Finished Creating Audit Schema', $output);
            }
            $messages = array('report-type' => 'ERROR Col:', 'report-missing-column' => 'is missing', 'report-unexpected-column' => 'Plugin possible', 'report-no-baseline' => 'Does not Exist', 'report-missing-index' => 'ERROR Index:', 'report-unique-index' => 'ERROR Index:', 'report-primary-index' => 'ERROR Index:', 'report-index-reordered' => 'resequenced columns', 'report-unexpected-index' => 'does not exist in default Kadupul');
            if (isset($messages[$case])) {
                self::assertStringContainsString($messages[$case], $output);
            }
            if ($case === 'report-clean' || $case === 'report-index-clean') {
                self::assertStringContainsString('Audit was clean', $output);
            }
            if (str_starts_with($case, 'upgrade-')) {
                self::assertSame(array('alternate', 'setup', 'standard'), $db->query('SELECT name FROM upgrade_events ORDER BY name')->fetchAll(PDO::FETCH_COLUMN));
                self::assertSame(0, (int) $db->query("SELECT COUNT(*) FROM plugin_config WHERE directory='ghost'")->fetchColumn());
                foreach (array('plugin_hooks' => 'name', 'plugin_realms' => 'plugin', 'plugin_db_changes' => 'plugin') as $table => $column) {
                    self::assertSame(0, (int) $db->query("SELECT COUNT(*) FROM $table WHERE $column='ghost'")->fetchColumn());
                }
                self::assertSame(array('legacy', false), json_decode(trim(file_get_contents($directory . '/uninstall-log')), true));
                $args = json_decode(file_get_contents($directory . '/plugins/standard/script-args'), true);
                self::assertSame(array('--type=large', '--force-ver=1'), array_slice($args, 1));
                $log = file_get_contents($directory . '/upgrade-log');
                self::assertStringContainsString($case === 'upgrade-failure' ? 'Kadupul Upgrade Encountered Errors' : 'Kadupul Upgrade succeeded', $log);
                self::assertStringContainsString($case === 'upgrade-failure' ? 'Plugin standard Upgrade Encountered Errors' : 'Plugin standard Upgrade Succeeded', $log);
                self::assertStringContainsString('lacks a setup file', $log);
                self::assertStringContainsString('Does not Require Upgrade', $log);
            }
            $mutations = is_file($directory . '/db-mutations') ? file_get_contents($directory . '/db-mutations') : '';
            if ($option === '--alters') {
                self::assertStringNotContainsString('ALTER TABLE `probe`', $mutations);
            }
            if ($expected !== 0 && $option !== '--load') {
                self::assertDoesNotMatchRegularExpression('/(?:TRUNCATE|DROP TABLE(?: IF EXISTS)?|DELETE FROM)\s+`?table_(?:columns|indexes)/', $mutations);
            }
            self::assertSame(array(), $db->query("SELECT name FROM sqlite_master WHERE name LIKE 'audit_%'")->fetchAll(PDO::FETCH_COLUMN));
            if ($coverage !== null) {
                foreach (glob($directory . '/*.coverage') as $report) {
                    $coverage->merge(unserialize(file_get_contents($report)));
                }
            }
        } finally {
            $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($entries as $entry) {
                $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
            rmdir($directory);
        }
    }

    public static function cases(): array
    {
        $reports = array_map(static fn($case) => array($case, '--report', 0), array('report-type', 'report-missing-column', 'report-unexpected-column', 'report-no-baseline', 'report-missing-index', 'report-unique-index', 'report-primary-index', 'report-index-reordered', 'report-unexpected-index', 'report-clean', 'report-index-clean'));
        return array_merge($reports, array(array('valid', '--create', 0), array('truncated-import', '--create', 1), array('partial-success', '--create', 1), array('load-truncate-columns', '--load', 1), array('load-truncate-indexes', '--load', 1), array('load-column-failure', '--load', 1), array('load-index-failure', '--load', 1), array('partial-import', '--repair', 1), array('import-failure', '--repair', 1), array('empty-import', '--create', 1), array('swap-failure', '--create', 1), array('missing', '--create', 1), array('create-table_columns-failure', '--create', 1), array('create-table_indexes-failure', '--create', 1), array('repair-failure', '--repair', 1), array('repair-success', '--repair', 0), array('plan', '--alters', 0), array('dump-failure', '--load', 1), array('dump-success', '--load', 0), array('missing-docs', '--load', 1), array('upgrade-success', '--upgrade', 0), array('upgrade-failure', '--upgrade', 0), array('version', '--version', 0), array('help', '--help', 0)));
    }
}
