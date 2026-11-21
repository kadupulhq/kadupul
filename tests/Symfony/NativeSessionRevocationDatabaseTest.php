<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\IdentityAccess\Infrastructure\Legacy\NativeSessionRevocation;
use Kadupul\Platform\Contract\DatabaseConnection;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Native revocation against the installed schema on disposable matrix servers. */
final class NativeSessionRevocationDatabaseTest extends TestCase
{
    private const array TABLES = ['user_auth', 'settings_user', 'user_auth_cache', 'sessions'];
    private ?PDO $database = null;
    private string $schema = '';
    private string $dsn = '';
    private string $user = '';
    private string $password = '';

    protected function setUp(): void
    {
        $dsn = getenv('KADUPUL_TEST_MYSQL_DSN');
        if (!$dsn) {
            self::markTestSkipped('KADUPUL_TEST_MYSQL_DSN is required for database contracts');
        }
        require_once dirname(__DIR__, 2) . '/lib/auth.php';
        $this->dsn = $dsn;
        $this->user = getenv('KADUPUL_TEST_MYSQL_ADMIN_USER') ?: (getenv('KADUPUL_TEST_MYSQL_USER') ?: 'root');
        $this->password = getenv('KADUPUL_TEST_MYSQL_ADMIN_PASSWORD') ?: (getenv('KADUPUL_TEST_MYSQL_PASSWORD') ?: '');
        $this->database = new PDO($this->dsn, $this->user, $this->password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $schema = 'session_revocation_' . bin2hex(random_bytes(8));
        $this->database->exec('CREATE DATABASE `' . $schema . '` CHARACTER SET utf8mb4');
        // Claim cleanup ownership only after this invocation created the schema.
        $this->schema = $schema;
        $this->database->exec('USE `' . $this->schema . '`');
        $source = file_get_contents(dirname(__DIR__, 2) . '/cacti.sql');
        self::assertIsString($source);
        foreach (self::TABLES as $table) {
            self::assertSame(1, preg_match('/CREATE TABLE `?' . $table . '`? \(.*?\n\) ENGINE=[^;]*;/s', $source, $match), $table);
            $this->database->exec($match[0]);
        }
        $this->database->exec("INSERT INTO user_auth(id, username, password) VALUES(42,'fixture-operator','unchanged-hash'),(43,'fixture-other','other-hash')");
        $this->database->exec("INSERT INTO settings_user(user_id,name,value) VALUES(42,'page_refresh','60')");
        $this->database->exec("INSERT INTO sessions(id,user_id,data) VALUES('first',42,'first-session'),('second',42,'second-session'),('other',43,'other-session')");
        $this->database->exec("INSERT INTO user_auth_cache(user_id,token) VALUES(42,'first-fixture-token'),(42,'second-fixture-token'),(43,'other-fixture-token')");
    }

    protected function tearDown(): void
    {
        if ($this->database !== null && $this->schema !== '') {
            if ($this->database->inTransaction()) {
                $this->database->rollBack();
            }
            $this->database->exec('DROP DATABASE `' . $this->schema . '`');
        }
        $this->database = null;
    }

    #[DataProvider('outcomes')]
    public function testInstalledSchemaOutcomes(string $scenario, bool $success): void
    {
        $credential = hash('sha256', 'unchanged-hash');
        if ($scenario === 'rehash') {
            self::assertTrue(\auth_rehash_password_preserving_sessions(42, 'unchanged-hash', 'upgraded-hash', $this->database));
        } elseif (in_array($scenario, ['generation', 'tokens', 'sessions'], true)) {
            $table = ['generation' => 'settings_user', 'tokens' => 'user_auth_cache', 'sessions' => 'sessions'][$scenario];
            $event = $scenario === 'generation' ? 'INSERT' : 'DELETE';
            $this->database->exec("CREATE TRIGGER refuse_write BEFORE $event ON $table FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture refusal'");
        } elseif ($scenario === 'readback') {
            $this->database->exec("CREATE TRIGGER alter_generation BEFORE INSERT ON settings_user FOR EACH ROW SET NEW.value='invalid'");
        } elseif ($scenario === 'caller') {
            $this->database->beginTransaction();
            $this->database->exec("INSERT INTO settings_user VALUES(43,'caller','owned')");
        } elseif ($scenario === 'stale') {
            $credential = str_repeat('a', 64);
        } elseif ($scenario === 'malformed') {
            $credential = "invalid\0";
        } elseif ($scenario === 'missing') {
            $this->database->exec('DELETE FROM user_auth WHERE id=42');
        }
        $before = $this->snapshot();
        self::assertSame($success, $scenario === 'facade'
            ? \auth_logout_everywhere(42, $credential, $this->database)
            : $this->service($this->database)->revoke(42, $credential));
        self::assertSame($scenario === 'caller', $this->database->inTransaction());
        if (!$success) {
            self::assertSame($before, $this->snapshot());
            if ($scenario === 'caller') {
                $this->database->rollBack();
                self::assertSame(0, (int) $this->database->query("SELECT COUNT(*) FROM settings_user WHERE name='caller'")->fetchColumn());
            }
            return;
        }
        $this->assertRevoked($before, $credential);
    }

    public static function outcomes(): iterable
    {
        foreach (['normal', 'facade', 'rehash', 'generation', 'tokens', 'sessions', 'readback', 'caller', 'stale', 'malformed', 'missing'] as $scenario) {
            yield $scenario => [$scenario, in_array($scenario, ['normal', 'facade', 'rehash'], true)];
        }
    }

    #[DataProvider('unsafeTables')]
    public function testUnsafeStorageIsRefusedOnTheMutatingConnection(string $table, bool $temporary): void
    {
        $other = $this->connection();
        if ($temporary) {
            $definition = $this->database->query('SHOW CREATE TABLE `' . $table . '`')->fetch(PDO::FETCH_NUM);
            self::assertIsArray($definition);
            self::assertIsString($definition[1]);
            $sql = preg_replace('/\ACREATE TABLE /', 'CREATE TEMPORARY TABLE ', $definition[1], 1, $replacements);
            self::assertSame(1, $replacements);
            $other->exec($sql);
            // Populate the shadow: an empty account table would refuse before
            // demonstrating whether the temporary-table guard is effective.
            foreach ($this->snapshot()[$table] as $row) {
                $columns = implode('`,`', array_keys($row));
                $values = implode(',', array_fill(0, count($row), '?'));
                $other->prepare('INSERT INTO `' . $table . '` (`' . $columns . '`) VALUES (' . $values . ')')->execute(array_values($row));
            }
        } else {
            // MyISAM cannot index settings_user's 255 utf8mb4 characters.
            // Keep its column width and ASCII fixture data while changing the engine.
            $charset = $table === 'settings_user' ? 'CONVERT TO CHARACTER SET utf8mb3, ' : '';
            $other->exec('ALTER TABLE `' . $table . '` ' . $charset . 'ENGINE=MyISAM');
        }
        $before = $this->snapshot();
        self::assertFalse($this->service($other)->revoke(42, hash('sha256', 'unchanged-hash')));
        self::assertFalse($other->inTransaction());
        self::assertSame($before, $this->snapshot());
        if ($temporary) {
            $other->exec('DROP TEMPORARY TABLE `' . $table . '`');
            // The same account is usable after removing only the invalid shadow.
            self::assertTrue($this->service($other)->revoke(42, hash('sha256', 'unchanged-hash')));
            $this->assertRevoked($before, hash('sha256', 'unchanged-hash'));
        }
    }

    public static function unsafeTables(): iterable
    {
        foreach (self::TABLES as $table) {
            yield $table . '-nontransactional' => [$table, false];
            yield $table . '-shadow' => [$table, true];
        }
    }

    public function testRefusedCommitRollsBackTheActualConnection(): void
    {
        $db = new class ($this->dsn, $this->user, $this->password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]) extends PDO {
            public function commit(): bool
            {
                return false;
            }
        };
        $db->exec('USE `' . $this->schema . '`');
        $before = $this->snapshot();
        self::assertFalse($this->service($db)->revoke(42, hash('sha256', 'unchanged-hash')));
        self::assertFalse($db->inTransaction());
        self::assertSame($before, $this->snapshot());
    }

