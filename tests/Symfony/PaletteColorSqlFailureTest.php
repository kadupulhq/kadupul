<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Graphing\Application\Port\PaletteColorAccess;
use Kadupul\Graphing\Infrastructure\Legacy\LegacyPaletteColorAccess;
use Kadupul\Graphing\Infrastructure\Legacy\LegacyPaletteColorPreferences;
use Kadupul\Graphing\Infrastructure\Legacy\LegacyPaletteColorStore;
use Kadupul\Graphing\Infrastructure\Legacy\PaletteSql;
use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\AuditEvent;
use Kadupul\IdentityAccess\Contract\AuditTrail;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Platform\Contract\DatabaseConnection;
use Kadupul\Platform\Contract\LegacyConfiguration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PaletteColorSqlFailureTest extends TestCase
{
    #[DataProvider('mutations')]
    public function testSilentFailuresCannotClaimSuccessOrCommitPartialRows(string $operation): void
    {
        $database = $this->database();
        $audit = new class implements AuditTrail {
            public array $events = [];
            public function record(AuditEvent $event): void
            {
                $this->events[] = $event;
            }
        };
        $store = new LegacyPaletteColorStore($this->connection($database), $this->access(), $audit, $this->createMock(LegacyConfiguration::class));
        $revision = $store->find(7)->revision;
        $snapshot = $store->snapshot();
        $before = $database->query('SELECT * FROM colors')->fetchAll(\PDO::FETCH_ASSOC);
        $database->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_SILENT);
        if ($operation === 'dependency-read') {
            self::assertNotFalse($database->exec('DROP TABLE graph_templates_item'));
            self::assertNotFalse($database->exec("CREATE VIEW graph_templates_item AS SELECT json_extract('invalid-json', '$') AS color_id"));
            $probe = $database->prepare('SELECT color_id FROM graph_templates_item WHERE color_id IN (?)');
            self::assertInstanceOf(\PDOStatement::class, $probe);
            self::assertFalse($probe->execute([7]));
            self::assertSame('HY000', $probe->errorCode());
        } else {
            $event = $operation === 'update' ? 'UPDATE' : 'INSERT';
            self::assertNotFalse($database->exec("CREATE TRIGGER reject_palette_write BEFORE $event ON colors BEGIN SELECT RAISE(FAIL, 'Fixture rejected write'); END"));
            self::assertSame(7, (int) $database->lastInsertId());
        }
        $failure = null;
        try {
            match ($operation) {
                'insert' => $store->save(9, null, 'new', 'def', null),
                'update' => $store->save(9, 7, 'changed', 'abc', $revision),
                'import' => $store->import(9, [['name' => 'changed', 'hex' => 'abc'], ['name' => 'new', 'hex' => 'def']], true, $snapshot),
                'dependency-read' => $store->delete(9, [7], [7 => $revision]),
            };
        } catch (\PDOException $error) {
            $failure = $error;
        }
        self::assertInstanceOf(\PDOException::class, $failure);
        self::assertNotSame('00000', $failure->errorInfo[0]);
        self::assertFalse($database->inTransaction());
        self::assertSame($before, $database->query('SELECT * FROM colors')->fetchAll(\PDO::FETCH_ASSOC));
        self::assertSame(AuditEvent::ALLOWED, $audit->events[array_key_last($audit->events)]->decision);
        self::assertSame(AuditEvent::FAILED, $audit->events[array_key_last($audit->events)]->outcome);
    }

    public static function mutations(): array
    {
        return [['insert'], ['update'], ['import'], ['dependency-read']];
    }

    public function testPrepareFailureIsNotAnEmptyResult(): void
    {
        $database = $this->database();
        $store = new LegacyPaletteColorStore($this->connection($database), $this->access(), $this->createMock(AuditTrail::class), $this->createMock(LegacyConfiguration::class));
        $database->exec('DROP TABLE colors');
        $database->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_SILENT);
        $this->expectException(\PDOException::class);
        $store->find(7);
    }

    #[DataProvider('readers')]
    public function testLateReadErrorsDoNotReturnPartialOrMissingResults(string $reader): void
    {
        $database = $this->database();
        $database->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_SILENT);
        $query = PaletteSql::execute($database, "SELECT 1 AS value UNION ALL SELECT json_extract('invalid-json', '$') AS value");
        if ($reader === 'one') {
            self::assertSame(['value' => 1], PaletteSql::one($query));
        } elseif ($reader === 'column') {
            self::assertSame(1, PaletteSql::column($query));
        }
        $this->expectException(\PDOException::class);
        match ($reader) {
            'one' => PaletteSql::one($query),
            'column' => PaletteSql::column($query),
            'all' => PaletteSql::all($query),
        };
    }

    public static function readers(): array
    {
        return [['one'], ['column'], ['all']];
    }

    public function testSuccessfulEmptyReadStillReturnsNoRow(): void
    {
        $database = $this->database();
        self::assertFalse(PaletteSql::one(PaletteSql::execute($database, 'SELECT * FROM colors WHERE id=?', [99])));
        self::assertFalse(PaletteSql::column(PaletteSql::execute($database, 'SELECT id FROM colors WHERE id=?', [99])));
        self::assertSame([], PaletteSql::all(PaletteSql::execute($database, 'SELECT * FROM colors WHERE id=?', [99])));
    }

    public function testFailedDirectRealmQueryCannotFallBackToAnEnabledGroup(): void
    {
        $database = $this->database();
        $database->exec("CREATE TABLE user_auth(id INT,username TEXT,enabled TEXT,locked TEXT,must_change_password TEXT);
            INSERT INTO user_auth VALUES(9,'fixture','on','','');
            INSERT INTO settings VALUES('auth_method','1'),('guest_user','0');
            CREATE VIEW user_auth_realm AS SELECT CAST(9 AS INTEGER) AS user_id,json_extract('invalid-json', '$') AS realm_id;
            CREATE TABLE user_auth_group(id INT,enabled TEXT); INSERT INTO user_auth_group VALUES(1,'on');
            CREATE TABLE user_auth_group_realm(group_id INT,realm_id INT); INSERT INTO user_auth_group_realm VALUES(1,5);
            CREATE TABLE user_auth_group_members(group_id INT,user_id INT); INSERT INTO user_auth_group_members VALUES(1,9);");
        $console = $this->createMock(ConsoleAccess::class);
        $console->method('consoleActor')->willReturn(new Actor(9, 'fixture'));
        $access = new LegacyPaletteColorAccess($console, $this->connection($database));
        $database->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_SILENT);
        $probe = $database->prepare('SELECT realm_id FROM user_auth_realm WHERE user_id = ? AND realm_id = ?');
        self::assertInstanceOf(\PDOStatement::class, $probe);
        self::assertFalse($probe->execute([9, 5]));
        self::assertSame('HY000', $probe->errorCode());
        $this->expectException(\PDOException::class);
        $access->authorize();
    }

    public function testSilentPreferenceWriteIsRefusedAndOriginalValueSurvives(): void
    {
        $database = $this->database();
        $database->exec("CREATE TABLE settings_user(user_id INT,name TEXT,value TEXT,PRIMARY KEY(user_id,name));
            INSERT INTO settings_user VALUES(9,'palette_colors_filters','{\"filter\":\"before\"}');
            CREATE TRIGGER reject_preference BEFORE INSERT ON settings_user BEGIN SELECT RAISE(FAIL,'Fixture rejected preference'); END");
        $preferences = new LegacyPaletteColorPreferences($this->access(), $this->connection($database));
        $database->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_SILENT);
        $failed = false;
        try {
            $preferences->save(['filter' => 'after']);
        } catch (\PDOException) {
            $failed = true;
        }
        self::assertTrue($failed);
        self::assertFalse($database->inTransaction());
        self::assertSame('{"filter":"before"}', $database->query('SELECT value FROM settings_user')->fetchColumn());
    }

    public function testSilentPreferenceReadFailureIsNotAnAbsentPreference(): void
    {
        $database = $this->database();
        $database->exec("CREATE VIEW settings_user AS SELECT CAST(9 AS INTEGER) AS user_id,'palette_colors_filters' AS name,json_extract('invalid-json', '$') AS value");
        $preferences = new LegacyPaletteColorPreferences($this->access(), $this->connection($database));
        $database->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_SILENT);
        $this->expectException(\PDOException::class);
        $preferences->load();
    }

    public function testSilentDuplicateRetainsNativeIdentityAfterConfirmedRollback(): void
    {
        $database = $this->database();
        $store = new LegacyPaletteColorStore($this->connection($database), $this->access(), $this->createMock(AuditTrail::class), $this->createMock(LegacyConfiguration::class));
        $database->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_SILENT);
        try {
            $store->save(9, null, 'duplicate', 'ABC', null);
            self::fail('Duplicate was accepted.');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('A Color with this hex value already exists.', $error->getMessage());
            self::assertInstanceOf(\PDOException::class, $error->getPrevious());
            self::assertSame(['23000', 19, 'UNIQUE constraint failed: colors.hex'], $error->getPrevious()->errorInfo);
        }
        self::assertFalse($database->inTransaction());
        self::assertSame(1, (int) $database->query('SELECT COUNT(*) FROM colors')->fetchColumn());
    }

    #[DataProvider('uncertainPreferences')]
    public function testPreferenceUncertainOutcomeDoesNotClaimSuccess(string $failure): void
    {
        $database = new class ('sqlite::memory:') extends \PDO {
            public string $failure = '';
            public function commit(): bool
            {
                return $this->failure === 'commit' ? false : parent::commit();
            }
            public function rollBack(): bool
            {
                if ($this->failure === 'rollback-throw') {
                    throw new \PDOException('Fixture rejected rollback.');
                }
                return $this->failure === 'rollback-false' ? false : parent::rollBack();
            }
        };
        $database->exec("CREATE TABLE settings_user(user_id INT,name TEXT,value TEXT,PRIMARY KEY(user_id,name));
            INSERT INTO settings_user VALUES(9,'palette_colors_filters','before')");
        if ($failure !== 'commit') {
            $database->exec("CREATE TRIGGER reject_preference BEFORE INSERT ON settings_user BEGIN SELECT RAISE(FAIL,'Fixture rejected preference'); END");
        }
        $database->failure = $failure;
        $database->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_SILENT);
        $preferences = new LegacyPaletteColorPreferences($this->access(), $this->connection($database));
        try {
            $preferences->save(['filter' => 'after']);
            self::fail('Unconfirmed preference operation succeeded.');
        } catch (\RuntimeException $error) {
            self::assertSame($failure === 'commit' ? 'Filter preference commit was not confirmed.' : 'Filter preference rollback was not confirmed.', $error->getMessage());
            self::assertSame($failure !== 'commit', $database->inTransaction());
            self::assertSame('before', $database->query('SELECT value FROM settings_user')->fetchColumn());
        } finally {
            if ($database->inTransaction()) {
                $database->exec('ROLLBACK');
            }
        }
    }

    public static function uncertainPreferences(): array
    {
        return [['commit'], ['rollback-false'], ['rollback-throw']];
    }

    private function database(): \PDO
    {
        $database = new \PDO('sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $database->exec("CREATE TABLE colors(id INTEGER PRIMARY KEY AUTOINCREMENT,name TEXT,hex TEXT COLLATE NOCASE UNIQUE,read_only TEXT);
            CREATE TABLE graph_templates_item(id INTEGER PRIMARY KEY,color_id INT,graph_template_id INT,local_graph_id INT);
            CREATE TABLE color_template_items(color_id INT); CREATE TABLE settings(name TEXT,value TEXT);
            INSERT INTO colors(id,name,hex,read_only) VALUES(7,'original','abc','');");
        return $database;
    }

    private function connection(\PDO $database): DatabaseConnection
    {
        return new class ($database) implements DatabaseConnection {
            public function __construct(private \PDO $database) {}
            public function get(): \PDO
            {
                return $this->database;
            }
        };
    }

    private function access(): PaletteColorAccess
    {
        $access = $this->createMock(PaletteColorAccess::class);
        $access->method('authorize')->willReturn(new Actor(9, 'fixture'));
        return $access;
    }
}
