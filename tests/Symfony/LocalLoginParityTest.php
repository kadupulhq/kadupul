<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Kadupul\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Kadupul\Tests\Fixtures\RealMariaDb;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

require_once __DIR__ . '/Fixtures/RealMariaDb.php';

/**
 * The ADR 0010 parity matrix for the local login method (auth_method 1) and the
 * remembered login, pinned against the legacy code as it is today. Each request
 * goes over HTTP to the real auth_login.php or include/auth.php running under
 * `php -S` against a throwaway MariaDB schema built from cacti.sql, so a Symfony
 * authenticator can later be held to the same response, user_log row, AUTH log
 * line, session keys, lockout counters and timing floor.
 *
 * Some pinned results look wrong; their test names say so. They are recorded,
 * not endorsed, so that the replacement changes them on purpose or not at all.
 */
final class LocalLoginParityTest extends TestCase
{
    use RealMariaDb;

    private const string USER = 'parity-user';
    // Obviously fake fixture credential; never a real secret.
    private const string PASSWORD = 'Parity-Fixture-Only-1!';
    private const int USER_ID = 10;
    private const int GUEST_ID = 3;

    private ?Connection $admin = null;
    private ?Connection $database = null;
    private string $schema = '';
    private string $directory = '';
    private ?Process $server = null;
    private int $port = 0;

    protected function setUp(): void
    {
        $this->admin = $this->realMariaDb(true);
        $parameters = $this->admin->getParams();
        $this->schema = 'local_login_parity_' . bin2hex(random_bytes(6));
        $this->admin->executeStatement('CREATE DATABASE `' . $this->schema . '` CHARACTER SET utf8mb4');
        $this->database = DriverManager::getConnection(['dbname' => $this->schema] + $parameters);

        $source = file_get_contents(dirname(__DIR__, 2) . '/cacti.sql');
        self::assertIsString($source);
        $mode = (string) $this->database->fetchOne('SELECT @@SESSION.sql_mode');
        $this->database->executeStatement('SET SESSION sql_mode = ' . $this->database->quote(implode(',', array_diff(explode(',', $mode), ['NO_ZERO_DATE', 'NO_ZERO_IN_DATE']))));
        foreach (['settings', 'settings_user', 'user_auth', 'user_auth_cache', 'user_auth_realm', 'user_auth_group', 'user_auth_group_members', 'user_auth_group_realm', 'user_log'] as $table) {
            self::assertSame(1, preg_match('/^CREATE TABLE `?' . $table . '`? \(.*?^\) ENGINE=[^;]*;/ms', $source, $match), $table);
            $this->database->executeStatement($match[0]);
        }
        $this->database->executeStatement('SET SESSION sql_mode = ' . $this->database->quote($mode));

        $this->directory = sys_get_temp_dir() . '/local-login-parity-' . bin2hex(random_bytes(6));
        foreach (['', '/sessions', '/include-path'] as $child) {
            mkdir($this->directory . $child, 0700, true);
        }
        file_put_contents($this->directory . '/include-path/global.php', "<?php\n");
        touch($this->directory . '/cacti.log');

        $settings = [
            'auth_method' => '1',
            'auth_cache_enabled' => 'on',
            'guest_user' => '0',
            'user_template' => '0',
            'secpass_lockfailed' => '3',
            'secpass_unlocktime' => '15',
            'secpass_forceold' => '',
            'secpass_minlen' => '8',
            'secpass_expireaccount' => '0',
            'log_destination' => '1',
            'log_verbosity' => '2',
            'path_cactilog' => $this->directory . '/cacti.log',
            'ldap_tls_certificate' => '2',
        ];
        foreach ($settings as $name => $value) {
            $this->database->insert('settings', ['name' => $name, 'value' => $value]);
        }
        $this->database->insert('user_auth', ['id' => self::USER_ID, 'username' => self::USER, 'password' => password_hash(self::PASSWORD, PASSWORD_DEFAULT), 'realm' => 0, 'login_opts' => 3, 'must_change_password' => '', 'password_change' => 'on', 'enabled' => 'on']);
        $this->database->insert('user_auth_realm', ['user_id' => self::USER_ID, 'realm_id' => 7]);
        // The guest row cacti.sql ships, disabled as shipped.
        $this->database->insert('user_auth', ['id' => self::GUEST_ID, 'username' => 'guest', 'password' => '', 'realm' => 0, 'login_opts' => 3, 'enabled' => '']);
        $this->database->insert('user_auth_realm', ['user_id' => self::GUEST_ID, 'realm_id' => 7]);

        $this->startServer($parameters);
    }