    #[DataProvider('rivals')]
    public function testAccountLockRechecksAConcurrentCredentialUpdate(string $outcome, bool $success): void
    {
        $before = $this->snapshot();
        // The rival is SQL fixture work only. The shipped service executes in
        // this PHPUnit process, so its measured coverage stays with this test.
        $program = <<<'CHILD'
$input=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
$db=new PDO($input['dsn'],$input['user'],$input['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
if (!preg_match('/\Asession_revocation_[a-f0-9]{16}\z/D',$input['schema'])) { throw new RuntimeException('Invalid fixture schema'); }
$db->exec('USE `'.$input['schema'].'`');
$db->beginTransaction();
$db->query('SELECT password FROM user_auth WHERE id=42 FOR UPDATE')->fetchColumn();
$own=(int)$db->query('SELECT CONNECTION_ID()')->fetchColumn();
echo "locked\n"; flush();
$maria=str_contains($db->query('SELECT VERSION()')->fetchColumn(),'MariaDB');
$sql=$maria
    ? 'SELECT COUNT(*) FROM information_schema.INNODB_LOCK_WAITS w JOIN information_schema.INNODB_TRX r ON r.trx_id=w.requesting_trx_id JOIN information_schema.INNODB_TRX b ON b.trx_id=w.blocking_trx_id WHERE r.trx_mysql_thread_id=? AND b.trx_mysql_thread_id=?'
    : 'SELECT COUNT(*) FROM performance_schema.data_lock_waits w JOIN performance_schema.threads r ON r.THREAD_ID=w.REQUESTING_THREAD_ID JOIN performance_schema.threads b ON b.THREAD_ID=w.BLOCKING_THREAD_ID WHERE r.PROCESSLIST_ID=? AND b.PROCESSLIST_ID=?';
$q=$db->prepare($sql); $observed=false; $deadline=microtime(true)+15;
do { $q->execute([$input['waiter'],$own]); $observed=(int)$q->fetchColumn()>0; $q->closeCursor(); if ($observed) break; usleep(100000); } while(microtime(true)<$deadline);
if (!$observed) { $db->rollBack(); throw new RuntimeException('Revocation did not wait for the account lock'); }
$db->exec("UPDATE user_auth SET password='upgraded-hash' WHERE id=42");
if ($input['outcome']==='rehash') {
    $db->prepare("REPLACE INTO settings_user VALUES(42,'auth_credential_generation',?)")->execute([hash('sha256','upgraded-hash').':'.hash('sha256','unchanged-hash')]);
}
$input['outcome']==='rollback' ? $db->rollBack() : $db->commit();
echo "observed\n";
CHILD;
        $process = proc_open([PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'zend.exception_ignore_args=1', '-r', $program], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        try {
            $payload = json_encode(['dsn' => $this->dsn, 'user' => $this->user, 'password' => $this->password, 'schema' => $this->schema,
                'waiter' => (int) $this->database->query('SELECT CONNECTION_ID()')->fetchColumn(), 'outcome' => $outcome], JSON_THROW_ON_ERROR);
            self::assertSame(strlen($payload), fwrite($pipes[0], $payload));
            fclose($pipes[0]);
            stream_set_timeout($pipes[1], 20);
            self::assertSame("locked\n", fgets($pipes[1]));
            self::assertSame($success, $this->service($this->database)->revoke(42, hash('sha256', 'unchanged-hash')));
            self::assertSame("observed\n", stream_get_contents($pipes[1]));
            self::assertSame('', stream_get_contents($pipes[2]));
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process));
        } finally {
            if (is_resource($process)) {
                proc_terminate($process);
                foreach ($pipes as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }
                proc_close($process);
            }
        }
        self::assertFalse($this->database->inTransaction());
        if ($success) {
            $expected = $before;
            if ($outcome === 'rehash') {
                $expected['user_auth'][0]['password'] = 'upgraded-hash';
            }
            $this->assertRevoked($expected, hash('sha256', 'unchanged-hash'));
        } else {
            $before['user_auth'][0]['password'] = 'upgraded-hash';
            self::assertSame($before, $this->snapshot());
        }
    }

    public static function rivals(): iterable
    {
        yield 'replacement commits' => ['replace', false];
        yield 'replacement rolls back' => ['rollback', true];
        yield 'transparent rehash commits' => ['rehash', true];
    }

    private function service(PDO $db): NativeSessionRevocation
    {
        return new NativeSessionRevocation(new class ($db) implements DatabaseConnection {
            public function __construct(private readonly PDO $db) {}
            public function get(): PDO
            {
                return $this->db;
            }
        });
    }

    private function connection(): PDO
    {
        $db = new PDO($this->dsn, $this->user, $this->password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $db->exec('USE `' . $this->schema . '`');
        return $db;
    }

    private function snapshot(): array
    {
        $state = [];
        foreach (self::TABLES as $table) {
            $state[$table] = $this->database->query('SELECT * FROM `' . $table . '` ORDER BY 1,2')->fetchAll(PDO::FETCH_ASSOC);
        }
        return $state;
    }

    private function assertRevoked(array $before, string $credential): void
    {
        $after = $this->snapshot();
        self::assertSame($before['user_auth'], $after['user_auth']);
        self::assertSame('60', $this->database->query("SELECT value FROM settings_user WHERE user_id=42 AND name='page_refresh'")->fetchColumn());
        foreach (['sessions', 'user_auth_cache'] as $table) {
            self::assertSame(array_values(array_filter($before[$table], static fn(array $row): bool => (int) $row['user_id'] !== 42)), $after[$table]);
        }
        $password = $this->database->query('SELECT password FROM user_auth WHERE id=42')->fetchColumn();
        $generation = \auth_session_credential_generation(42, $password, $this->database);
        self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/D', $generation);
        self::assertNotSame($credential, $generation);
        self::assertFalse($this->service($this->database)->revoke(42, $credential));
        self::assertSame($after, $this->snapshot());
    }
}
