<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Kadupul\Tests;

use Kadupul\DataInput\Application\DataInputDenied;
use Kadupul\DataInput\Infrastructure\Legacy\LegacyDataInputAccess;
use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Platform\Contract\DatabaseConnection;
use PHPUnit\Framework\TestCase;

final class DataInputStorageNativeTest extends TestCase
{
    private ?\PDO $database = null;
    private string $name = '';
    private string $directory = '';
    private string $root;

    protected function setUp(): void
    {
        $dsn = getenv('KADUPUL_TEST_MYSQL_DSN');
        if (!$dsn) {
            self::markTestSkipped('Native MySQL/MariaDB DSN is required.');
        }
        $this->root = dirname(__DIR__, 2);
        $this->database = new \PDO($dsn, getenv('KADUPUL_TEST_MYSQL_USER') ?: 'root', getenv('KADUPUL_TEST_MYSQL_PASSWORD') ?: '', [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $this->name = 'data_input_list_' . bin2hex(random_bytes(8));
        $this->database->exec('CREATE DATABASE `' . $this->name . '`');
        $this->database->exec('USE `' . $this->name . '`');
        $this->database->exec("CREATE TABLE settings(name VARCHAR(100) PRIMARY KEY,value TEXT) ENGINE=InnoDB;
            INSERT INTO settings VALUES('auth_method','1'),('guest_user','0');
            CREATE TABLE user_auth(id INT PRIMARY KEY,username TEXT,enabled TEXT,locked TEXT,must_change_password TEXT,password_change TEXT) ENGINE=InnoDB;
            INSERT INTO user_auth VALUES(9,'operator','on','','','');
            CREATE TABLE user_auth_realm(user_id INT,realm_id INT,PRIMARY KEY(user_id,realm_id)) ENGINE=InnoDB;
            INSERT INTO user_auth_realm VALUES(9,8),(9,2);
            CREATE TABLE user_auth_group(id INT PRIMARY KEY,enabled TEXT) ENGINE=InnoDB;
            CREATE TABLE user_auth_group_members(group_id INT,user_id INT,PRIMARY KEY(group_id,user_id)) ENGINE=InnoDB;
            CREATE TABLE user_auth_group_realm(group_id INT,realm_id INT,PRIMARY KEY(group_id,realm_id)) ENGINE=InnoDB;
            CREATE TABLE settings_user(user_id INT,name VARCHAR(100),value TEXT,PRIMARY KEY(user_id,name)) ENGINE=InnoDB;
            INSERT INTO settings_user VALUES(9,'twig_data_input_filter','original');
            CREATE TABLE data_input(id INT PRIMARY KEY,hash VARCHAR(32),name TEXT,type_id INT,input_string TEXT) ENGINE=InnoDB;
            INSERT INTO data_input VALUES(3,'11111111111111111111111111111111','Fixture',1,'fixture command');
            CREATE TABLE data_template_data(id INT PRIMARY KEY,local_data_id INT,data_input_id INT) ENGINE=InnoDB;");
        foreach (['data_input_fields', 'data_input_data', 'data_template_rrd'] as $table) {
            $this->database->exec('CREATE TABLE ' . $table . ' (id INT PRIMARY KEY) ENGINE=InnoDB');
        }
        $this->directory = sys_get_temp_dir() . '/' . $this->name;
        foreach (['', '/bin', '/include', '/lib'] as $folder) {
            mkdir($this->directory . $folder, 0700);
        }
        copy($this->root . '/bin/legacy-data-input.php', $this->directory . '/bin/legacy-data-input.php');
        self::assertSame(hash_file('sha256', $this->root . '/bin/legacy-data-input.php'), hash_file('sha256', $this->directory . '/bin/legacy-data-input.php'));
        copy($this->root . '/tests/Fixtures/data-input-list-native.php', $this->directory . '/include/cli_check.php');
        file_put_contents($this->directory . '/source.json', json_encode($this->root, JSON_THROW_ON_ERROR));
        file_put_contents($this->directory . '/database', $this->name);
        foreach (['api_data_source', 'poller', 'template', 'utility'] as $library) {
            file_put_contents($this->directory . '/lib/' . $library . '.php', '<?php');
        }
        file_put_contents($this->directory . '/lib/data_input_worker.php', '<?php require ' . var_export($this->root . '/lib/data_input_worker.php', true) . ';');
    }

    protected function tearDown(): void
    {
        if ($this->database === null) {
            return;
        }
        $this->database->exec('DROP DATABASE IF EXISTS `' . $this->name . '`');
        if ($this->directory !== '') {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($iterator as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($this->directory);
        }
    }

    /** @dataProvider listCases */
    public function testActualListWorkerPersistsOnlyConfirmedTransactionalPreferences(string $engine, bool $fail, bool $allowed): void
    {
        $this->database->exec('ALTER TABLE settings_user ENGINE=' . $engine);
        if (!$allowed) {
            $this->database->exec('DELETE FROM user_auth_realm WHERE realm_id=2');
        }
        $scenario = ['engine' => $engine, 'fail_plugin' => $fail, 'allowed' => $allowed];
        $before = $this->preferences();
        $result = $this->worker($scenario);
        if ($engine !== 'InnoDB') {
            self::assertSame($before, $this->preferences(), 'Nontransactional preferences must never survive a rejected list operation.');
        }
        if (!$allowed) {
            self::assertSame('denied', $result['status']);
            self::assertFileDoesNotExist($this->directory . '/preference-write');
        } elseif ($engine !== 'InnoDB') {
            self::assertSame('failed', $result['status']);
            self::assertFileDoesNotExist($this->directory . '/preference-write');
        } else {
            self::assertSame($fail ? 'failed' : 'ok', $result['status']);
            self::assertFileExists($this->directory . '/preference-write');
        }
        if ($allowed && $engine === 'InnoDB' && !$fail) {
            self::assertSame(['twig_data_input_direction' => 'ASC', 'twig_data_input_filter' => 'Fixture', 'twig_data_input_rows' => '-1', 'twig_data_input_sort' => 'name'], $this->preferences());
            self::assertSame(1, $result['result']['total']);
        } else {
            self::assertSame($before, $this->preferences());
        }
    }

    public static function listCases(): array
    {
        return [['MyISAM', true, true], ['MyISAM', false, true], ['InnoDB', true, true], ['InnoDB', false, true], ['MyISAM', false, false]];
    }

    /** @dataProvider groupTableCases */
    public function testNativeGroupTableProbesPreserveGrantAndMissingTableOutcomes(?string $missing): void
    {
        $this->database->exec("DELETE FROM user_auth_realm WHERE realm_id=2;
            INSERT INTO user_auth_group VALUES(3,'on');
            INSERT INTO user_auth_group_members VALUES(3,9);
            INSERT INTO user_auth_group_realm VALUES(3,2);");
        if ($missing !== null) {
            $this->database->exec('DROP TABLE ' . $missing);
        }
        $console = $this->createMock(ConsoleAccess::class);
        $console->method('consoleActor')->willReturn(new Actor(9, 'operator'));
        $observed = $this->tracedConnection();
        $connection = new readonly class ($observed) implements DatabaseConnection {
            public function __construct(private \PDO $db) {}
            public function get(): \PDO
            {
                return $this->db;
            }
        };
        $access = new LegacyDataInputAccess($console, $connection);
        if ($missing !== null) {
            try {
                $access->authorize();
                self::fail('Missing native group table admitted a grant.');
            } catch (DataInputDenied $error) {
                self::assertFalse($error->anonymous);
            }
            $tables = ['user_auth_group_realm', 'user_auth_group_members', 'user_auth_group'];
            self::assertSame(array_map(static fn($table) => "SHOW TABLES LIKE '" . $table . "'", array_slice($tables, 0, array_search($missing, $tables, true) + 1)), $observed->probes);
            return;
        }
        self::assertSame(9, $access->authorize()->id);
        $observed->beginTransaction();
        try {
            $access->assertCurrent(9);
        } finally {
            $observed->rollBack();
        }
        self::assertSame(array_merge(...array_fill(0, 2, ["SHOW TABLES LIKE 'user_auth_group_realm'", "SHOW TABLES LIKE 'user_auth_group_members'", "SHOW TABLES LIKE 'user_auth_group'"])), $observed->probes);
    }

    public function testNativeGroupProbeQueryExceptionRetainsDeniedOutcome(): void
    {
        $observed = $this->tracedConnection();
        $this->database->exec('DROP DATABASE `' . $this->name . '`');
        $console = $this->createMock(ConsoleAccess::class);
        $connection = new readonly class ($observed) implements DatabaseConnection {
            public function __construct(private \PDO $db) {}
            public function get(): \PDO
            {
                return $this->db;
            }
        };
        $access = new LegacyDataInputAccess($console, $connection);
        $method = new \ReflectionMethod($access, 'groupTablesExist');
        self::assertFalse($method->invoke($access, $observed));
        self::assertSame(["SHOW TABLES LIKE 'user_auth_group_realm'"], $observed->probes);
    }

    private function tracedConnection(): \PDO
    {
        $db = new class (getenv('KADUPUL_TEST_MYSQL_DSN'), getenv('KADUPUL_TEST_MYSQL_USER') ?: 'root', getenv('KADUPUL_TEST_MYSQL_PASSWORD') ?: '', [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]) extends \PDO {
            public array $probes = [];
            public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): \PDOStatement|false
            {
                if (str_starts_with($query, 'SHOW TABLES LIKE ')) {
                    $this->probes[] = $query;
                }
                return $fetchMode === null ? parent::query($query) : parent::query($query, $fetchMode, ...$fetchModeArgs);
            }
        };
        $db->exec('USE `' . $this->name . '`');
        return $db;
    }

    public static function groupTableCases(): array
    {
        return [[null], ['user_auth_group_realm'], ['user_auth_group_members'], ['user_auth_group']];
    }

    private function preferences(): array
    {
        return $this->database->query('SELECT name,value FROM settings_user WHERE user_id=9 ORDER BY name')->fetchAll(\PDO::FETCH_KEY_PAIR);
    }

    public function testChildCoverageExcludesOnlyTheOwnedVarDirectory(): void
    {
        foreach ([$this->root, '/var/www/html[fixture]~'] as $root) {
            $exclude = $this->coverageExclusion($root);
            self::assertSame(0, preg_match($exclude, $root . '/lib/data_input_worker.php'));
            self::assertSame(0, preg_match($exclude, $root . '/bin/legacy-data-input.php'));
            self::assertSame(1, preg_match($exclude, $root . '/var/cache/container.php'));
            self::assertSame(1, preg_match($exclude, $root . '/include/vendor/autoload.php'));
            self::assertSame(1, preg_match($exclude, $root . '/tests/Fixtures/data-input-list-native.php'));
            self::assertSame(0, preg_match($exclude, $root . '-sibling/var/application.php'));
            self::assertSame(0, preg_match($exclude, '/var/another-application/lib/worker.php'));
        }
    }

    private function coverageExclusion(string $root): string
    {
        return '~/(include/vendor|tests)/|^' . preg_quote($root . '/var/', '~') . '~';
    }

    private function worker(array $scenario): array
    {
        file_put_contents($this->directory . '/scenario.json', json_encode($scenario, JSON_THROW_ON_ERROR));
        $coverage = \PHPUnit\Runner\CodeCoverage::instance()->isActive() ? \PHPUnit\Runner\CodeCoverage::instance()->codeCoverage() : null;
        $environment = getenv();
        if ($coverage !== null) {
            $environment['KADUPUL_LIST_NATIVE_COVERAGE'] = '1';
        }
        $command = [PHP_BINARY, '-d', 'auto_prepend_file=', '-d', 'pcov.directory=/', '-d', 'pcov.exclude=' . $this->coverageExclusion($this->root), $this->directory . '/bin/legacy-data-input.php'];
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $environment);
        self::assertIsResource($process);
        fwrite($pipes[0], json_encode(['actor' => 9, 'id' => 0, 'nonce' => 'native-list', 'action' => 'list', 'payload' => ['filter' => 'Fixture']], JSON_THROW_ON_ERROR));
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        self::assertSame('', $errors);
        self::assertMatchesRegularExpression('/^KADUPUL_DATA_INPUT_RESULT=(\{[^\r\n]+\})\n$/', $out);
        $result = json_decode(substr($out, strlen('KADUPUL_DATA_INPUT_RESULT=')), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($result['status'] === 'ok' ? 0 : 1, $status);
        if ($coverage !== null) {
            require_once $this->root . '/tests/Helpers/NativeChildCoverageEvidence.php';
            $reports = glob($this->directory . '/*.coverage');
            self::assertCount(1, $reports);
            $sources = ['composer.lock', 'tests/composer.lock', 'tests/Fixtures/rrd-process-coverage.php', 'tests/Helpers/NativeChildCoverageEvidence.php', 'tests/Symfony/DataInputStorageNativeTest.php', 'bin/legacy-data-input.php', 'lib/data_input_worker.php', 'src/DataInput/Domain/DataInputState.php', 'lib/rrd.php', 'src/Graphing/Infrastructure/Rrd/ProxyCipher.php', 'lib/dsdebug.php', 'lib/rrd_maintenance.php', 'lib/poller.php', 'lib/boost.php', 'lib/api_data_source.php', 'lib/rrdcheck.php', 'lib/dsstats.php'];
            $markers = ['actual-list-worker-terminated'];
            $hits = ['bin/legacy-data-input.php', 'lib/data_input_worker.php'];
            $child = \NativeChildCoverageEvidence::load($reports[0], $this->root, 'tests/Fixtures/data-input-list-native.php', json_encode($scenario, JSON_THROW_ON_ERROR), $sources, $markers, $hits);
            static $verified = false;
            if (!$verified) {
                self::assertSame(28, \NativeChildCoverageEvidence::verifyRejections($reports[0], $this->root, 'tests/Fixtures/data-input-list-native.php', json_encode($scenario, JSON_THROW_ON_ERROR), $sources, $markers, $hits, 'lib/boost.php'));
                $verified = true;
            }
            $coverage->merge($child);
        }
        return $result;
    }
}
