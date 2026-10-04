<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

$applicationLoader = require dirname(__DIR__, 2) . '/include/vendor/autoload.php';
// Keep the database matrix's PHPUnit ahead of application dev dependencies.
$applicationLoader->unregister();
$applicationLoader->register(false);

use Kadupul\Inventory\Infrastructure\Legacy\DeviceMutationSelection;
use PHPUnit\Framework\TestCase;

/** Run production selection SQL against isolated copies of the install schema. */
final class DeviceMutationSelectionDatabaseTest extends TestCase
{
    private const TABLES = ['settings', 'user_auth', 'user_auth_realm', 'user_auth_group', 'user_auth_group_members', 'user_auth_group_realm', 'user_auth_perms', 'user_auth_group_perms', 'host', 'graph_local', 'sites', 'poller'];
    private string $prefix;
    private PDO $database;

    protected function setUp(): void
    {
        if (!getenv('KADUPUL_TEST_MYSQL_DSN')) {
            self::markTestSkipped('KADUPUL_TEST_MYSQL_DSN is required for database contracts');
        }
        $this->prefix = 'selection_' . bin2hex(random_bytes(8)) . '_';
        $this->database = $this->connect();
        $schema = file_get_contents(dirname(__DIR__, 2) . '/cacti.sql');
        foreach (self::TABLES as $table) {
            if (!preg_match('/CREATE TABLE `?' . preg_quote($table, '/') . '`? \(.*?;\n/s', $schema, $match)) {
                throw new RuntimeException('Missing install table: ' . $table);
            }
            $this->database->exec($this->sql($match[0]));
        }
        foreach ([
            "INSERT INTO settings (name, value) VALUES ('auth_method','1'),('guest_user','0'),('graph_auth_method','1')",
            "INSERT INTO user_auth (id, username, enabled, locked, policy_graphs, policy_hosts, policy_graph_templates) VALUES (7,'selection-operator','on','',1,1,1)",
            'INSERT INTO user_auth_realm (user_id, realm_id) VALUES (7,8),(7,3)',
            "INSERT INTO host (id, description, hostname, site_id, poller_id, deleted) VALUES (11,'selection-device','127.0.0.1',0,1,'')",
            "INSERT INTO sites (id, name) VALUES (7,'earlier destination'),(9,'new destination')",
            "INSERT INTO poller (id, name, disabled) VALUES (1,'primary',''),(3,'enabled destination',''),(5,'disabled destination','on')",
        ] as $sql) {
            $this->database->exec($this->sql($sql));
        }
        $this->database->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (!isset($this->database)) {
            return;
        }
        if ($this->database->inTransaction()) {
            $this->database->rollBack();
        }
        foreach (array_reverse(self::TABLES) as $table) {
            $this->database->exec('DROP TABLE IF EXISTS ' . $this->prefix . $table);
        }
    }

    public function testSiteZeroSelectionLocksNewDestinationsOnceInNumericOrder(): void
    {
        $locked = $this->lock([9, 7, 9]);
        self::assertSame([7, 9], array_keys($locked['site_locks']));
        self::assertSame(9, (int) $locked['site_locks'][9][0]['id']);
        self::assertSame(0, (int) $locked['rows'][0]['site_id']);
    }

    public function testNoSitesAndExplicitUnassignmentReturnAnEmptyLockMap(): void
    {
        self::assertSame([], $this->lock()['site_locks']);
        self::assertSame([], $this->lock([0])['site_locks']);
    }

    public function testDestinationCollectorsIncludeTheirEnabledStateInNumericOrder(): void
    {
        $locked = $this->lock([], [5, 3, 3]);
        self::assertSame([1, 3, 5], array_keys($locked['poller_locks']));
        self::assertSame('', $locked['poller_locks'][3][0]['disabled']);
        self::assertSame('on', $locked['poller_locks'][5][0]['disabled']);
        self::assertSame([1, 3, 5], $locked['pollers']);
    }

    public function testMissingDestinationRowsRemainUnavailableToTheWorker(): void
    {
        $locked = $this->lock([99], [99]);
        self::assertSame([], $locked['site_locks'][99]);
        self::assertSame([], $locked['poller_locks'][99]);
    }

    public function testConcurrentDestinationChangesWaitUntilTheOwningTransactionEnds(): void
    {
        $this->lock([9], [3]);
        $other = $this->connect();
        $other->exec('SET SESSION innodb_lock_wait_timeout=1');
        foreach (['UPDATE sites SET name=\'changed\' WHERE id=9', 'UPDATE poller SET disabled=\'on\' WHERE id=3'] as $sql) {
            try {
                $other->exec($this->sql($sql));
                self::fail('Destination changed while the assignment transaction owned its lock');
            } catch (PDOException $error) {
                self::assertSame(1205, (int) $error->errorInfo[1]);
            }
        }
        $this->database->rollBack();
        self::assertSame(1, $other->exec($this->sql("UPDATE sites SET name='changed' WHERE id=9")));
        self::assertSame(1, $other->exec($this->sql("UPDATE poller SET disabled='on' WHERE id=3")));
    }

    /** @return array<string, mixed> */
    private function lock(array $sites = [], array $pollers = []): array
    {
        $status = null;
        $locked = (new DeviceMutationSelection())->lock($this->database, 7, [11], static function (string $next) use (&$status): void {
            $status = $next;
        }, $sites, $pollers);
        self::assertNull($status);
        self::assertTrue($this->database->inTransaction());
        return $locked;
    }

    private function sql(string $sql): string
    {
        return preg_replace_callback('/\b(' . implode('|', self::TABLES) . ')\b/', fn(array $match): string => $this->prefix . $match[1], $sql);
    }

    private function connect(): PDO
    {
        $rewrite = $this->sql(...);
        return new class (getenv('KADUPUL_TEST_MYSQL_DSN'), getenv('KADUPUL_TEST_MYSQL_USER') ?: 'root', getenv('KADUPUL_TEST_MYSQL_PASSWORD') ?: '', $rewrite) extends PDO {
            public function __construct(string $dsn, string $user, string $password, private Closure $rewrite)
            {
                parent::__construct($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
                $this->exec("SET SESSION sql_mode='NO_ENGINE_SUBSTITUTION'");
            }

            public function prepare(string $query, array $options = []): PDOStatement|false
            {
                return parent::prepare(($this->rewrite)($query), $options);
            }

            public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
            {
                return $fetchMode === null ? parent::query(($this->rewrite)($query)) : parent::query(($this->rewrite)($query), $fetchMode, ...$fetchModeArgs);
            }
        };
    }
}
