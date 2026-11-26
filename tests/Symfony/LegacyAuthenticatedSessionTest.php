<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\IdentityAccess\Infrastructure\Legacy\LegacyAuthenticatedSession;
use Kadupul\IdentityAccess\Infrastructure\Legacy\ReadOnlyDatabaseSessionHandler;
use Kadupul\IdentityAccess\Infrastructure\Legacy\SharedSession;
use Kadupul\Platform\Contract\DatabaseConnection;
use Kadupul\Platform\Contract\LegacyConfiguration;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class LegacyAuthenticatedSessionTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testActiveSessionEnforcesMustChangePasswordForLocalAccountsWithoutRevokingSession(): void
    {
        require_once dirname(__DIR__, 2) . '/lib/auth.php';
        $directory = sys_get_temp_dir() . '/legacy-auth-session-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700, true);
        session_save_path($directory);

        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE settings (name TEXT, value TEXT)');
        $pdo->exec("INSERT INTO settings VALUES ('auth_method','1'),('guest_user','3'),('force_https','')");
        $pdo->exec('CREATE TABLE settings_user (user_id INTEGER, name TEXT, value TEXT)');
        $pdo->exec('CREATE TABLE user_auth (id INTEGER PRIMARY KEY, username TEXT, realm INTEGER, enabled TEXT, locked TEXT, password TEXT, must_change_password TEXT, password_change TEXT)');
        $pdo->exec("INSERT INTO user_auth VALUES (9, 'account', 0, 'on', '', 'stored-hash', 'on', 'on')");

        $generation = \auth_session_credential_generation(9, 'stored-hash', $pdo);
        session_name('LegacyAuthUnit');
        session_start(['use_cookies' => false]);
        $sessionId = session_id();
        $_SESSION = [
            'sess_user_id' => 9,
            'sess_user_credential' => $generation,
            'cacti_cwd' => $directory,
        ];
        session_write_close();

        $_COOKIE = ['LegacyAuthUnit' => $sessionId];
        $request = Request::create('/console', 'GET', [], $_COOKIE);
        $requests = new RequestStack();
        $requests->push($request);

        $database = new readonly class ($pdo) implements DatabaseConnection {
            public function __construct(private \PDO $pdo) {}

            public function get(): \PDO
            {
                return $this->pdo;
            }
        };
        $configuration = $this->createStub(LegacyConfiguration::class);
        $configuration->method('values')->willReturn([
            'root' => $directory,
            'session_name' => 'LegacyAuthUnit',
            'database_sessions' => false,
            'url_path' => '/',
            'cookie_domain' => '',
        ]);
        $session = new LegacyAuthenticatedSession(
            new SharedSession($requests, $configuration, new ReadOnlyDatabaseSessionHandler($database), $database),
            $database,
        );

        try {
            // Local realm 0 with must_change_password=on and password_change=on is ineligible under auth_method 1 and 3.
            self::assertNull($session->authenticatedActor());
            self::assertFileExists($directory . '/sess_' . $sessionId);

            $pdo->exec("UPDATE settings SET value = '3' WHERE name = 'auth_method'");
            self::assertNull($session->authenticatedActor());
            self::assertFileExists($directory . '/sess_' . $sessionId);

            // When password_change is off, or the account is non-local, or must_change_password is off, the actor is admitted.
            $pdo->exec("UPDATE user_auth SET password_change = '' WHERE id = 9");
            self::assertSame(9, $session->authenticatedActor()?->id);

            $pdo->exec("UPDATE user_auth SET realm = 3, must_change_password = 'on', password_change = 'on' WHERE id = 9");
            self::assertSame(9, $session->authenticatedActor()?->id);

            $pdo->exec("UPDATE user_auth SET realm = 0, must_change_password = '', password_change = 'on' WHERE id = 9");
            self::assertSame(9, $session->authenticatedActor()?->id);
        } finally {
            foreach (glob($directory . '/sess_*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }
}
