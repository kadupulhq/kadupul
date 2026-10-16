<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\IdentityAccess\Contract\BrowserSession;
use Kadupul\Platform\Contract\DatabaseConnection;
use Kadupul\Platform\Infrastructure\Legacy\ConsoleUpgradeRequired;
use Kadupul\Platform\Infrastructure\Legacy\InstallationConfiguration;
use Kadupul\Platform\Infrastructure\Legacy\LegacyBrowserWorker;
use Kadupul\Platform\Infrastructure\Symfony\ConsoleHomeExceptionSubscriber;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class ConsoleUpgradeRedirectTest extends TestCase
{
    public function testMismatchedSchemaRedirectsBeforeInterpreterOrExtensionsAreRead(): void
    {
        $root = sys_get_temp_dir() . '/console-upgrade-' . bin2hex(random_bytes(8));
        mkdir($root . '/include', 0700, true);
        file_put_contents($root . '/include/config.php', '<?php $url_path = "/kadupul/";');
        file_put_contents($root . '/include/cacti_version', '1.3.0');
        try {
            $pdo = new \PDO('sqlite::memory:');
            $pdo->exec("CREATE TABLE version (cacti TEXT); INSERT INTO version VALUES ('1.2.0')");
            // No settings table exists: attempting interpreter selection fails.
            $database = $this->createMock(DatabaseConnection::class);
            $database->method('get')->willReturn($pdo);
            $session = $this->createMock(BrowserSession::class);
            $session->method('read')->willReturn(['sess_user_id' => 7, 'sess_user_credential' => str_repeat('a', 64)]);
            $request = Request::create('/kadupul/console');
            $request->attributes->set('_route', 'platform_console');
            $requests = new RequestStack();
            $requests->push($request);
            $worker = new LegacyBrowserWorker($root, $requests, $session, $database, new InstallationConfiguration($root), new NullLogger());
            try {
                $worker->console(7);
                self::fail('A mismatched schema must not execute the console.');
            } catch (ConsoleUpgradeRequired $error) {
                self::assertSame('/kadupul/install/', $error->location);
                $event = new ExceptionEvent($this->createMock(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, $error);
                $subscriber = new ConsoleHomeExceptionSubscriber(static fn() => throw new \LogicException('Login must not be selected.'), new NullLogger());
                $subscriber->onException($event);
                self::assertSame(302, $event->getResponse()?->getStatusCode());
                self::assertSame('/kadupul/install/', $event->getResponse()?->headers->get('Location'));
                self::assertStringContainsString('no-store', $event->getResponse()?->headers->get('Cache-Control'));
            }
        } finally {
            unlink($root . '/include/config.php');
            unlink($root . '/include/cacti_version');
            rmdir($root . '/include');
            rmdir($root);
        }
    }
}
