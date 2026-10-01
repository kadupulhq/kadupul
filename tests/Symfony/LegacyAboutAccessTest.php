<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use Kadupul\IdentityAccess\Contract\AuditTrail;
use Kadupul\IdentityAccess\Infrastructure\Legacy\LegacyAboutAccess;
use Kadupul\IdentityAccess\Infrastructure\Legacy\LegacyBrowserAuthentication;
use Kadupul\IdentityAccess\Infrastructure\Legacy\NativeAuthenticationSession;
use Kadupul\IdentityAccess\Infrastructure\Legacy\ReadOnlyDatabaseSessionHandler;
use Kadupul\IdentityAccess\Infrastructure\Legacy\SharedSession;
use Kadupul\Platform\Contract\DatabaseConnection;
use Kadupul\Platform\Contract\LegacyConfiguration;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class LegacyAboutAccessTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testPresentNullPasswordChangeFlagNeverFallsBackToAuthentication(): void
    {
        $directory = sys_get_temp_dir() . '/about-password-change-' . bin2hex(random_bytes(8));
        mkdir($directory);
        session_save_path($directory);
        session_name('AboutUnit');
        session_start(['use_cookies' => false]);
        $id = session_id();
        $_SESSION = ['sess_user_id' => 9, 'sess_change_password' => null, 'cacti_cwd' => $directory];
        session_write_close();
        $_COOKIE = ['AboutUnit' => $id];
        $request = Request::create('/about', 'GET', [], $_COOKIE);
        $requests = new RequestStack();
        $requests->push($request);
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE settings (name TEXT, value TEXT)');
        $pdo->exec("INSERT INTO settings VALUES ('auth_method','2'),('force_https','')");
        // No account table: reaching account lookup is a real SQL failure.
        $database = $this->createMock(DatabaseConnection::class);
        $database->method('get')->willReturn($pdo);
        $configuration = $this->createMock(LegacyConfiguration::class);
        $configuration->method('values')->willReturn(['root' => $directory, 'session_name' => 'AboutUnit', 'database_sessions' => false, 'url_path' => '/', 'cookie_domain' => '']);
        $audit = $this->createMock(AuditTrail::class);
        $audit->expects(self::never())->method('record');
        $session = new SharedSession($requests, $configuration, new ReadOnlyDatabaseSessionHandler($database), $database);
        $authentication = new LegacyBrowserAuthentication($requests, $database, $configuration, new NativeAuthenticationSession($configuration, $database), $audit);
        try {
            self::assertNull((new LegacyAboutAccess($session, $authentication))->authenticatedActor());
            self::assertSame([], $pdo->query("SELECT name FROM sqlite_master WHERE name='user_auth'")->fetchAll());
        } finally {
            foreach (glob($directory . '/sess_*') as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }
}
