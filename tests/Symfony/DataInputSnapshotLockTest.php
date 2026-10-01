<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Kadupul\Tests;

use Kadupul\DataInput\Domain\DataInputState;
use Kadupul\DataInput\Infrastructure\Legacy\DataInputHandoff;
use PHPUnit\Framework\TestCase;

final class DataInputSnapshotLockTest extends TestCase
{
    private ?\PDO $database = null;
    private string $name = '';
    private string $directory = '';
    private string $revision;

    protected function setUp(): void
    {
        $dsn = getenv('KADUPUL_TEST_MYSQL_DSN');
        if (!$dsn) {
            self::markTestSkipped('Native MySQL/MariaDB DSN is required.');
        }
        $root = dirname(__DIR__, 2);
        $this->database = new \PDO($dsn, getenv('KADUPUL_TEST_MYSQL_USER') ?: 'root', getenv('KADUPUL_TEST_MYSQL_PASSWORD') ?: '', [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $this->name = 'data_input_snapshot_' . bin2hex(random_bytes(8));
        $this->database->exec('CREATE DATABASE `' . $this->name . '`');
        $this->database->exec('USE `' . $this->name . '`');
        $this->database->exec("CREATE TABLE settings(name VARCHAR(100) PRIMARY KEY,value TEXT) ENGINE=InnoDB;
            INSERT INTO settings VALUES('auth_method','1'),('poller_replicate_data_input_crc','7'),('poller_replicate_data_input_fields_crc','11');
            CREATE TABLE user_auth(id INT PRIMARY KEY,username TEXT,enabled TEXT,locked TEXT,must_change_password TEXT,password_change TEXT) ENGINE=InnoDB;
            INSERT INTO user_auth VALUES(9,'operator','on','','','');
            CREATE TABLE user_auth_realm(user_id INT,realm_id INT,PRIMARY KEY(user_id,realm_id)) ENGINE=InnoDB;
            INSERT INTO user_auth_realm VALUES(9,8),(9,2);
            CREATE TABLE data_input(id INT PRIMARY KEY,hash VARCHAR(100),name TEXT,type_id INT,input_string TEXT) ENGINE=InnoDB;
            INSERT INTO data_input VALUES(3,'fixture-hash','Fixture',1,'fixture command'),(4,'other-hash','Other',1,'other command');
            CREATE TABLE data_input_fields(id INT PRIMARY KEY,data_input_id INT,KEY input_owner(data_input_id)) ENGINE=InnoDB;
            CREATE TABLE data_template_data(id INT PRIMARY KEY,local_data_id INT,data_input_id INT) ENGINE=InnoDB;");
        $method = $this->database->query('SELECT * FROM data_input WHERE id=3')->fetch(\PDO::FETCH_ASSOC);
        $this->revision = DataInputState::revision($method, []);
        $this->directory = sys_get_temp_dir() . '/' . $this->name;
        foreach (['', '/bin', '/include', '/lib'] as $folder) {
            mkdir($this->directory . $folder, 0700);
        }
        copy($root . '/bin/legacy-data-input-handoff.php', $this->directory . '/bin/legacy-data-input-handoff.php');
        self::assertSame(hash_file('sha256', $root . '/bin/legacy-data-input-handoff.php'), hash_file('sha256', $this->directory . '/bin/legacy-data-input-handoff.php'));
        copy($root . '/tests/Fixtures/data-input-snapshot-native.php', $this->directory . '/include/cli_check.php');
        file_put_contents($this->directory . '/source.json', json_encode($root, JSON_THROW_ON_ERROR));
        file_put_contents($this->directory . '/database', $this->name);
        file_put_contents($this->directory . '/mode', 'ok');
        file_put_contents($this->directory . '/whitelist', json_encode(['fixture-hash' => 'fixture command'], JSON_THROW_ON_ERROR));
        foreach (['api_data_source', 'poller', 'template', 'utility'] as $library) {
            file_put_contents($this->directory . '/lib/' . $library . '.php', '<?php');
        }
        file_put_contents($this->directory . '/lib/data_input_worker.php', '<?php require ' . var_export($root . '/lib/data_input_worker.php', true) . ';');
    }

    protected function tearDown(): void
    {
        if ($this->database === null) {
            return;
        }
        if ($this->name !== '') {
            $this->database->exec('DROP DATABASE IF EXISTS `' . $this->name . '`');
        }
        $this->database = null;
        if ($this->directory === '') {
            return;
        }
        foreach (glob($this->directory . '/*/*') as $file) {
            unlink($file);
        }
        foreach (glob($this->directory . '/*') as $file) {
            is_dir($file) ? rmdir($file) : unlink($file);
        }
        rmdir($this->directory);
    }

    public function testBoundCollectorReadsWhileActualTargetLocksRemainOwned(): void
    {
        $handoff = new DataInputHandoff(PHP_BINARY, $this->directory, 9, hrtime(true) / 1e9 + 5, 4);
        self::assertTrue($handoff->propagate(3, $this->revision));
        $observed = json_decode(file_get_contents($this->directory . '/observed'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['owned_transaction' => true, 'target_writer_blocked' => true, 'command' => 'fixture command'], $observed);
        self::assertSame('Unrelated writer', $this->database->query('SELECT name FROM data_input WHERE id=4')->fetchColumn());
        $this->assertTargetUnlockedAndPrimaryCrcUnchanged();
    }

    public function testDeadlineKillsTheLeafAndReleasesItsLocksWithoutChangingPrimaryCrc(): void
    {
        file_put_contents($this->directory . '/mode', 'slow');
        $handoff = new DataInputHandoff(PHP_BINARY, $this->directory, 9, hrtime(true) / 1e9 + 2, 0.3);
        self::assertFalse($handoff->propagate(3, $this->revision));
        self::assertFileExists($this->directory . '/started');
        $this->assertTargetUnlockedAndPrimaryCrcUnchanged();
        usleep(3200000);
        self::assertFileDoesNotExist($this->directory . '/late');
    }

    private function assertTargetUnlockedAndPrimaryCrcUnchanged(): void
    {
        $this->database->exec('SET SESSION innodb_lock_wait_timeout=1');
        self::assertSame(1, $this->database->exec("UPDATE data_input SET input_string='Confirmed unlocked' WHERE id=3"));
        self::assertSame(['poller_replicate_data_input_crc' => '7', 'poller_replicate_data_input_fields_crc' => '11'], $this->database->query("SELECT name,value FROM settings WHERE name LIKE 'poller_replicate%' ORDER BY name")->fetchAll(\PDO::FETCH_KEY_PAIR));
    }
}
