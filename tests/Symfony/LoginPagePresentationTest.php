<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Kadupul\IdentityAccess\Contract\AuthenticatedAccess;
use Kadupul\IdentityAccess\Infrastructure\Symfony\LoginPageRenderer;
use Kadupul\Kernel;
use Kadupul\Platform\Contract\DatabaseConnection;
use Twig\Markup;

final class LoginPagePresentationTest extends TestCase
{
    public function testInjectedRendererNeedsNoAccountOrDatabaseAccess(): void
    {
        $kernel = new Kernel('test', true);
        try {
            $kernel->boot();
            $services = $kernel->getContainer()->get('test.service_container');
            $database = $this->createMock(DatabaseConnection::class);
            $database->expects($this->never())->method('get');
            $services->set(DatabaseConnection::class, $database);
            $identity = $this->createMock(AuthenticatedAccess::class);
            $identity->expects($this->never())->method('authenticatedActor');
            $services->set(AuthenticatedAccess::class, $identity);
            $renderer = $kernel->getContainer()->get(LoginPageRenderer::class);
            self::assertInstanceOf(LoginPageRenderer::class, $renderer);
            $page = $renderer->begin(new Markup('fr-FR', 'UTF-8'))
                . $renderer->formStart('Connexion', 'login.php')
                . $renderer->credentials('Connectez-vous', 'Utilisateur', 'Mot de passe', new Markup('alice &amp; bob', 'UTF-8'))
                . $renderer->realms('Domaine', [['value' => '2', 'name' => new Markup('LDAP &amp; Local', 'UTF-8'), 'selected' => true]])
                . $renderer->remember('Rester connecté', true)
                . $renderer->submit('Connexion')
                . $renderer->result(new Markup('Refus &lt;reason&gt;', 'UTF-8'), 'Version <1>', 'fixture" onload="alert(1)', false)
                . $renderer->end();
            $xpath = $this->document($page);
            self::assertSame('alice & bob', $xpath->evaluate('string(//input[@name="login_username"]/@value)'));
            self::assertSame('LDAP & Local', $xpath->evaluate('string(//option[@selected])'));
            self::assertSame('Refus <reason>', $xpath->evaluate('string(//div[@class="loginErrors"])'));
            self::assertSame('Version <1>', $xpath->evaluate('string(//div[@class="versionInfo"])'));
            self::assertSame('fixture" onload="alert(1)', $xpath->evaluate('string(//script/@nonce)'));
            self::assertSame(0, $xpath->query('//script/@onload')->length);
            self::assertSame(1, $xpath->query('//input[@name="remember_me" and @checked]')->length);
            self::assertStringContainsString("$('#login_password').focus();", $page);
        } finally {
            $kernel->shutdown();
        }
    }

    /** @return array<string, mixed> */
    private function render(array $scenario): array
    {
        $process = proc_open(
            [PHP_BINARY, '-d', 'memory_limit=2G', '-d', 'auto_prepend_file=', dirname(__DIR__) . '/Fixtures/login-page-presentation.php', json_encode($scenario, JSON_THROW_ON_ERROR)],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $errors);
        self::assertSame('', $errors);
        return json_decode($output, true, flags: JSON_THROW_ON_ERROR);
    }

    private function document(string $html): \DOMXPath
    {
        $document = new \DOMDocument();
        self::assertTrue(@$document->loadHTML('<?xml encoding="UTF-8">' . $html));
        return new \DOMXPath($document);
    }

    public static function pages(): iterable
    {
        yield 'local' => [[], 0, 0];
        yield 'LDAP' => [['method' => 3], 2, 0];
        yield 'LDAP remembered realm' => [['method' => 3, 'realm' => 1], 2, 0];
        yield 'domains absent' => [['method' => 4], 0, 0];
        yield 'domains' => [['method' => 4, 'domains' => [1 => ['name' => 'Local', 'selected' => false], 9 => ['name' => 'Directory', 'selected' => true]]], 2, 0];
        yield 'remember offered' => [['remember' => true], 0, 1];
        yield 'remember checked' => [['remember' => true, 'remember_checked' => true], 0, 1];
        yield 'translated native labels' => [['locale' => 'fr-FR'], 0, 0];
        yield 'native entity semantics' => [['username' => '<tag> &amp; ` &quot;'], 0, 0];
    }

