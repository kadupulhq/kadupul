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

final class NativeSessionRevocationTest extends TestCase
{
    #[DataProvider('outcomes')]
    public function testInjectedRevocationPreservesTheNativeTransactionContract(string $scenario, bool $success): void
    {
        $db = new class ('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]) extends PDO {
            public bool $refuseCommit = false;

            public function commit(): bool
            {
                return !$this->refuseCommit && parent::commit();
            }
        };
        $db->exec("CREATE TABLE user_auth(id INTEGER PRIMARY KEY, password TEXT);
            INSERT INTO user_auth VALUES(42,'unchanged-hash'),(43,'other-hash');
            CREATE TABLE settings_user(user_id INTEGER,name TEXT,value TEXT,PRIMARY KEY(user_id,name));
            INSERT INTO settings_user VALUES(42,'page_refresh','60');
            CREATE TABLE sessions(id TEXT PRIMARY KEY,user_id INTEGER,data TEXT);
            INSERT INTO sessions VALUES('first',42,'first-session'),('second',42,'second-session'),('other',43,'other-session');
            CREATE TABLE user_auth_cache(user_id INTEGER,token TEXT);
            INSERT INTO user_auth_cache VALUES(42,'first-token'),(42,'second-token'),(43,'other-token');");
        $credential = hash('sha256', 'unchanged-hash');
        if ($scenario === 'rehash') {
            // Native rehash mappings retain the old credential generation.
            $db->exec("UPDATE user_auth SET password='upgraded-hash' WHERE id=42");
            $db->prepare("INSERT INTO settings_user VALUES(42,'auth_credential_generation',?)")
                ->execute([hash('sha256', 'upgraded-hash') . ':' . $credential]);
        } elseif (in_array($scenario, ['generation', 'tokens', 'sessions'], true)) {
            $table = ['generation' => 'settings_user', 'tokens' => 'user_auth_cache', 'sessions' => 'sessions'][$scenario];
            $event = $scenario === 'generation' ? 'INSERT' : 'DELETE';
            $db->exec("CREATE TRIGGER refuse_write BEFORE $event ON $table BEGIN SELECT RAISE(ABORT,'refused'); END");
        } elseif ($scenario === 'readback') {
            $db->exec("CREATE TRIGGER change_mapping AFTER INSERT ON settings_user BEGIN UPDATE settings_user SET value='invalid' WHERE user_id=42 AND name='auth_credential_generation'; END");
        } elseif ($scenario === 'commit') {
            $db->refuseCommit = true;
        } elseif ($scenario === 'caller') {
            $db->beginTransaction();
            $db->exec("INSERT INTO settings_user VALUES(43,'caller','owned')");
        } elseif ($scenario === 'stale') {
            $credential = str_repeat('a', 64);
        } elseif ($scenario === 'malformed') {
            $credential = "invalid\0";
        } elseif ($scenario === 'missing') {
            $db->exec('DELETE FROM user_auth WHERE id=42');
        }
        $before = self::snapshot($db);
        $connection = $this->createMock(DatabaseConnection::class);
        $connection->expects(self::once())->method('get')->willReturn($db);
        $service = new NativeSessionRevocation($connection);

        self::assertSame($success, $service->revoke($scenario === 'zero' ? 0 : 42, $credential));
        self::assertSame($scenario === 'caller', $db->inTransaction());
        if (!$success) {
            self::assertSame($before, self::snapshot($db));
            if ($scenario === 'caller') {
                $db->rollBack();
                self::assertSame(0, (int) $db->query("SELECT COUNT(*) FROM settings_user WHERE name='caller'")->fetchColumn());
            }
            return;
        }
        self::assertSame($before['user_auth'], self::snapshot($db)['user_auth']);
        self::assertSame('60', $db->query("SELECT value FROM settings_user WHERE user_id=42 AND name='page_refresh'")->fetchColumn());
        self::assertSame([['id' => 'other', 'user_id' => 43, 'data' => 'other-session']], self::snapshot($db)['sessions']);
        self::assertSame([['user_id' => 43, 'token' => 'other-token']], self::snapshot($db)['user_auth_cache']);
        $password = $db->query('SELECT password FROM user_auth WHERE id=42')->fetchColumn();
        $current = \auth_session_credential_generation(42, $password, $db);
        self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/D', $current);
        self::assertNotSame($credential, $current);
        // A second request with the revoked generation cannot rotate it again.
        self::assertFalse(NativeSessionRevocation::onConnection($db, 42, $credential));
        self::assertSame($current, \auth_session_credential_generation(42, $password, $db));
    }

    public static function outcomes(): iterable
    {
        foreach (['normal', 'rehash', 'generation', 'tokens', 'sessions', 'readback', 'commit', 'caller', 'stale', 'malformed', 'missing', 'zero'] as $scenario) {
            yield $scenario => [$scenario, in_array($scenario, ['normal', 'rehash'], true)];
        }
    }

    #[DataProvider('legacyIdentities')]
    public function testLegacyFacadePreservesBoundIdentity(mixed $userId, bool $accepted): void
    {
        require_once dirname(__DIR__, 2) . '/lib/auth.php';
        $db = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $db->exec("CREATE TABLE user_auth(id INTEGER PRIMARY KEY, password TEXT);
            INSERT INTO user_auth VALUES(42,'unchanged-hash');
            CREATE TABLE settings_user(user_id INTEGER,name TEXT,value TEXT,PRIMARY KEY(user_id,name));
            CREATE TABLE sessions(user_id INTEGER); INSERT INTO sessions VALUES(42);
            CREATE TABLE user_auth_cache(user_id INTEGER); INSERT INTO user_auth_cache VALUES(42);");
        self::assertSame($accepted, \auth_logout_everywhere($userId, hash('sha256', 'unchanged-hash'), $db));
        self::assertSame($accepted ? 0 : 1, (int) $db->query('SELECT COUNT(*) FROM sessions')->fetchColumn());
        self::assertSame($accepted ? 0 : 1, (int) $db->query('SELECT COUNT(*) FROM user_auth_cache')->fetchColumn());
        self::assertFalse($db->inTransaction());
    }

    public static function legacyIdentities(): iterable
    {
        yield 'integer' => [42, true];
        yield 'leading zero' => ['042', true];
        yield 'fractional number' => [42.9, false];
        yield 'fractional string' => ['42.9', false];
        yield 'missing' => [null, false];
        yield 'array' => [[42], false];
    }

    private static function snapshot(PDO $db): array
    {
        $state = [];
        foreach (['user_auth', 'settings_user', 'sessions', 'user_auth_cache'] as $table) {
            $state[$table] = $db->query('SELECT * FROM ' . $table . ' ORDER BY 1, 2')->fetchAll(PDO::FETCH_ASSOC);
        }
        return $state;
    }
}
