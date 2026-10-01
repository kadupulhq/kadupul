<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use Kadupul\IdentityAccess\Contract\AuditTrail;
use Kadupul\IdentityAccess\Infrastructure\Legacy\LegacyBrowserAuthentication;
use Kadupul\IdentityAccess\Infrastructure\Legacy\NativeAuthenticationSession;
use Kadupul\Platform\Contract\DatabaseConnection;
use Kadupul\Platform\Contract\LegacyConfiguration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class LegacyBrowserAuthenticationTest extends TestCase
{
    private array $server;
    private array $cookies;

    protected function setUp(): void
    {
        $this->server = $_SERVER;
        $this->cookies = $_COOKIE;
        $_SERVER = ['REMOTE_ADDR' => '127.0.0.1'];
        $_COOKIE = [];
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
        $_COOKIE = $this->cookies;
    }

    private function authentication(Request $request, DatabaseConnection $connection, array $values = []): LegacyBrowserAuthentication
    {
        if (!$request->attributes->has('_route')) {
            $request->attributes->set('_route', 'platform_about');
        }
        $requests = new RequestStack();
        $requests->push($request);
        $configuration = $this->createMock(LegacyConfiguration::class);
        $configuration->method('values')->willReturn($values + ['database_sessions' => false]);
        return new LegacyBrowserAuthentication($requests, $connection, $configuration, new NativeAuthenticationSession($configuration, $connection), $this->createMock(AuditTrail::class));
    }

    public static function forgedIdentities(): iterable
    {
        yield 'unverified native Basic header' => [['PHP_AUTH_USER' => 'account'], [], ['PHP_AUTH_USER' => 'account']];
        yield 'synthetic Basic' => [['PHP_AUTH_USER' => 'account'], [], []];
        yield 'client header' => [['HTTP_REMOTE_USER' => 'account'], [], ['HTTP_REMOTE_USER' => 'account']];
        yield 'synthetic remember cookie' => [[], ['cacti_remembers' => '9,0,token'], []];
        yield 'mismatched native Basic' => [['PHP_AUTH_USER' => 'account'], [], ['PHP_AUTH_USER' => 'different', 'REMOTE_USER' => 'account']];
        yield 'empty first server principal cannot fall back' => [['REMOTE_USER' => '', 'REDIRECT_REMOTE_USER' => 'account'], [], ['REMOTE_USER' => '', 'REDIRECT_REMOTE_USER' => 'account']];
    }

    #[DataProvider('forgedIdentities')]
    public function testUnverifiedIdentityDoesNotReachDatabase(array $server, array $cookies, array $native): void
    {
        $_SERVER += $native;
        $request = Request::create('/about', 'GET', [], $cookies, [], $server + ['REMOTE_ADDR' => '127.0.0.1']);
        $database = $this->createMock(DatabaseConnection::class);
        $database->expects(self::never())->method('get');
        self::assertNull($this->authentication($request, $database)->restore());
    }

    public function testCallerTransactionRemainsOwnedAndUnchanged(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE caller (id INTEGER)');
        $pdo->beginTransaction();
        $pdo->exec('INSERT INTO caller VALUES (9)');
        $_SERVER['PHP_AUTH_USER'] = 'account';
        $_SERVER['REMOTE_USER'] = 'account';
        $request = Request::create('/about', 'GET', [], [], [], $_SERVER);
        $connection = $this->createMock(DatabaseConnection::class);
        $connection->method('get')->willReturn($pdo);
        try {
            $this->authentication($request, $connection)->restore();
            self::fail('An existing caller transaction must be refused.');
        } catch (RuntimeException $failure) {
            self::assertSame('Browser authentication cannot own an existing transaction.', $failure->getMessage());
            self::assertTrue($pdo->inTransaction());
            self::assertSame(9, (int) $pdo->query('SELECT id FROM caller')->fetchColumn());
        } finally {
            $pdo->rollBack();
        }
    }

    public static function rememberedRefusals(): iterable
    {
        yield 'empty token' => ['9,0,', 'on', '127.0.0.1', 'on', '', '1'];
        yield 'malformed realm' => ['9,invalid,token', 'on', '127.0.0.1', 'on', '', '1'];
        yield 'wrong realm' => ['9,2,token', 'on', '127.0.0.1', 'on', '', '1'];
        yield 'wrong token' => ['9,0,wrong', 'on', '127.0.0.1', 'on', '', '1'];
        yield 'wrong client IP' => ['9,0,token', 'on', '192.0.2.9', 'on', '', '1'];
        yield 'cache disabled' => ['9,0,token', '', '127.0.0.1', 'on', '', '1'];
        yield 'account disabled' => ['9,0,token', 'on', '127.0.0.1', '', '', '1'];
        yield 'account locked' => ['9,0,token', 'on', '127.0.0.1', 'on', 'on', '1'];
        yield 'auth policy disabled' => ['9,0,token', 'on', '127.0.0.1', 'on', '', '0'];
        yield 'auth policy malformed' => ['9,0,token', 'on', '127.0.0.1', 'on', '', 'oops'];
    }

    #[DataProvider('rememberedRefusals')]
    public function testRefusedRememberTokenPreservesCredential(string $cookie, string $cache, string $host, string $enabled, string $locked, string $method): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE settings (name TEXT, value TEXT)');
        $statement = $pdo->prepare('INSERT INTO settings VALUES (?, ?)');
        foreach (['auth_method' => $method, 'auth_cache_enabled' => $cache, 'guest_user' => '3', 'force_https' => ''] as $name => $value) {
            $statement->execute([$name, $value]);
        }
        $pdo->exec('CREATE TABLE user_auth (id INTEGER PRIMARY KEY, username TEXT, realm INTEGER, enabled TEXT, locked TEXT)');
        $pdo->prepare('INSERT INTO user_auth VALUES (9, ?, 0, ?, ?)')->execute(['account', $enabled, $locked]);
        $pdo->exec('CREATE TABLE user_auth_cache (id INTEGER PRIMARY KEY, user_id INTEGER, token TEXT, hostname TEXT)');
        $pdo->prepare('INSERT INTO user_auth_cache VALUES (1, 9, ?, ?)')->execute([hash('sha512', 'token'), $host]);
        $_COOKIE = ['cacti_remembers' => $cookie];
        $request = Request::create('/about', 'GET', [], $_COOKIE, [], $_SERVER);
        $connection = $this->createMock(DatabaseConnection::class);
        $connection->method('get')->willReturn($pdo);
        self::assertNull($this->authentication($request, $connection)->restore());
        self::assertFalse($pdo->inTransaction());
        self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM user_auth_cache')->fetchColumn());
    }

    public static function nativeProxyAddresses(): iterable
    {
        yield 'default ignores forwarded header' => [[], ['HTTP_X_FORWARDED_FOR' => '192.0.2.4'], '127.0.0.1'];
        yield 'configured literal server key' => [['HTTP_X_FORWARDED_FOR'], ['HTTP_X_FORWARDED_FOR' => '192.0.2.4'], '192.0.2.4'];
        yield 'legacy display header is not invented HTTP mapping' => [['X-Forwarded-For'], ['HTTP_X_FORWARDED_FOR' => '192.0.2.4'], '127.0.0.1'];
        yield 'unknown key refused' => [['HTTP_EVIL_PROXY'], ['HTTP_EVIL_PROXY' => '192.0.2.4'], '127.0.0.1'];
        yield 'legacy true allowlist' => [true, ['HTTP_X_FORWARDED_FOR' => 'invalid,192.0.2.4'], '192.0.2.4'];
        yield 'invalid address falls back' => [['HTTP_X_FORWARDED_FOR'], ['HTTP_X_FORWARDED_FOR' => 'invalid'], '127.0.0.1'];
    }

    #[DataProvider('nativeProxyAddresses')]
    public function testNativeProxyConfigurationKeepsLegacyLiteralServerConvention(array|bool $configured, array $server, string $expected): void
    {
        $_SERVER += $server;
        $request = Request::create('/about', 'GET', [], [], [], $_SERVER);
        $database = $this->createMock(DatabaseConnection::class);
        $authentication = $this->authentication($request, $database);
        $method = new ReflectionMethod($authentication, 'nativeIp');
        self::assertSame($expected, $method->invoke($authentication, $request, ['proxy_headers' => $configured]));
        foreach (array_keys($server) as $name) {
            $request->server->set($name, '192.0.2.99');
        }
        self::assertSame('127.0.0.1', $method->invoke($authentication, $request, ['proxy_headers' => $configured]));
    }

    public function testBasicMappingUsesConfiguredLegacyCsvAfterDomainStripping(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'about-basic-map-');
        file_put_contents($path, "external,internal\nother,another\n");
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE settings (name TEXT, value TEXT)');
        $pdo->prepare('INSERT INTO settings VALUES (?, ?)')->execute(['path_basic_mapfile', $path]);
        $database = $this->createMock(DatabaseConnection::class);
        $database->method('get')->willReturn($pdo);
        $authentication = $this->authentication(Request::create('/about'), $database);
        $mapping = new ReflectionMethod($authentication, 'mappedBasic');
        try {
            self::assertSame('internal', $mapping->invoke($authentication, $pdo, 'external@example.invalid'));
            self::assertSame('unmatched', $mapping->invoke($authentication, $pdo, 'unmatched@example.invalid'));
            self::assertSame('domain\\\\user', $mapping->invoke($authentication, $pdo, 'domain\\user'));
        } finally {
            unlink($path);
        }
    }


    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCookiePublicationFailureRevokesTheCommittedFileSession(): void
    {
        $directory = sys_get_temp_dir() . '/about-publish-failure-' . bin2hex(random_bytes(8));
        mkdir($directory);
        session_save_path($directory);
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE settings (name TEXT, value TEXT)');
        $pdo->exec("INSERT INTO settings VALUES ('auth_method','2'),('force_https',''),('guest_user','3')");
        $pdo->exec('CREATE TABLE user_auth (id INTEGER PRIMARY KEY, username TEXT, realm INTEGER, enabled TEXT, locked TEXT)');
        $pdo->exec("INSERT INTO user_auth VALUES (9, 'account', 2, 'on', '')");
        $pdo->exec('CREATE TABLE user_log (username TEXT, user_id INTEGER, result INTEGER, ip TEXT, time TEXT)');
        $database = $this->createMock(DatabaseConnection::class);
        $database->method('get')->willReturn($pdo);
        $values = ['root' => $directory, 'session_name' => 'AboutPublishUnit', 'database_sessions' => false, 'url_path' => '/', 'cookie_domain' => ''];
        $invalid = $values;
        $invalid['cookie_domain'] = "invalid\n";
        $configuration = $this->createMock(LegacyConfiguration::class);
        $configuration->method('values')->willReturnOnConsecutiveCalls($values, $values, $invalid, $values, $values);
        $_SERVER['PHP_AUTH_USER'] = 'account';
        $_SERVER['REMOTE_USER'] = 'account';
        $request = Request::create('/about', 'GET', [], [], [], $_SERVER);
        $request->attributes->set('_route', 'platform_about_legacy');
        $requests = new RequestStack();
        $requests->push($request);
        $events = [];
        $audit = $this->createMock(AuditTrail::class);
        $audit->expects(self::exactly(2))->method('record')->willReturnCallback(static function ($event) use (&$events): void {
            $events[] = $event->outcome;
        });
        $authentication = new LegacyBrowserAuthentication($requests, $database, $configuration, new NativeAuthenticationSession($configuration, $database), $audit);
        try {
            try {
                $authentication->restore();
                self::fail('Invalid cookie publication must not return an actor.');
            } catch (ValueError $failure) {
                self::assertSame(['succeeded', 'failed'], $events);
                self::assertSame([], glob($directory . '/sess_*'));
                self::assertSame(PHP_SESSION_NONE, session_status());
                self::assertFalse($pdo->inTransaction());
                // Commit was real; cleanup must revoke the separately persisted file.
                self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM user_log')->fetchColumn());
            }
        } finally {
            foreach (glob($directory . '/sess_*') as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }


    public function testProductionLogPrimaryKeyKeepsLegacySameSecondDeduplication(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE user_log (username TEXT, user_id INTEGER, result INTEGER, ip TEXT, time TEXT, PRIMARY KEY(username,user_id,time))');
        $database = $this->createMock(DatabaseConnection::class);
        $database->method('get')->willReturn($pdo);
        $authentication = $this->authentication(Request::create('/about'), $database);
        $log = new ReflectionMethod($authentication, 'log');
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $pdo->exec('DELETE FROM user_log');
            $time = $pdo->query('SELECT CURRENT_TIMESTAMP')->fetchColumn();
            $pdo->prepare('INSERT INTO user_log VALUES (?, ?, ?, ?, ?)')->execute(['account', 9, 0, '192.0.2.4', $time]);
            $log->invoke($authentication, $pdo, 'account', 9, 1, '127.0.0.1');
            $log->invoke($authentication, $pdo, 'account', 9, 2, '127.0.0.1');
            if ($pdo->query('SELECT CURRENT_TIMESTAMP')->fetchColumn() !== $time) {
                continue;
            }
            self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM user_log')->fetchColumn());
            self::assertSame(0, (int) $pdo->query('SELECT result FROM user_log')->fetchColumn());
            return;
        }
        self::fail('Could not exercise both real writes within the same database second.');
    }

    public function testIgnoredLogInsertWithoutExactExistingPrimaryKeyIsRefused(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE user_log (username TEXT, user_id INTEGER, result INTEGER, ip TEXT, time TEXT, PRIMARY KEY(username,user_id,time))');
        $pdo->prepare('INSERT INTO user_log VALUES (?, ?, ?, ?, ?)')->execute(['other', 9, 1, '127.0.0.1', '2000-01-01 00:00:00']);
        $pdo->exec('CREATE TRIGGER ignore_log BEFORE INSERT ON user_log BEGIN SELECT RAISE(IGNORE); END');
        $database = $this->createMock(DatabaseConnection::class);
        $database->method('get')->willReturn($pdo);
        $authentication = $this->authentication(Request::create('/about'), $database);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Browser authentication log insertion was not confirmed.');
        (new ReflectionMethod($authentication, 'log'))->invoke($authentication, $pdo, 'account', 9, 1, '127.0.0.1');
    }

    public function testLogDeduplicationConfirmationChecksLateDriverFailure(): void
    {
        $clock = $this->createMock(PDOStatement::class);
        $clock->method('execute')->willReturn(true);
        $clock->method('errorCode')->willReturn('00000');
        $clock->method('fetchColumn')->willReturn('2026-10-01 12:00:00');
        $insert = $this->createMock(PDOStatement::class);
        $insert->method('execute')->willReturn(true);
        $insert->method('errorCode')->willReturn('00000');
        $insert->method('rowCount')->willReturn(0);
        $confirmation = $this->createMock(PDOStatement::class);
        $confirmation->method('execute')->willReturn(true);
        $confirmation->method('fetchColumn')->willReturn(1);
        $confirmation->method('errorCode')->willReturnOnConsecutiveCalls('00000', '08006');
        $confirmation->method('errorInfo')->willReturn(['08006', 7, 'late confirmation failure']);
        $pdo = $this->createMock(PDO::class);
        $pdo->method('getAttribute')->willReturn('sqlite');
        $pdo->method('prepare')->willReturnOnConsecutiveCalls($clock, $insert, $confirmation);
        $database = $this->createMock(DatabaseConnection::class);
        $database->method('get')->willReturn($pdo);
        $authentication = $this->authentication(Request::create('/about'), $database);
        try {
            (new ReflectionMethod($authentication, 'log'))->invoke($authentication, $pdo, 'account', 9, 1, '127.0.0.1');
            self::fail('A failed confirmation read cannot certify an existing log.');
        } catch (PDOException $failure) {
            self::assertSame(['08006', 7, 'late confirmation failure'], $failure->errorInfo);
        }
    }


    public static function unrelatedRestorationRoutes(): iterable
    {
        yield 'missing route' => [null];
        yield 'console session' => ['platform_session'];
        yield 'unrelated About prefix' => ['platform_about_other'];
        yield 'unrelated page' => ['inventory_sites_list'];
    }

    #[DataProvider('unrelatedRestorationRoutes')]
    public function testRestorationNeverCrossesAboutRouteBoundary(?string $route): void
    {
        $_SERVER['REMOTE_USER'] = 'account';
        $_SERVER['PHP_AUTH_USER'] = 'account';
        $request = Request::create('/about', 'GET', [], [], [], $_SERVER);
        $request->attributes->set('_route', $route);
        $database = $this->createMock(DatabaseConnection::class);
        $database->expects(self::never())->method('get');
        self::assertNull($this->authentication($request, $database)->restore());
    }


    public static function unconfirmedIsolationStates(): iterable
    {
        yield 'null state' => [null];
        yield 'late failure' => ['08006'];
    }

    #[DataProvider('unconfirmedIsolationStates')]
    public function testPositiveIsolationExecWithoutConfirmedStateCannotBegin(?string $state): void
    {
        $statement = $this->createMock(PDOStatement::class);
        $statement->method('execute')->willReturn(true);
        $statement->method('errorCode')->willReturn('00000');
        $statement->method('fetch')->willReturn(['Create Table' => 'CREATE TABLE fixture (id INTEGER) ENGINE=InnoDB']);
        $pdo = $this->createMock(PDO::class);
        $pdo->method('getAttribute')->willReturn('mysql');
        $pdo->method('inTransaction')->willReturn(false);
        $pdo->method('prepare')->willReturn($statement);
        $pdo->expects(self::once())->method('exec')->with('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ')->willReturn(0);
        $pdo->method('errorCode')->willReturn($state);
        $pdo->method('errorInfo')->willReturn([$state, 7, 'late isolation failure']);
        $pdo->expects(self::never())->method('beginTransaction');
        $connection = $this->createMock(DatabaseConnection::class);
        $connection->method('get')->willReturn($pdo);
        $_SERVER['REMOTE_USER'] = 'account';
        $_SERVER['PHP_AUTH_USER'] = 'account';
        $request = Request::create('/about', 'GET', [], [], [], $_SERVER);
        try {
            $this->authentication($request, $connection)->restore();
            self::fail('Unknown isolation state must not begin a mutation.');
        } catch (PDOException $failure) {
            self::assertSame([$state, 7, 'late isolation failure'], $failure->errorInfo);
        }
    }

}
