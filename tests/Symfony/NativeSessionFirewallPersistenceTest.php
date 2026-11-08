<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\IdentityAccess\Infrastructure\Legacy\ReadOnlyDatabaseSessionHandler;
use Kadupul\IdentityAccess\Infrastructure\Symfony\Security\SessionIdentity;
use Kadupul\Kernel;
use Kadupul\Platform\Contract\DatabaseConnection;
use Kadupul\Platform\Contract\LegacyConfiguration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

final class NativeSessionFirewallPersistenceTest extends TestCase
{
    public static function sessions(): iterable
    {
        foreach ([false, true] as $database) {
            foreach (['/session', '/about/legacy'] as $path) {
                foreach (['current', 'password-replaced', 'disabled', 'locked', 'guest', 'forced-change', 'no-console-realm'] as $state) {
                    yield ($database ? 'database' : 'file') . $path . ':' . $state => [$database, $path, $state];
                }
            }
        }
    }

    #[DataProvider('sessions')]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testNativePayloadIsValidatedByTheRealFirewall(bool $databaseSessions, string $path, string $state): void
    {
        $directory = sys_get_temp_dir() . '/native-firewall-' . bin2hex(random_bytes(8));
        $this->assertTrue(mkdir($directory, 0700));
        $pdo = new \PDO('sqlite::memory:', options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC]);
        $pdo->exec("CREATE TABLE user_auth (id INTEGER PRIMARY KEY, username TEXT, enabled TEXT, locked TEXT, password TEXT);
            INSERT INTO user_auth VALUES (9, 'operator', 'on', '', 'stored-hash');
            CREATE TABLE settings (name TEXT, value TEXT);
            INSERT INTO settings VALUES ('auth_method', '1'), ('guest_user', '0'), ('i18n_language_support', '0');
            CREATE TABLE settings_user (user_id INTEGER, name TEXT, value TEXT);
            CREATE TABLE user_auth_realm (user_id INTEGER, realm_id INTEGER);
            INSERT INTO user_auth_realm VALUES (9, 8);
            CREATE TABLE user_auth_group (id INTEGER, enabled TEXT);
            CREATE TABLE user_auth_group_members (group_id INTEGER, user_id INTEGER);
            CREATE TABLE user_auth_group_realm (group_id INTEGER, realm_id INTEGER);
            CREATE TABLE sessions (id TEXT PRIMARY KEY, data TEXT, access INTEGER)");
        $configuration = $this->createMock(LegacyConfiguration::class);
        $configuration->method('values')->willReturn(['root' => $directory, 'session_name' => 'Cacti', 'database_sessions' => $databaseSessions, 'url_path' => '/monitoring/', 'cookie_domain' => '']);
        $connection = $this->createMock(DatabaseConnection::class);
        $connection->method('get')->willReturn($pdo);
        session_save_path($directory);
        session_name('Cacti');
        $this->assertTrue(session_start(['use_cookies' => false]));
        $_SESSION = ['cacti_cwd' => $directory, 'sess_user_id' => 9, 'sess_user_credential' => hash('sha256', 'stored-hash')];
        if ($state === 'forced-change') {
            $_SESSION['sess_change_password'] = true;
        }
        $id = session_id();
        $payload = session_encode();
        $this->assertTrue(session_write_close());
        if ($databaseSessions) {
            $pdo->prepare('INSERT INTO sessions VALUES (?, ?, ?)')->execute([$id, $payload, time()]);
        }
        match ($state) {
            'password-replaced' => $pdo->exec("UPDATE user_auth SET password = 'replacement-hash'"),
            'disabled' => $pdo->exec("UPDATE user_auth SET enabled = ''"),
            'locked' => $pdo->exec("UPDATE user_auth SET locked = 'on'"),
            'guest' => $pdo->exec("UPDATE settings SET value = '9' WHERE name = 'guest_user'"),
            'no-console-realm' => $pdo->exec('DELETE FROM user_auth_realm'),
            default => null,
        };
        $_COOKIE = ['Cacti' => $id];
        $_ENV['SHELL_VERBOSITY'] = '-1';
        $_SERVER['SHELL_VERBOSITY'] = '-1';
        $kernel = new Kernel('test', true);
        try {
            $kernel->boot();
            $container = $kernel->getContainer()->get('test.service_container');
            $container->set(LegacyConfiguration::class, $configuration);
            $container->set(DatabaseConnection::class, $connection);
            $container->set(ReadOnlyDatabaseSessionHandler::class, new ReadOnlyDatabaseSessionHandler($connection));
            $request = Request::create('/monitoring/app.php' . $path, 'GET', [], $_COOKIE, [], [
                'SCRIPT_NAME' => '/monitoring/app.php', 'PHP_SELF' => '/monitoring/app.php' . $path,
                'SCRIPT_FILENAME' => dirname(__DIR__, 2) . '/app.php',
            ]);
            $response = $kernel->handle($request);
            $allowed = $state === 'current' || ($state === 'no-console-realm' && $path === '/about/legacy');
            $this->assertSame($allowed ? ($path === '/session' ? 200 : 302) : 401, $response->getStatusCode());
            $token = $container->get(TokenStorageInterface::class)->getToken();
            if ($allowed) {
                $this->assertInstanceOf(SessionIdentity::class, $token?->getUser());
                $this->assertSame('9', $token->getUserIdentifier());
                if ($path === '/session') {
                    $this->assertSame(['id' => 9, 'username' => 'operator'], json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR));
                } else {
                    $this->assertSame('/monitoring/app.php/about', $response->headers->get('Location'));
                }
                $stored = $databaseSessions
                    ? $pdo->query('SELECT data FROM sessions')->fetchColumn()
                    : file_get_contents($directory . '/sess_' . $id);
                $this->assertSame($payload, $stored, 'Firewall authentication must retain the legacy payload exactly.');
            } else {
                $this->assertNull($token);
            }
            $this->assertFalse($request->hasSession(), 'Symfony must not create a second session bag.');
            $this->assertSame(PHP_SESSION_NONE, session_status());
            $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
            if (in_array($state, ['password-replaced', 'disabled', 'locked'], true)) {
                $this->assertSame(0, $databaseSessions
                    ? (int) $pdo->query('SELECT COUNT(*) FROM sessions')->fetchColumn()
                    : count(glob($directory . '/sess_*')));
            }
        } finally {
            $kernel->shutdown();
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_abort();
            }
            foreach (glob($directory . '/sess_*') as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }
}