    #[DataProvider('pages')]
    public function testNativeEntryRetainsFormAndCsrfContract(array $scenario, int $realms, int $remember): void
    {
        $result = $this->render($scenario);
        $xpath = $this->document($result['page']);
        self::assertSame(1, $xpath->query('//form[@id="login" and @name="login" and @method="post" and @action="login.php"]')->length);
        self::assertSame('login', $xpath->evaluate('string(//input[@name="action"]/@value)'));
        self::assertSame(1, $xpath->query('//form[@id="login"]//input[@name="__csrf_magic"]')->length);
        self::assertTrue($result['token_valid']);
        self::assertSame('retained', $result['session']['fixture_state']);
        self::assertSame('', $xpath->evaluate('string(//input[@name="login_password"]/@value)'));
        self::assertSame('current-password', $xpath->evaluate('string(//input[@name="login_password"]/@autocomplete)'));
        self::assertSame('retained', $xpath->evaluate('string(//input[@name="plugin_value"]/@value)'));
        self::assertStringNotContainsString('IGNORED_HOOK_RETURN', $result['page']);
        self::assertSame($realms, $xpath->query('//select[@name="realm"]/option')->length);
        self::assertSame($remember, $xpath->query('//input[@name="remember_me"]')->length);
        self::assertSame(($scenario['remember_checked'] ?? false) ? 1 : 0, $xpath->query('//input[@name="remember_me" and @checked]')->length);
        self::assertSame(($scenario['locale'] ?? '') === 'fr-FR' ? 'Connexion' : 'User Login', $xpath->evaluate('string(//h1)'));
        self::assertSame($scenario['locale'] ?? 'en-US', $xpath->evaluate('string(//html/@lang)'));
        if (isset($scenario['username'])) {
            self::assertSame('<tag> & ` "', $xpath->evaluate('string(//input[@name="login_username"]/@value)'));
            self::assertSame(0, $xpath->query('//tag')->length);
        }
        if (isset($scenario['realm'])) {
            self::assertSame('1', $xpath->evaluate('string(//option[@selected]/@value)'));
        }
        self::assertTrue($result['observations']['form_open_before_hook']);
        self::assertTrue($result['observations']['bootstrap_globals_retained']);
        self::assertTrue($result['observations']['submit_before_after_hook']);
        self::assertStringContainsString('var refreshIsLogout=false;', $result['page']);
        self::assertSame(2, $xpath->query('//script[@nonce and not(@src)]')->length);
    }

    public function testHooksObserveAndChangeTheSameRenderingStages(): void
    {
        $result = $this->render(['method' => 3, 'mutate' => true]);
        $xpath = $this->document($result['page']);
        self::assertSame(['custom_login', 'login_title', 'login_before', 'login_realms', 'login_after'], $result['hooks']);
        self::assertTrue($result['observations']['credentials_before_realms']);
        self::assertSame('before <tag> & `', $xpath->evaluate('string(//input[@name="login_username"]/@value)'));
        self::assertSame('after <script>not markup</script> & `', $xpath->evaluate('string(//div[@class="loginErrors"])'));
        self::assertSame(0, $xpath->query('//div[@class="loginErrors"]/script')->length);
        self::assertSame('Version hook-version | 2004-2026', $xpath->evaluate('string(//div[@class="versionInfo"])'));
        self::assertSame('Same & <label>', $xpath->evaluate('string(//option[@value="7"])'));
        self::assertSame('7', $xpath->evaluate('string(//option[@selected]/@value)'));
        self::assertStringContainsString("$('#login_username').focus();", $result['page']);
        self::assertStringNotContainsString("$('#login_password').focus();", $result['page']);
    }

    public function testReskinOwnsTheResponse(): void
    {
        $result = $this->render(['reskin' => true]);
        self::assertSame(['custom_login'], $result['hooks']);
        self::assertSame('<div id="custom-login">Plugin login</div>', $result['page']);
        self::assertFalse($result['token_valid']);
    }

    public static function stoppedHooks(): iterable
    {
        yield ['login_before', false];
        yield ['login_after', true];
    }

    #[DataProvider('stoppedHooks')]
    public function testAPluginCanStopAfterPreviouslyRenderedOutput(string $hook, bool $credentialsRendered): void
    {
        $result = $this->render(['stop' => $hook]);
        self::assertStringContainsString("<form id='login'", $result['page']);
        self::assertSame($credentialsRendered, str_contains($result['page'], "id='login_username'"));
        self::assertStringNotContainsString('var refreshIsLogout=', $result['page']);
        self::assertSame($hook, $result['hooks'][array_key_last($result['hooks'])]);
    }
}