    protected function tearDown(): void
    {
        $this->server?->stop(0);
        $this->database?->close();
        if ($this->admin !== null && $this->schema !== '') {
            $this->admin->executeStatement('DROP DATABASE IF EXISTS `' . $this->schema . '`');
            $this->admin->close();
        }
        if ($this->directory !== '' && is_dir($this->directory)) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($this->directory);
        }
    }

    /** @param array<string, mixed> $parameters */
    private function startServer(array $parameters): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($socket);
        $this->port = (int) substr((string) strrchr((string) stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);

        $root = dirname(__DIR__, 2);
        $this->server = new Process(
            [PHP_BINARY, '-d', 'session.save_path=' . $this->directory . '/sessions', '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', 'error_log=' . $this->directory . '/php-errors.log', '-S', '127.0.0.1:' . $this->port, $root . '/tests/Fixtures/local-login-parity-router.php'],
            $root,
            [
                'KADUPUL_PARITY_STATE' => $this->directory,
                'KADUPUL_PARITY_DB_NAME' => $this->schema,
                'KADUPUL_PARITY_DB_HOST' => (string) ($parameters['host'] ?? '127.0.0.1'),
                'KADUPUL_PARITY_DB_PORT' => (string) ($parameters['port'] ?? 3306),
                'KADUPUL_PARITY_DB_USER' => (string) ($parameters['user'] ?? ''),
                'KADUPUL_PARITY_DB_PASSWORD' => (string) ($parameters['password'] ?? ''),
            ],
            null,
            null,
        );
        $this->server->start();
        for ($attempt = 0; $attempt < 100; $attempt++) {
            if (@file_get_contents('http://127.0.0.1:' . $this->port . '/__health') === 'ready') {
                return;
            }
            usleep(50000);
        }
        self::fail('php -S did not start: ' . $this->server->getErrorOutput());
    }

    /**
     * @param array<string, string> $form
     * @param array<string, string> $cookies
     * @return array{status: int, location: ?string, cookies: array<string, string>, body: string, session: array<string, mixed>, elapsed_ms: int}
     */
    private function request(string $path, array $form = [], array $cookies = []): array
    {
        @unlink($this->directory . '/state.json');
        $headers = ['Host: 127.0.0.1:' . $this->port];
        if ($cookies !== []) {
            $headers[] = 'Cookie: ' . implode('; ', array_map(static fn(string $name, string $value): string => $name . '=' . rawurlencode($value), array_keys($cookies), $cookies));
        }
        $options = ['ignore_errors' => true, 'follow_location' => 0, 'timeout' => 30, 'method' => $form === [] ? 'GET' : 'POST'];
        if ($form !== []) {
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            $options['content'] = http_build_query($form);
        }
        $options['header'] = implode("\r\n", $headers);
        $body = file_get_contents('http://127.0.0.1:' . $this->port . $path, false, stream_context_create(['http' => $options]));
        self::assertIsString($body);
        /** @var list<string> $http_response_header */
        self::assertSame(1, preg_match('#^HTTP/\S+ (\d{3})#', $http_response_header[0], $status));
        $location = null;
        $set = [];
        foreach (array_slice($http_response_header, 1) as $line) {
            [$name, $value] = array_map('trim', explode(':', $line, 2)) + [1 => ''];
            if (strcasecmp($name, 'Location') === 0) {
                $location = $value;
            } elseif (strcasecmp($name, 'Set-Cookie') === 0) {
                [$pair] = explode(';', $value, 2);
                [$cookie, $content] = explode('=', $pair, 2) + [1 => ''];
                $set[$cookie] = rawurldecode($content);
            }
        }
        $state = json_decode((string) file_get_contents($this->directory . '/state.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($state);
        $errors = is_file($this->directory . '/php-errors.log') ? (string) file_get_contents($this->directory . '/php-errors.log') : '';
        self::assertSame('', $errors, 'The legacy request raised PHP errors.');

        return ['status' => (int) $status[1], 'location' => $location, 'cookies' => $set, 'body' => $body, 'session' => $state['session'], 'elapsed_ms' => $state['elapsed_ms']];
    }

    /**
     * @param array<string, string> $extra
     * @return array{status: int, location: ?string, cookies: array<string, string>, body: string, session: array<string, mixed>, elapsed_ms: int}
     */
    private function login(string $username, string $password, array $extra = []): array
    {
        // csrf_check() is replaced in the router; the field must still be present.
        return $this->request('/auth_login.php', ['__csrf_magic' => 'fixture', 'action' => 'login','login_username' => $username, 'login_password' => $password, 'realm' => '0'] + $extra);
    }

    /** @return list<array{username: string, user_id: int, result: int, ip: string}> */
    private function userLog(): array
    {
        return array_map(
            static fn(array $row): array => ['username' => (string) $row['username'], 'user_id' => (int) $row['user_id'], 'result' => (int) $row['result'], 'ip' => (string) $row['ip']],
            $this->database->fetchAllAssociative('SELECT username, user_id, result, ip FROM user_log ORDER BY time, result'),
        );
    }

    /** @return list<string> the AUTH lines of cacti.log without their timestamps */
    private function authLog(): array
    {
        $lines = file($this->directory . '/cacti.log', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $auth = [];
        foreach ($lines as $line) {
            if (preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d - AUTH (.*)$/', $line, $match) === 1) {
                $auth[] = $match[1];
            }
        }

        return $auth;
    }

    /** @return array{failed_attempts: int, locked: string, must_change_password: string} */
    private function account(int $id = self::USER_ID): array
    {
        $row = $this->database->fetchAssociative('SELECT failed_attempts, locked, must_change_password FROM user_auth WHERE id = ?', [$id]);
        self::assertIsArray($row);

        return ['failed_attempts' => (int) $row['failed_attempts'], 'locked' => (string) $row['locked'], 'must_change_password' => (string) $row['must_change_password']];
    }

    private function storedHash(int $id = self::USER_ID): string
    {
        return (string) $this->database->fetchOne('SELECT password FROM user_auth WHERE id = ?', [$id]);
    }

    private function loginError(string $body): string
    {
        self::assertSame(1, preg_match("#<div class='loginErrors'>\s*(.*?)\s*</div>#s", $body, $match), 'The response is not the login page.');

        return $match[1];
    }

    private function assertFloor(array $response): void
    {
        self::assertGreaterThanOrEqual(1000, $response['elapsed_ms'], 'The local login returned before the 1000 ms floor.');
    }

    // ---- Password login (auth_method 1) ----

    public function testSuccessRedirectsToLoginOptionAndBindsTheSessionToTheCredential(): void
    {
        $response = $this->login(self::USER, self::PASSWORD);

        self::assertSame(302, $response['status']);
        self::assertSame('/graph_view.php', $response['location']);
        self::assertSame(['sess_user_id' => self::USER_ID, 'sess_user_credential' => hash('sha256', $this->storedHash())], $response['session']);
        self::assertArrayHasKey('Cacti', $response['cookies']);
        self::assertArrayNotHasKey('cacti_remembers', $response['cookies']);
        self::assertSame([['username' => self::USER, 'user_id' => self::USER_ID, 'result' => 1, 'ip' => '127.0.0.1']], $this->userLog());
        self::assertSame(["LOGIN: User 'parity-user' authenticated from IP Address '127.0.0.1'"], $this->authLog());
        self::assertSame(['failed_attempts' => 0, 'locked' => '', 'must_change_password' => ''], $this->account());
        $this->assertFloor($response);
    }

    public function testSuccessWithRememberMeIssuesAnUnversionedToken(): void
    {
        $response = $this->login(self::USER, self::PASSWORD, ['remember_me' => 'on']);

        self::assertSame(302, $response['status']);
        // A password login issues the unversioned form; only a restore converts it to v1.
        self::assertMatchesRegularExpression('/\A10,0,[a-f0-9]{64}\z/D', $response['cookies']['cacti_remembers'] ?? '');
        $token = explode(',', $response['cookies']['cacti_remembers'])[2];
        self::assertSame([['user_id' => self::USER_ID, 'hostname' => '127.0.0.1', 'token' => hash('sha512', $token)]], $this->cacheRows());
        self::assertSame(['user_id' => self::USER_ID, 'hash' => hash('sha512', $token)], $response['session']['sess_remember_token']);
        self::assertSame([['username' => self::USER, 'user_id' => self::USER_ID, 'result' => 1, 'ip' => '127.0.0.1']], $this->userLog());
    }

    public function testWrongPasswordRendersTheLoginPageAndCountsTheFailure(): void
    {
        $response = $this->login(self::USER, 'Wrong-Fixture-2!');

        self::assertSame(200, $response['status']);
        self::assertNull($response['location']);
        self::assertSame('Access Denied!  Login Failed.', $this->loginError($response['body']));
        self::assertSame([], $response['session']);
        self::assertSame(1, $this->account()['failed_attempts']);
        self::assertSame('', $this->account()['locked']);
        self::assertSame([
            "LOGIN FAILED: User 'parity-user' failed authentication, incrementing lockout (1 of 3)",
            "LOGIN FAILED: Local Login Failed for user 'parity-user' from IP Address '127.0.0.1'.",
            "LOGIN FAILED: Local Login Failed for user 'parity-user' from IP Address '127.0.0.1'.",
        ], $this->authLog());
        $this->assertFailureRowsOnly(self::USER, self::USER_ID);
        $this->assertFloor($response);
    }

    /**
     * auth_process_lockout() and auth_login.php each insert a result 0 row for
     * one wrong password, about a timing floor apart. user_log's key is
     * (username, user_id, time) at one-second resolution and both use INSERT
     * IGNORE, so the attempt leaves one or two rows depending on where the
     * second boundary falls. Only the distinct row is stable.
     */
    public function testWrongPasswordWritesOneOrTwoIdenticalFailureRowsDependingOnTheClock(): void
    {
        $this->login(self::USER, 'Wrong-Fixture-2!');

        $rows = $this->userLog();
        self::assertContains(count($rows), [1, 2]);
        self::assertSame([['username' => self::USER, 'user_id' => self::USER_ID, 'result' => 0, 'ip' => '127.0.0.1']], array_values(array_unique($rows, SORT_REGULAR)));
    }

    public function testThirdWrongPasswordLocksTheAccountAndSaysSo(): void
    {
        $this->database->update('user_auth', ['failed_attempts' => 2, 'lastfail' => time()], ['id' => self::USER_ID]);

        $response = $this->login(self::USER, 'Wrong-Fixture-2!');

        self::assertSame(200, $response['status']);
        self::assertSame('Your account has been locked.  Please contact your Administrator.', $this->loginError($response['body']));
        self::assertSame(['failed_attempts' => 3, 'locked' => 'on', 'must_change_password' => ''], $this->account());
        self::assertSame([
            "LOGIN FAILED: User 'parity-user' failed authentication, incrementing lockout (3 of 3)",
            "LOGIN FAILED: Local Login Failed for user 'parity-user' from IP Address '127.0.0.1'.  Account is locked out.",
            "LOGIN FAILED: Local Login Failed for user 'parity-user' from IP Address '127.0.0.1'.",
        ], $this->authLog());
        $this->assertFailureRowsOnly(self::USER, self::USER_ID);
        $this->assertFloor($response);
    }

    public function testUnknownUserGetsTheWrongPasswordMessageAndAnAnonymousFailureRow(): void
    {
        $response = $this->login('parity-nobody', 'Wrong-Fixture-2!');

        self::assertSame(200, $response['status']);
        self::assertSame('Access Denied!  Login Failed.', $this->loginError($response['body']));
        self::assertSame([], $response['session']);
        self::assertSame([['username' => 'parity-nobody', 'user_id' => 0, 'result' => 0, 'ip' => '127.0.0.1']], $this->userLog());
        self::assertSame([
            'LOGIN FAILED: Invalid user parity-nobody specified.',
            "LOGIN FAILED: Local Login Failed for user 'parity-nobody' from IP Address '127.0.0.1'.",
        ], $this->authLog());
        self::assertSame(0, $this->account()['failed_attempts']);
        $this->assertFloor($response);
    }

    public function testDisabledAccountWithTheRightPasswordIsRefusedLikeAWrongPassword(): void
    {
        $this->database->update('user_auth', ['enabled' => ''], ['id' => self::USER_ID]);

        $response = $this->login(self::USER, self::PASSWORD);

        self::assertSame(200, $response['status']);
        self::assertSame('Access Denied!  Login Failed.', $this->loginError($response['body']));
        self::assertSame([], $response['session']);
        self::assertSame([['username' => self::USER, 'user_id' => self::USER_ID, 'result' => 0, 'ip' => '127.0.0.1']], $this->userLog());
        self::assertSame([
            'LOGIN FAILED: User parity-user, account disabled.',
            "LOGIN FAILED: Local Login Failed for user 'parity-user' from IP Address '127.0.0.1'.",
        ], $this->authLog());
        self::assertSame(0, $this->account()['failed_attempts']);
        $this->assertFloor($response);
    }

    /** The locked message differs from the wrong-password one, so it tells a caller the account exists and is locked. */
    public function testLockedAccountWithTheRightPasswordIsRefusedWithADistinctMessage(): void
    {
        $this->database->update('user_auth', ['locked' => 'on', 'failed_attempts' => 3, 'lastfail' => time()], ['id' => self::USER_ID]);

        $response = $this->login(self::USER, self::PASSWORD);

        self::assertSame(200, $response['status']);
        self::assertSame('Your account has been locked.  Please contact your Administrator.', $this->loginError($response['body']));
        self::assertSame([], $response['session']);
        self::assertSame([['username' => self::USER, 'user_id' => self::USER_ID, 'result' => 0, 'ip' => '127.0.0.1']], $this->userLog());
        self::assertSame(["LOGIN FAILED: Local Login Failed for user 'parity-user' from IP Address '127.0.0.1'."], $this->authLog());
        self::assertSame(['failed_attempts' => 3, 'locked' => 'on', 'must_change_password' => ''], $this->account());
        $this->assertFloor($response);
    }

    public function testLockoutOlderThanTheUnlockTimeIsClearedAndTheLoginSucceeds(): void
    {
        $this->database->update('user_auth', ['locked' => 'on', 'failed_attempts' => 3, 'lastfail' => time() - 16 * 60], ['id' => self::USER_ID]);

        $response = $this->login(self::USER, self::PASSWORD);

        self::assertSame(302, $response['status']);
        self::assertSame(self::USER_ID, $response['session']['sess_user_id'] ?? null);
        self::assertSame(['failed_attempts' => 0, 'locked' => '', 'must_change_password' => ''], $this->account());
        self::assertSame([['username' => self::USER, 'user_id' => self::USER_ID, 'result' => 1, 'ip' => '127.0.0.1']], $this->userLog());
        $this->assertFloor($response);
    }

    /**
     * An expired password is the row poller_maintenance.php leaves behind
     * (secpass_check_expired sets must_change_password). The login completes and
     * the next gated request goes to the change page.
     */
    public function testExpiredPasswordLogsInAndSendsTheNextRequestToTheChangePage(): void
    {
        $this->database->update('user_auth', ['must_change_password' => 'on', 'lastchange' => time() - 400 * 86400], ['id' => self::USER_ID]);

        $response = $this->login(self::USER, self::PASSWORD);

        self::assertSame(302, $response['status']);
        self::assertSame('/graph_view.php', $response['location']);
        self::assertSame(['sess_user_id' => self::USER_ID, 'sess_user_credential' => hash('sha256', $this->storedHash()), 'sess_change_password' => true], $response['session']);
        self::assertSame([['username' => self::USER, 'user_id' => self::USER_ID, 'result' => 1, 'ip' => '127.0.0.1']], $this->userLog());
        self::assertSame(["LOGIN: User 'parity-user' authenticated from IP Address '127.0.0.1'"], $this->authLog());
        $this->assertFloor($response);

        $next = $this->request('/gated.php', [], ['Cacti' => $response['cookies']['Cacti']]);
        self::assertSame(302, $next['status']);
        self::assertSame('/auth_changepassword.php?ref=index.php', $next['location']);
    }

    /** must_change_password is ignored when the account may not change its password. */
    public function testExpiredPasswordOnAnAccountThatMayNotChangeItLogsInWithoutAForcedChange(): void
    {
        $this->database->update('user_auth', ['must_change_password' => 'on', 'password_change' => ''], ['id' => self::USER_ID]);

        $response = $this->login(self::USER, self::PASSWORD);

        self::assertSame(302, $response['status']);
        self::assertArrayNotHasKey('sess_change_password', $response['session']);
        self::assertSame(self::USER_ID, $response['session']['sess_user_id'] ?? null);
    }

    public function testForcedChangeForAPasswordFailingTheRulesFlagsTheAccountAndTheSession(): void
    {
        $this->database->update('settings', ['value' => 'on'], ['name' => 'secpass_forceold']);
        $this->database->update('settings', ['value' => '32'], ['name' => 'secpass_minlen']);

        $response = $this->login(self::USER, self::PASSWORD);

        self::assertSame(302, $response['status']);
        self::assertSame('/graph_view.php', $response['location']);
        self::assertTrue($response['session']['sess_change_password'] ?? null);
        self::assertSame('on', $this->account()['must_change_password']);
        self::assertSame([['username' => self::USER, 'user_id' => self::USER_ID, 'result' => 1, 'ip' => '127.0.0.1']], $this->userLog());
        $this->assertFloor($response);
    }

    public function testForcedChangeOnAnAccountThatMayNotChangeItsPasswordRefusesTheLogin(): void
    {
        $this->database->update('settings', ['value' => 'on'], ['name' => 'secpass_forceold']);
        $this->database->update('settings', ['value' => '32'], ['name' => 'secpass_minlen']);
        $this->database->update('user_auth', ['password_change' => ''], ['id' => self::USER_ID]);

        $response = $this->login(self::USER, self::PASSWORD);

        self::assertSame(200, $response['status']);
        self::assertSame('Access Denied!  Login Failed.', $this->loginError($response['body']));
        self::assertSame([], $response['session']);
        self::assertSame([['username' => self::USER, 'user_id' => self::USER_ID, 'result' => 0, 'ip' => '127.0.0.1']], $this->userLog());
        self::assertSame([
            'LOGIN FAILED: User parity-user has a password that fails the complexity rules and may not change it.',
            "LOGIN FAILED: Local Login Failed for user 'parity-user' from IP Address '127.0.0.1'.",
        ], $this->authLog());
        self::assertSame('', $this->account()['must_change_password']);
        $this->assertFloor($response);
    }

    /** With a guest configured, a failed local login still fails: the guest fallback needs a login without an error. */
    public function testFailedLocalLoginDoesNotFallBackToTheGuest(): void
    {
        $this->database->update('settings', ['value' => 'guest'], ['name' => 'guest_user']);

        $response = $this->login('parity-nobody', 'Wrong-Fixture-2!');

        self::assertSame(200, $response['status']);
        self::assertSame('Access Denied!  Login Failed.', $this->loginError($response['body']));
        self::assertSame([], $response['session']);
        self::assertSame([['username' => 'parity-nobody', 'user_id' => 0, 'result' => 0, 'ip' => '127.0.0.1']], $this->userLog());
        $this->assertFloor($response);
    }

    /** The guest page fallback admits the shipped guest even though its row is disabled, and writes no user_log row. */
    public function testGuestPageWithoutASessionRunsAsTheGuest(): void
    {
        $this->database->update('settings', ['value' => 'guest'], ['name' => 'guest_user']);

        $response = $this->request('/gated.php?guest=1');

        self::assertSame(200, $response['status']);
        self::assertSame('GATED:' . self::GUEST_ID, $response['body']);
        self::assertSame(['sess_user_id' => self::GUEST_ID, 'sess_user_credential' => hash('sha256', '')], $response['session']);
        self::assertSame([], $this->userLog());
        self::assertSame([], $this->authLog());
    }

    public function testGuestPageWithoutAConfiguredGuestShowsTheLoginPage(): void
    {
        $response = $this->request('/gated.php?guest=1');

        self::assertSame(200, $response['status']);
        self::assertSame('', $this->loginError($response['body']));
        self::assertSame([], $response['session']);
    }

    /** A refused login is recorded as result 1 (success) in user_log before the access check fails. */
    public function testNoRealmAccessRefusesTheLoginButRecordsASuccessRow(): void
    {
        $this->database->delete('user_auth_realm', ['user_id' => self::USER_ID]);

        $response = $this->login(self::USER, self::PASSWORD);

        self::assertSame(200, $response['status']);
        self::assertSame('You do not have access to any area of Kadupul.  Contact your administrator.', $this->loginError($response['body']));
        self::assertSame([], $response['session']);
        self::assertSame([['username' => self::USER, 'user_id' => self::USER_ID, 'result' => 1, 'ip' => '127.0.0.1']], $this->userLog());
        self::assertSame([
            "LOGIN: User 'parity-user' authenticated from IP Address '127.0.0.1'",
            'LOGIN FAILED: User parity-user with id 10 does not have access to any area of Kadupul.',
        ], $this->authLog());
        self::assertSame(0, $this->account()['failed_attempts']);
        $this->assertFloor($response);
    }

    public function testGroupRealmCountsAsAccess(): void
    {
        $this->database->delete('user_auth_realm', ['user_id' => self::USER_ID]);
        $this->database->insert('user_auth_group', ['id' => 5, 'name' => 'parity-group', 'enabled' => 'on', 'login_opts' => 3]);
        $this->database->insert('user_auth_group_members', ['group_id' => 5, 'user_id' => self::USER_ID]);
        $this->database->insert('user_auth_group_realm', ['group_id' => 5, 'realm_id' => 7]);

        $response = $this->login(self::USER, self::PASSWORD);

        self::assertSame(302, $response['status']);
        self::assertSame('/graph_view.php', $response['location']);
        self::assertSame(self::USER_ID, $response['session']['sess_user_id'] ?? null);
        self::assertSame([['username' => self::USER, 'user_id' => self::USER_ID, 'result' => 1, 'ip' => '127.0.0.1']], $this->userLog());
    }

    // ---- Remembered login (cacti_remembers) through include/auth.php ----

    public function testLegacyIssuedUnversionedTokenRestoresAndIsConvertedAndRotated(): void
    {
        $issued = $this->login(self::USER, self::PASSWORD, ['remember_me' => 'on']);
        $old = $issued['cookies']['cacti_remembers'];
        $this->database->executeStatement('DELETE FROM user_log');
        file_put_contents($this->directory . '/cacti.log', '');

        $response = $this->request('/gated.php', [], ['cacti_remembers' => $old]);

        $generation = hash('sha256', $this->storedHash());
        $this->assertRestored($response, $old, $generation);
    }

    public function testVersionedTokenOfTheCurrentGenerationRestoresAndRotates(): void
    {
        $generation = bin2hex(random_bytes(32));
        $this->database->insert('settings_user', ['user_id' => self::USER_ID, 'name' => 'auth_credential_generation', 'value' => hash('sha256', $this->storedHash()) . ':' . $generation]);
        $cookie = $this->seedToken($this->versionedToken($generation));

        $response = $this->request('/gated.php', [], ['cacti_remembers' => $cookie]);

        $this->assertRestored($response, $cookie, $generation);
    }

    public function testRotatedTokenCannotBeReplayed(): void
    {
        $cookie = $this->seedToken(bin2hex(random_bytes(32)));
        self::assertSame('GATED:' . self::USER_ID, $this->request('/gated.php', [], ['cacti_remembers' => $cookie])['body']);

        $this->assertRefused($this->request('/gated.php', [], ['cacti_remembers' => $cookie]), 1);
    }

    public function testStaleGenerationTokenIsRefused(): void
    {
        // Logout Everywhere wrote a new generation; the token was MACed with the old one.
        $old = hash('sha256', $this->storedHash());
        $this->database->insert('settings_user', ['user_id' => self::USER_ID, 'name' => 'auth_credential_generation', 'value' => $old . ':' . bin2hex(random_bytes(32))]);
        $cookie = $this->seedToken($this->versionedToken($old));

        $this->assertRefused($this->request('/gated.php', [], ['cacti_remembers' => $cookie]));
    }

    public function testTokenFromAnotherClientAddressIsRefused(): void
    {
        $cookie = $this->seedToken(bin2hex(random_bytes(32)), '192.0.2.10');

        $this->assertRefused($this->request('/gated.php', [], ['cacti_remembers' => $cookie]));
    }

    public function testTokenForTheGuestAccountIsRefused(): void
    {
        // An enabled guest, so the guest check is the only reason to refuse.
        $this->database->update('settings', ['value' => 'guest'], ['name' => 'guest_user']);
        $this->database->update('user_auth', ['enabled' => 'on', 'password' => password_hash(self::PASSWORD, PASSWORD_DEFAULT)], ['id' => self::GUEST_ID]);
        $cookie = $this->seedToken(bin2hex(random_bytes(32)), '127.0.0.1', self::GUEST_ID);

        $this->assertRefused($this->request('/gated.php', [], ['cacti_remembers' => $cookie]));
    }

    public function testTokenForALockedAccountIsRefused(): void
    {
        $this->database->update('user_auth', ['locked' => 'on'], ['id' => self::USER_ID]);
        $cookie = $this->seedToken(bin2hex(random_bytes(32)));

        $this->assertRefused($this->request('/gated.php', [], ['cacti_remembers' => $cookie]));
    }

    public function testTokenForADisabledAccountIsRefused(): void
    {
        $this->database->update('user_auth', ['enabled' => ''], ['id' => self::USER_ID]);
        $cookie = $this->seedToken(bin2hex(random_bytes(32)));

        $this->assertRefused($this->request('/gated.php', [], ['cacti_remembers' => $cookie]));
    }

    private function versionedToken(string $generation): string
    {
        $secret = bin2hex(random_bytes(32));

        return 'v1.' . $secret . '.' . hash_hmac('sha256', $secret, $generation);
    }

    /** Store a token the way set_auth_cookie() does and return its cookie value. */
    private function seedToken(string $token, string $hostname = '127.0.0.1', int $userId = self::USER_ID): string
    {
        $this->database->executeStatement('INSERT INTO user_auth_cache (user_id, hostname, last_update, token) VALUES (?, ?, NOW(), ?)', [$userId, $hostname, hash('sha512', $token)]);

        return $userId . ',0,' . $token;
    }

    /** @return list<array{user_id: int, hostname: string, token: string}> */
    private function cacheRows(): array
    {
        return array_map(
            static fn(array $row): array => ['user_id' => (int) $row['user_id'], 'hostname' => (string) $row['hostname'], 'token' => (string) $row['token']],
            $this->database->fetchAllAssociative('SELECT user_id, hostname, token FROM user_auth_cache ORDER BY id'),
        );
    }

    /** @param array{status: int, location: ?string, cookies: array<string, string>, body: string, session: array<string, mixed>, elapsed_ms: int} $response */
    private function assertRestored(array $response, string $presented, string $generation): void
    {
        self::assertSame(200, $response['status']);
        self::assertSame('GATED:' . self::USER_ID, $response['body']);
        self::assertSame(self::USER_ID, $response['session']['sess_user_id'] ?? null);
        self::assertSame($generation, $response['session']['sess_user_credential'] ?? null);
        self::assertArrayNotHasKey('sess_cookie_credential', $response['session']);

        $rotated = $response['cookies']['cacti_remembers'] ?? '';
        self::assertSame(1, preg_match('/\A10,0,v1\.([a-f0-9]{64})\.([a-f0-9]{64})\z/D', $rotated, $parts), 'The restore did not issue a v1 token.');
        self::assertSame(hash_hmac('sha256', $parts[1], $generation), $parts[2]);
        self::assertNotSame($presented, $rotated);
        $newToken = substr($rotated, strlen('10,0,'));
        self::assertSame([['user_id' => self::USER_ID, 'hostname' => '127.0.0.1', 'token' => hash('sha512', $newToken)]], $this->cacheRows(), 'The presented token survived the rotation.');
        self::assertSame(['user_id' => self::USER_ID, 'hash' => hash('sha512', $newToken)], $response['session']['sess_remember_token'] ?? null);

        self::assertSame([['username' => self::USER, 'user_id' => self::USER_ID, 'result' => 2, 'ip' => '127.0.0.1']], $this->userLog());
        self::assertSame(["LOGIN: User 'parity-user' with ID '10' Authenticated via Authentication Cookie"], $this->authLog());
    }

    /** @param array{status: int, location: ?string, cookies: array<string, string>, body: string, session: array<string, mixed>, elapsed_ms: int} $response */
    private function assertRefused(array $response, int $priorRestores = 0): void
    {
        $cache = $this->cacheRows();
        self::assertSame(200, $response['status']);
        self::assertSame('', $this->loginError($response['body']), 'A refused token must fall through to the login page.');
        self::assertSame([], $response['session']);
        self::assertArrayNotHasKey('cacti_remembers', $response['cookies']);
        self::assertCount(1, $cache, 'A refused token must not rotate or delete the stored row.');
        self::assertSame($priorRestores, count(array_filter($this->userLog(), static fn(array $row): bool => $row['result'] === 2)));
        self::assertCount($priorRestores, array_filter($this->authLog(), static fn(string $line): bool => str_contains($line, 'Authentication Cookie')));
    }

    private function assertFailureRowsOnly(string $username, int $userId): void
    {
        $rows = array_values(array_unique($this->userLog(), SORT_REGULAR));
        self::assertSame([['username' => $username, 'user_id' => $userId, 'result' => 0, 'ip' => '127.0.0.1']], $rows);
    }
}
