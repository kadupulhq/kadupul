<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\IdentityAccess\Contract\AuthenticatedAccess;
use Kadupul\Kernel;
use Kadupul\Platform\Contract\DatabaseConnection;
use Kadupul\Platform\Infrastructure\Symfony\LegacyLogoutPage;
use Kadupul\Platform\Infrastructure\Symfony\NativeLogoutPage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class LogoutMessagePresentationTest extends TestCase
{
    public function testNestedNativeBuffersAreCollectedWithoutFlushingTheCaller(): void
    {
        $level = ob_get_level();
        ob_start();
        echo 'head';
        ob_start(static fn(string $text): string => strtoupper($text));
        echo 'plugin';
        $this->assertSame('headPLUGIN', LegacyLogoutPage::finishCapture($level));
        $this->assertSame($level, ob_get_level());
    }

    public function testClosedNativeCaptureIsRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Logout extension closed its output boundary.');
        LegacyLogoutPage::finishCapture(ob_get_level());
    }

    public function testLogoutConfirmationTargetUsesConfiguredApplicationPath(): void
    {
        $request = Request::create('/monitoring/logout.php', 'GET', [], [], [], ['HTTP_HOST' => 'attacker.example']);

        $this->assertSame('/monitoring/logout.php', LegacyLogoutPage::confirmationTarget('/monitoring/'));
        $this->assertSame('attacker.example', $request->getHost());
    }

    public static function methods(): iterable
    {
        yield ['GET'];
        yield ['HEAD'];
        yield ['POST'];
    }

    #[DataProvider('methods')]
    public function testNativeHandoffRetainsOutputAndPolicyWithoutRestoringIdentity(string $method): void
    {
        $kernel = new Kernel('test', true);
        try {
            $kernel->boot();
            $container = $kernel->getContainer()->get('test.service_container');
            $database = $this->createMock(DatabaseConnection::class);
            $database->expects($this->never())->method('get');
            $container->set(DatabaseConnection::class, $database);
            $access = $this->createMock(AuthenticatedAccess::class);
            $access->expects($this->never())->method('authenticatedActor');
            $container->set(AuthenticatedAccess::class, $access);
            $page = new NativeLogoutPage(
                '<title>Déconnexion</title><link rel="stylesheet" href="/monitoring/include/themes/modern/main.css">',
                '<script nonce="fixture-nonce">window.nativeFooter=true;</script>',
                'Session terminée <script>not markup</script>',
                'Fermez le navigateur ou <a href="index.php">Connexion</a>',
                'Déconnexion automatique',
                'Version <not-markup>',
                '2004-2026',
                'fixture-nonce" onload="alert(1)',
                ['Content-Security-Policy' => "default-src 'self'; script-src 'nonce-fixture-nonce'"],
            );
            $original = Request::create('/monitoring/logout.php?action=timeout', $method, [], ['Cacti' => str_repeat('a', 26), 'cacti_remembers' => 'untrusted'], [], [
                'SCRIPT_NAME' => '/monitoring/logout.php', 'PHP_SELF' => '/monitoring/logout.php',
                'SCRIPT_FILENAME' => dirname(__DIR__, 2) . '/logout.php',
            ]);
            $request = LegacyLogoutPage::request($original, dirname(__DIR__, 2), $page);
            $this->assertSame('/monitoring/app.php', $request->getBaseUrl());
            $this->assertSame('/logout/message', $request->getPathInfo());
            $response = $kernel->handle($request);
            $this->assertSame(200, $response->getStatusCode());
            $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
            $this->assertSame($page->policies['Content-Security-Policy'], $response->headers->get('Content-Security-Policy'));
            $this->assertFalse($request->hasSession());
            $this->assertSame([], $response->headers->getCookies());
            if ($method === 'HEAD') {
                $this->assertSame('', $response->getContent());
                return;
            }
            $document = new \DOMDocument();
            @$document->loadHTML('<?xml encoding="utf-8" ?>' . $response->getContent());
            $xpath = new \DOMXPath($document);
            $this->assertSame('Déconnexion automatique', $xpath->evaluate('string(//h1)'));
            $this->assertSame($page->message, $xpath->evaluate('string(//div[@class="logoutTitle"]/p[1])'));
            $this->assertSame('Version <not-markup> | 2004-2026', $xpath->evaluate('string(//div[@class="versionInfo"])'));
            $this->assertSame(1, $xpath->query('//a[@href="index.php" and .="Connexion"]')->length);
            $this->assertSame(1, $xpath->query('//link[@href="/monitoring/include/themes/modern/main.css"]')->length);
            $this->assertSame(1, $xpath->query('//script[@nonce="fixture-nonce"]')->length);
            $this->assertSame('fixture-nonce" onload="alert(1)', $xpath->evaluate('string(//script[@type="text/javascript"]/@nonce)'));
            $this->assertSame(0, $xpath->query('//script[@type="text/javascript"]/@onload')->length);
            $this->assertSame(0, $xpath->query('//div[@class="logoutTitle"]//script')->length);
        } finally {
            $kernel->shutdown();
        }
    }

    public function testDirectRequestsCannotSupplyTheNativeHandoff(): void
    {
        $kernel = new Kernel('test', true);
        try {
            foreach (['GET', 'HEAD', 'POST'] as $method) {
                $request = Request::create('/logout/message', $method, [NativeLogoutPage::class => ['message' => 'forged']], [], [], ['HTTP_X_NATIVE_LOGOUT_PAGE' => 'forged']);
                $response = $kernel->handle($request);
                $this->assertSame($method === 'POST' ? 405 : 404, $response->getStatusCode());
                if ($method !== 'POST') {
                    $this->assertSame('', $response->getContent());
                }
            }
        } finally {
            $kernel->shutdown();
        }
    }
}
