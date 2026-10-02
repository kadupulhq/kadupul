<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use Kadupul\IdentityAccess\Infrastructure\Legacy\NativeAuthenticationSession;
use Kadupul\Platform\Contract\DatabaseConnection;
use Kadupul\Platform\Contract\LegacyConfiguration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class NativeAuthenticationSessionTest extends TestCase
{
    public static function longerIds(): iterable
    {
        yield '48 characters' => [48];
        yield '64 characters' => [64];
    }

    private function configureSidLength(int $length): void
    {
        $deprecations = [];
        set_error_handler(static function (int $severity, string $message) use (&$deprecations): bool {
            if ($severity === E_DEPRECATED && str_contains($message, 'session.sid_length')) {
                $deprecations[] = $message;
                return true;
            }
            return false;
        }, E_DEPRECATED);
        try {
            self::assertNotFalse(ini_set('session.sid_length', (string) $length));
        } finally {
            restore_error_handler();
        }
        // PHP 8.4 deprecates changing this existing supported setting.
        self::assertCount(1, $deprecations);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    #[DataProvider('longerIds')]
    public function testLongConfiguredFileIdPersistsPublishesAndRevokes(int $length): void
    {
        $this->configureSidLength($length);
        $directory = sys_get_temp_dir() . '/about-long-sid-' . bin2hex(random_bytes(8));
        mkdir($directory);
        session_save_path($directory);
        $configuration = $this->createMock(LegacyConfiguration::class);
        $configuration->method('values')->willReturn(['root' => $directory, 'session_name' => 'AboutLongUnit', 'database_sessions' => false, 'url_path' => '/', 'cookie_domain' => '']);
        $database = $this->createMock(DatabaseConnection::class);
        $database->expects(self::never())->method('get');
        $sessions = new NativeAuthenticationSession($configuration, $database);
        $request = Request::create('/about');
        try {
            $id = $sessions->establish(9, $request, '127.0.0.1');
            self::assertSame($length, strlen($id));
            self::assertStringContainsString('sess_user_id|i:9;', file_get_contents($directory . '/sess_' . $id));
            $sessions->publish($id, $request);
            $sessions->revoke($id, $request);
            self::assertSame([], glob($directory . '/sess_*'));
        } finally {
            foreach (glob($directory . '/sess_*') as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    #[DataProvider('longerIds')]
    public function testDatabaseCapacityRefusesLongConfigurationBeforePersistence(int $length): void
    {
        $this->configureSidLength($length);
        $configuration = $this->createMock(LegacyConfiguration::class);
        $configuration->method('values')->willReturn(['database_sessions' => true]);
        $database = $this->createMock(DatabaseConnection::class);
        $database->expects(self::never())->method('get');
        try {
            (new NativeAuthenticationSession($configuration, $database))->establish(9, Request::create('/about'), '127.0.0.1');
            self::fail('A configured ID larger than VARCHAR(32) must be refused.');
        } catch (RuntimeException $failure) {
            self::assertSame('Configured authentication session ID exceeds storage capacity.', $failure->getMessage());
            self::assertSame(PHP_SESSION_NONE, session_status());
            self::assertSame('', session_id());
        }
    }
}
