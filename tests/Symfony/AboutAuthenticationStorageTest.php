<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\IdentityAccess\Contract\AuditTrail;
use Kadupul\IdentityAccess\Infrastructure\Legacy\LegacyBrowserAuthentication;
use Kadupul\IdentityAccess\Infrastructure\Legacy\NativeAuthenticationSession;
use Kadupul\Platform\Contract\DatabaseConnection;
use Kadupul\Platform\Contract\LegacyConfiguration;
use Kadupul\Tests\Fixtures\RealMariaDb;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class AboutAuthenticationStorageTest extends TestCase
{
    use RealMariaDb;

    public static function participants(): iterable
    {
        yield 'persistent Basic admitted' => [null, false];
        yield 'persistent remembered admitted' => [null, true];
        foreach (['settings', 'user_auth', 'settings_user', 'user_log', 'sessions'] as $table) {
            yield $table . ' temporary InnoDB' => [$table, false];
        }
        yield 'remembered cache temporary InnoDB' => ['user_auth_cache', true];
        yield 'MyISAM comment cannot claim InnoDB' => ['user_log', false, true];
    }

    #[DataProvider('participants')]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testActualRestorationRequiresPersistentParticipants(?string $shadow, bool $remembered, bool $spoofedEngine = false): void
    {
        $connection = $this->realMariaDb();
        $db = $connection->getNativeConnection();
        $schema = 'about_restore_' . bin2hex(random_bytes(6));
        $db->exec('CREATE DATABASE `' . $schema . '`');
        $observer = null;
        try {
            $db->exec('USE `' . $schema . '`');
            // The shipped legacy schema includes zero-date defaults; remove only
            // the MySQL zero-date restrictions while creating those exact tables.
            $mode = (string) $db->query('SELECT @@SESSION.sql_mode')->fetchColumn();
            $schemaMode = implode(',', array_diff(explode(',', $mode), ['NO_ZERO_DATE', 'NO_ZERO_IN_DATE']));
            $db->exec('SET SESSION sql_mode = ' . $db->quote($schemaMode));
            $sql = file_get_contents(dirname(__DIR__, 2) . '/cacti.sql');
            self::assertIsString($sql);
            $tables = ['settings', 'user_auth', 'settings_user', 'user_log', 'sessions', 'user_auth_cache'];
            foreach ($tables as $table) {
                self::assertSame(1, preg_match('/^CREATE TABLE `?' . preg_quote($table, '/') . '`? \(.*?^\) ENGINE=InnoDB[^;]*;/ms', $sql, $match));
                $db->exec($match[0]);
            }
            $db->exec('SET SESSION sql_mode = ' . $db->quote($mode));
            $db->exec("INSERT INTO settings (name,value) VALUES ('auth_method','" . ($remembered ? '1' : '2') . "'),('guest_user','guest'),('auth_cache_enabled','on'),('force_https','')");
            $realm = $remembered ? 0 : 2;
            $db->exec("INSERT INTO user_auth (id,username,realm,enabled) VALUES (42,'storage-operator',$realm,'on')");
            $token = str_repeat('a', 64);
            $statement = $db->prepare('INSERT INTO user_auth_cache (user_id,hostname,last_update,token) VALUES (42,?,?,?)');
            $statement->execute(['127.0.0.1', '2026-01-01 00:00:00', hash('sha512', $token)]);
            $observer = $this->realMariaDb();
            $observed = $observer->getNativeConnection();
            self::assertNotSame($db, $observed);
            $observed->exec('USE `' . $schema . '`');
            $snapshot = static fn(): string => hash('sha256', json_encode(array_map(static fn(string $table): array => $observed->query('SELECT * FROM `' . $table . '`')->fetchAll(\PDO::FETCH_ASSOC), $tables), JSON_THROW_ON_ERROR));
            if ($spoofedEngine) {
                $db->exec('SET SESSION sql_mode = ' . $db->quote($schemaMode));
                $db->exec("ALTER TABLE user_log ENGINE=MyISAM COMMENT=') ENGINE=InnoDB'");
                $db->exec('SET SESSION sql_mode = ' . $db->quote($mode));
            }
            $before = $snapshot();
            if ($shadow !== null && !$spoofedEngine) {
                $ddl = $db->query('SHOW CREATE TABLE `' . $shadow . '`')->fetch(\PDO::FETCH_NUM)[1];
                $db->exec('SET SESSION sql_mode = ' . $db->quote($schemaMode));
                $db->exec('CREATE TEMPORARY TABLE ' . substr($ddl, strlen('CREATE TABLE ')));
                $db->exec('SET SESSION sql_mode = ' . $db->quote($mode));
                foreach ($observed->query('SELECT * FROM `' . $shadow . '`')->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                    $copy = $db->prepare('INSERT INTO `' . $shadow . '` (`' . implode('`,`', array_keys($row)) . '`) VALUES (' . implode(',', array_fill(0, count($row), '?')) . ')');
                    $copy->execute(array_values($row));
                }
            }
            $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
            $_COOKIE = $remembered ? ['cacti_remembers' => '42,0,' . $token] : [];
            unset($_SERVER['REMOTE_USER'], $_SERVER['PHP_AUTH_USER'], $_SERVER['REDIRECT_REMOTE_USER']);
            if (!$remembered) {
                $_SERVER['REMOTE_USER'] = 'storage-operator';
            }
            $request = Request::create('/about', 'GET', [], $_COOKIE, server: $_SERVER);
            $request->attributes->set('_route', 'platform_about');
            $requests = new RequestStack();
            $requests->push($request);
            $database = $this->createMock(DatabaseConnection::class);
            $database->method('get')->willReturn($db);
            $configuration = $this->createMock(LegacyConfiguration::class);
            $configuration->method('values')->willReturn(['root' => dirname(__DIR__, 2), 'collector_id' => 1, 'database_sessions' => true, 'session_name' => 'AboutStorageNative', 'url_path' => '/', 'cookie_domain' => '', 'proxy_headers' => []]);
            $authentication = new LegacyBrowserAuthentication($requests, $database, $configuration, new NativeAuthenticationSession($configuration, $database), $this->createMock(AuditTrail::class));
            $error = null;
            $actor = null;
            try {
                $actor = $authentication->restore();
            } catch (\RuntimeException $caught) {
                $error = $caught;
            }
            self::assertFalse($db->inTransaction());
            if ($shadow === null) {
                self::assertNull($error);
                self::assertSame(42, $actor?->id);
                self::assertSame(1, (int) $observed->query('SELECT COUNT(*) FROM sessions')->fetchColumn());
                self::assertStringContainsString('sess_user_credential|s:64:"' . \auth_session_credential_key('') . '";', $observed->query('SELECT data FROM sessions')->fetchColumn());
                self::assertSame(1, (int) $observed->query('SELECT COUNT(*) FROM user_log')->fetchColumn());
            } else {
                self::assertInstanceOf(\RuntimeException::class, $error);
                self::assertSame('Browser authentication requires transactional tables.', $error->getMessage());
                self::assertNull($actor);
                self::assertSame($before, $snapshot());
            }
        } finally {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $observer?->close();
            $db->exec('DROP DATABASE `' . $schema . '`');
            $connection->close();
        }
    }
}
