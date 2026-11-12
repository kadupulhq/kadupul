<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\DataInput\Domain\DataQuerySettings;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/**
 * Runs the unchanged bin/legacy-data-query.php, with the real include/global.php
 * bootstrap and lib/ code, against a schema loaded from cacti.sql.
 */
final class DataQueryWorkerNativeTest extends TestCase
{
    private const string XML = '<path_cacti>/resource/snmp_queries/interface.xml';

    private static ?\PDO $administrator = null;
    private static string $candidate = '';
    private static string $directory = '';
    private static string $dsn = '';
    private ?\PDO $db = null;
    private string $schema = '';

    public static function setUpBeforeClass(): void
    {
        $dsn = getenv('KADUPUL_TEST_MYSQL_DSN');
        if (!is_string($dsn) || !str_starts_with($dsn, 'mysql:')) {
            return;
        }
        self::$dsn = $dsn;
        self::$administrator = new \PDO($dsn, getenv('KADUPUL_TEST_MYSQL_USER') ?: 'root', getenv('KADUPUL_TEST_MYSQL_PASSWORD') ?: '', [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $root = dirname(__DIR__, 2);
        self::$directory = sys_get_temp_dir() . '/data-query-native-' . bin2hex(random_bytes(8));
        self::$candidate = self::$directory . '/source';
        $filesystem = new Filesystem();
        $tracked = new Process(['git', 'ls-files', '-z', '--cached', '--others', '--exclude-standard'], $root);
        self::assertSame(0, $tracked->run(), $tracked->getErrorOutput());
        foreach (explode("\0", trim($tracked->getOutput(), "\0")) as $path) {
            if (is_file($root . '/' . $path) && !is_link($root . '/' . $path)) {
                $filesystem->copy($root . '/' . $path, self::$candidate . '/' . $path, true);
            }
        }
        // The worker resolves include/ and lib/ through __DIR__, so they are
        // copies; the vendored classes are the same source either way.
        $filesystem->symlink($root . '/include/vendor', self::$candidate . '/include/vendor');
        $filesystem->mkdir([self::$candidate . '/log', self::$candidate . '/cache', self::$candidate . '/rra'], 0700);
        $filesystem->copy(self::$candidate . '/tests/Fixtures/data-query-runtime-config.php', self::$candidate . '/include/config.php', true);
        self::assertSame(hash_file('sha256', $root . '/bin/legacy-data-query.php'), hash_file('sha256', self::$candidate . '/bin/legacy-data-query.php'));
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$directory !== '') {
            (new Filesystem())->remove(self::$directory);
        }
        self::$administrator = null;
    }

    protected function setUp(): void
    {
        if (self::$administrator === null) {
            self::markTestSkipped('Native MySQL/MariaDB DSN is required.');
        }
        $this->schema = 'kadupul_data_query_' . bin2hex(random_bytes(8));
        self::$administrator->exec('CREATE DATABASE `' . $this->schema . '`');
        $this->db = new \PDO(
            preg_replace('/dbname=[^;]*/', '', self::$dsn) . ';dbname=' . $this->schema,
            getenv('KADUPUL_TEST_MYSQL_USER') ?: 'root',
            getenv('KADUPUL_TEST_MYSQL_PASSWORD') ?: '',
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::MYSQL_ATTR_MULTI_STATEMENTS => true, \PDO::ATTR_STRINGIFY_FETCHES => true]
        );
        // Legacy install DDL contains historical zero timestamp defaults.
        $this->db->exec("SET SESSION sql_mode = ''");
        foreach (self::statements((string) file_get_contents(dirname(__DIR__, 2) . '/cacti.sql')) as $statement) {
            $this->db->exec($statement);
        }
        $this->db->exec("INSERT INTO user_auth (id, username, password, realm, full_name, enabled) VALUES (9, 'operator', '', 0, 'Operator', 'on');
            INSERT INTO user_auth_realm (user_id, realm_id) VALUES (9, 8), (9, 13);
            INSERT INTO user_auth (id, username, password, realm, full_name, enabled) VALUES (10, 'templates', '', 0, 'Templates', 'on');
            INSERT INTO user_auth_realm (user_id, realm_id) VALUES (10, 8), (10, 2);
            INSERT INTO data_input (id, hash, name, input_string, type_id) VALUES (90, 'f0000000000000000000000000000090', 'Fixture Script Query', '', 4);
            INSERT INTO snmp_query (id, hash, xml_path, name, description, graph_template_id, data_input_id)
                VALUES (20, 'e0000000000000000000000000000020', '" . self::XML . "', 'Fixture <query>', 'Stored', 0, 2);
            INSERT INTO data_template (id, hash, name) VALUES (30, 'd0000000000000000000000000000030', 'Fixture template');
            INSERT INTO data_template_data (id, local_data_template_data_id, local_data_id, data_template_id, data_input_id, name)
                VALUES (300, 0, 0, 30, 2, 'Fixture template'), (301, 300, 40, 30, 2, 'Fixture source');
            INSERT INTO data_local (id, data_template_id, host_id, snmp_query_id, snmp_index) VALUES (40, 30, 0, 20, '1');
            INSERT INTO data_template (id, hash, name) VALUES (31, 'd0000000000000000000000000000031', 'Unrelated template');
            INSERT INTO data_template_data (id, local_data_template_data_id, local_data_id, data_template_id, data_input_id, name)
                VALUES (310, 0, 0, 31, 2, 'Unrelated template');");
    }

    protected function tearDown(): void
    {
        if ($this->schema !== '') {
            self::$administrator?->exec('DROP DATABASE IF EXISTS `' . $this->schema . '`');
        }
        $this->db = null;
    }

    public function testFindReturnsTheStoredRowRevisionAndQueryInputMethods(): void
    {
        [$status, $answer] = $this->worker(9, 'find', 20, []);
        self::assertSame(0, $status);
        self::assertSame('ok', $answer['status']);
        $row = ['id' => 20, 'name' => 'Fixture <query>', 'description' => 'Stored', 'xml_path' => self::XML, 'data_input_id' => 2];
        self::assertSame($row, $answer['result']['query']);
        self::assertSame(DataQuerySettings::revision($row), $answer['result']['revision']);
        self::assertSame('found', $answer['result']['xml']);
        // Only Script Query, SNMP Query and Script Server Query methods, by name.
        self::assertSame(['90' => 'Fixture Script Query', '2' => 'Get SNMP Data (Indexed)'], array_intersect_key($answer['result']['inputs'], ['2' => true, '90' => true]));
        self::assertArrayNotHasKey('1', $answer['result']['inputs']);
        self::assertSame('not_found', $this->worker(9, 'find', 21, [])[1]['status']);
    }

    public function testASaveChangesTheMethodOfItsTemplatesAndSourcesInOneTransaction(): void
    {
        $before = $this->crc();
        [$status, $answer] = $this->worker(9, 'save', 20, $this->payload(['name' => ' Renamed ', 'data_input_id' => 90]));
        self::assertSame(0, $status, json_encode($answer, JSON_THROW_ON_ERROR));
        self::assertSame(['id' => 20, 'partial' => false], $answer['result']);
        self::assertSame(
            ['Renamed', 'Stored', self::XML, '90', 'e0000000000000000000000000000020'],
            array_values($this->db->query('SELECT name, description, xml_path, data_input_id, hash FROM snmp_query WHERE id = 20')->fetch(\PDO::FETCH_ASSOC))
        );
        self::assertSame(['300' => '90', '301' => '90', '310' => '2'], $this->db->query('SELECT id, data_input_id FROM data_template_data WHERE id IN (300, 301, 310) ORDER BY id')->fetchAll(\PDO::FETCH_KEY_PAIR));
        self::assertNotSame($before, $this->crc());
    }

    public function testAFailureAfterThePushOutLeavesThePrimaryUnchanged(): void
    {
        $before = $this->state();
        // The replication checksum is the last write before the commit.
        $this->db->exec("CREATE TRIGGER fail_crc BEFORE INSERT ON settings FOR EACH ROW
            IF NEW.name = 'poller_replicate_snmp_query_crc' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'injected'; END IF");
        [$status, $answer] = $this->worker(9, 'save', 20, $this->payload(['name' => 'Renamed', 'data_input_id' => 90]));
        self::assertSame([1, 'failed'], [$status, $answer['status']]);
        self::assertSame($before, $this->state());
    }

    public function testRefusalsWriteNothing(): void
    {
        $before = $this->state();
        $cases = [
            'stale revision' => [9, $this->payload([], 'stale'), 'conflict'],
            'no Data Queries realm' => [10, $this->payload(), 'denied'],
            'unknown actor' => [77, $this->payload(), 'denied'],
            'XML file outside resource' => [9, $this->payload(['xml_path' => '<path_cacti>/include/config.php']), 'invalid'],
            'missing XML file' => [9, $this->payload(['xml_path' => '<path_cacti>/resource/snmp_queries/missing.xml']), 'invalid'],
            'climbing out of resource' => [9, $this->payload(['xml_path' => '<path_cacti>/resource/../cacti.sql']), 'invalid'],
            'not a query input method' => [9, $this->payload(['data_input_id' => 1]), 'invalid'],
            'name too long' => [9, $this->payload(['name' => str_repeat('n', 101)]), 'invalid'],
            'name not text' => [9, $this->payload(['name' => 7]), 'invalid'],
        ];
        foreach ($cases as $name => [$actor, $payload, $expected]) {
            [$status, $answer] = $this->worker($actor, 'save', 20, $payload);
            self::assertSame([1, $expected], [$status, $answer['status']], $name);
            self::assertSame($before, $this->state(), $name);
        }
        [, $answer] = $this->worker(9, 'save', 20, $this->payload(['xml_path' => '/etc/hosts']));
        self::assertSame('xml_path', $answer['result']['field']);
        self::assertSame('invalid', $this->worker(9, 'drop', 20, [])[1]['status']);
        self::assertSame('not_found', $this->worker(9, 'save', 21, $this->payload())[1]['status']);
        self::assertSame($before, $this->state());
    }

    public function testCreatingAssignsAHashAndSkipsThePushOut(): void
    {
        $templates = $this->db->query('SELECT id, data_input_id FROM data_template_data ORDER BY id')->fetchAll(\PDO::FETCH_KEY_PAIR);
        [$status, $answer] = $this->worker(9, 'save', 0, ['settings' => ['name' => 'New', 'description' => '', 'xml_path' => self::XML, 'data_input_id' => 90], 'revision' => '']);
        self::assertSame(0, $status, json_encode($answer, JSON_THROW_ON_ERROR));
        $id = $answer['result']['id'];
        self::assertGreaterThan(20, $id);
        $row = $this->db->query('SELECT hash, name, data_input_id FROM snmp_query WHERE id = ' . (int) $id)->fetch(\PDO::FETCH_ASSOC);
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $row['hash']);
        self::assertSame(['New', '90'], [$row['name'], $row['data_input_id']]);
        self::assertSame($templates, $this->db->query('SELECT id, data_input_id FROM data_template_data ORDER BY id')->fetchAll(\PDO::FETCH_KEY_PAIR));
    }

    /**
     * Splits an install script the way the mysql client does, honouring its
     * DELIMITER lines, which the server itself does not understand.
     *
     * @return list<string>
     */
    private static function statements(string $script): array
    {
        $statements = [];
        $delimiter = ';';
        $buffer = '';
        foreach (preg_split('/\R/', $script) ?: [] as $line) {
            if (preg_match('/^\s*DELIMITER\s+(\S+)\s*$/i', $line, $match) === 1) {
                $delimiter = $match[1];
                continue;
            }
            $buffer .= $line . "\n";
            if (str_ends_with(rtrim($line), $delimiter)) {
                $statement = trim(substr(rtrim($buffer), 0, -strlen($delimiter)));
                if ($statement !== '') {
                    $statements[] = $statement;
                }
                $buffer = '';
            }
        }
        if (trim($buffer) !== '') {
            $statements[] = trim($buffer);
        }

        return $statements;
    }

    /**
     * @param array<string, mixed> $settings
     * @return array{settings: array<string, mixed>, revision: string}
     */
    private function payload(array $settings = [], ?string $revision = null): array
    {
        $row = ['id' => 20, 'name' => 'Fixture <query>', 'description' => 'Stored', 'xml_path' => self::XML, 'data_input_id' => 2];
        return ['settings' => $settings + array_diff_key($row, ['id' => true]), 'revision' => $revision ?? DataQuerySettings::revision($row)];
    }

    /** @return array<string, mixed> */
    private function state(): array
    {
        return [
            'query' => $this->db->query('SELECT * FROM snmp_query ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC),
            'templates' => $this->db->query('SELECT id, data_input_id FROM data_template_data ORDER BY id')->fetchAll(\PDO::FETCH_KEY_PAIR),
            'crc' => $this->crc(),
            'items' => $this->db->query('SELECT COUNT(*) FROM poller_item')->fetchColumn(),
        ];
    }

    private function crc(): mixed
    {
        return $this->db->query("SELECT value FROM settings WHERE name = 'poller_replicate_snmp_query_crc'")->fetchColumn();
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{int, array<string, mixed>}
     */
    private function worker(int $actor, string $action, int $id, array $payload): array
    {
        $nonce = bin2hex(random_bytes(16));
        $process = new Process(
            [PHP_BINARY, '-d', 'auto_prepend_file=', self::$candidate . '/bin/legacy-data-query.php'],
            self::$candidate,
            ['KADUPUL_DATA_QUERY_TEST_SCHEMA' => $this->schema],
            json_encode(['actor' => $actor, 'action' => $action, 'id' => $id, 'nonce' => $nonce, 'payload' => $payload], JSON_THROW_ON_ERROR),
            120
        );
        $process->run();
        self::assertSame('', $process->getErrorOutput());
        self::assertSame(1, preg_match('/^KADUPUL_DATA_QUERY_RESULT=(\{[^\r\n]+\})\n\z/', $process->getOutput(), $match), $process->getOutput());
        $answer = json_decode($match[1], true, 32, JSON_THROW_ON_ERROR);
        self::assertSame([$actor, $action, $id, $nonce], [$answer['actor'], $answer['action'], $answer['request_id'], $answer['nonce']]);

        return [(int) $process->getExitCode(), $answer];
    }
}
