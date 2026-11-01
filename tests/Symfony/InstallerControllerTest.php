<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Platform\Application\Installer\InstallerBackend;
use Kadupul\Platform\Application\Installer\InstallerWorkflow;
use Kadupul\Platform\Infrastructure\Symfony\Controller\InstallerController;
use Kadupul\Platform\Infrastructure\Symfony\InstallerWebContext;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Translation\Translator;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Symfony\Bridge\Twig\Extension\TranslationExtension;

final class InstallerControllerTest extends TestCase
{
    public function testDiagnosticsAreAuthorizedReadOnlyAndExcludeDeploymentData(): void
    {
        $backend = $this->createMock(InstallerBackend::class);
        $backend->expects($this->once())->method('authorize');
        $backend->expects($this->once())->method('inspect')->willReturn([
            'mode' => 'upgrade', 'schema' => '1.2.38', 'target' => '1.3.0',
            'revision' => 'private-review', 'snmp' => ['private-credential'],
            'checks' => [['name' => '/private/path', 'ok' => false, 'observed' => 'private-host', 'remedy' => 'private-diagnostic']],
            'job' => ['state' => 'failed', 'progress' => 41, 'phase' => 'private-phase', 'attempt' => 3,
                'pending_collectors' => 2, 'error' => 'private-error', 'actor' => 'private-user', 'token' => 'private-token'],
        ]);
        foreach (['configure', 'start', 'recover', 'status'] as $method) {
            $backend->expects($this->never())->method($method);
        }
        $response = ($this->controller($backend))(Request::create('/installer?view=diagnostics'));
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('attachment; filename="installer-diagnostics.json"', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('private-', $response->getContent());
        $data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('1.2.38', $data['schema']);
        $this->assertSame('Importing templates', $data['execution']['phase']);
        $this->assertSame('installation_requires_attention', $data['execution']['error_code']);
        $this->assertSame([['number' => 1, 'passed' => false]], $data['requirements']);
    }

    public function testDiagnosticsDenyBeforeInspection(): void
    {
        $backend = $this->createMock(InstallerBackend::class);
        $backend->expects($this->once())->method('authorize')->willThrowException(
            new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException()
        );
        $backend->expects($this->never())->method('inspect');
        $this->expectException(\Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException::class);
        ($this->controller($backend))(Request::create('/installer?view=diagnostics'));
    }

    public function testDiagnosticsFailureDoesNotExposeExceptionText(): void
    {
        $backend = $this->createMock(InstallerBackend::class);
        $backend->method('inspect')->willThrowException(new \RuntimeException('private-database-error'));
        $response = ($this->controller($backend))(Request::create('/installer?view=diagnostics'));
        $this->assertSame(503, $response->getStatusCode());
        $this->assertSame('{"error":"diagnostics_unavailable"}', $response->getContent());
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testNativeViewReauthorizesBeforeReadingConfigurationOrLocale(): void
    {
        define('KADUPUL_INSTALLER_WEB', true);
        $backend = $this->createMock(InstallerBackend::class);
        $backend->expects($this->once())->method('authorize')
            ->willThrowException(new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException('fixture authorization denied'));
        $context = new \Kadupul\Platform\Infrastructure\Legacy\NativeInstallerWebContext(
            new \Kadupul\Platform\Infrastructure\Legacy\NativeInstallerFunctions(),
            $backend,
        );
        $this->expectException(\Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException::class);
        $this->expectExceptionMessage('fixture authorization denied');
        $context->view();
    }

    private function controller(InstallerBackend $backend, bool $validPost = true): InstallerController
    {
        $context = $this->createMock(InstallerWebContext::class);
        $context->method('view')->willReturn(['action' => '/monitor/install/install.php', 'root' => '/monitor',
            'csrf' => 'fixture-token', 'locale' => 'en']);
        $context->method('validPost')->willReturn($validPost);
        $translator = new Translator('en');
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 2) . '/templates'), ['strict_variables' => true]);
        $twig->addExtension(new TranslationExtension($translator));
        return new InstallerController(new InstallerWorkflow($backend), $context, $twig, $translator);
    }

    public function testStatusUsesOnlyTheReadOperationAndPreservesFailure(): void
    {
        $backend = $this->createMock(InstallerBackend::class);
        $backend->expects($this->once())->method('authorize');
        $backend->expects($this->once())->method('status')->willReturn(['state' => 'failed', 'progress' => 41]);
        $backend->expects($this->never())->method('configure');
        $backend->expects($this->never())->method('start');
        $backend->expects($this->never())->method('inspect');
        $response = ($this->controller($backend))(Request::create('/installer?view=status'));
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['state' => 'failed', 'progress' => 41], json_decode($response->getContent(), true));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function testResponseSubscriberPreservesTheInstallerScriptAndBaseRestrictions(): void
    {
        $backend = $this->createMock(InstallerBackend::class);
        $backend->method('status')->willReturn(['state' => 'not_started']);
        $request = Request::create('/installer?view=status');
        $request->attributes->set('_route', 'platform_installer');
        $response = ($this->controller($backend))($request);
        $kernel = $this->createMock(\Symfony\Component\HttpKernel\HttpKernelInterface::class);
        $event = new \Symfony\Component\HttpKernel\Event\ResponseEvent(
            $kernel,
            $request,
            \Symfony\Component\HttpKernel\HttpKernelInterface::MAIN_REQUEST,
            $response
        );
        (new \Kadupul\Platform\Infrastructure\Symfony\SecurityHeaders())->onResponse($event);
        $policy = $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("script-src 'self'", $policy);
        $this->assertStringContainsString("base-uri 'none'", $policy);
        $this->assertStringNotContainsString('unsafe-inline', $policy);
    }

    public function testRejectedCsrfDoesNotDispatchOrReadProtectedPlan(): void
    {
        $backend = $this->createMock(InstallerBackend::class);
        foreach (['authorize', 'inspect', 'configure', 'start', 'status'] as $method) {
            $backend->expects($this->never())->method($method);
        }
        $request = Request::create('/installer', 'POST', ['action' => 'configure'], server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        $response = ($this->controller($backend, false))($request);
        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(['error' => 'csrf_expired', 'token' => 'fixture-token'], json_decode($response->getContent(), true));
    }

    public function testUnavailableStatusReturnsRetryGuidanceWithoutPublishingDiagnostics(): void
    {
        $backend = $this->createMock(InstallerBackend::class);
        $backend->method('status')->willThrowException(new \RuntimeException('private database diagnostic'));
        $backend->expects($this->never())->method('start');
        $backend->expects($this->never())->method('configure');
        $response = ($this->controller($backend))(Request::create('/installer?view=status'));
        $this->assertSame(503, $response->getStatusCode());
        $this->assertStringNotContainsString('private database diagnostic', $response->getContent());
        $this->assertStringContainsString('do not submit another installation', $response->getContent());
    }

    public static function malformedForms(): iterable
    {
        yield 'empty form' => [[]];
        yield 'array action' => [['action' => ['start']]];
        yield 'scalar options' => [['action' => 'configure', 'options' => 'bad', 'form_complete' => '1']];
        yield 'truncated form' => [['action' => 'configure', 'options' => ['Eula' => true]]];
        yield 'no retry acknowledgement' => [['action' => 'retry', 'revision' => str_repeat('a', 64), 'confirmed' => '1']];
    }

    #[DataProvider('malformedForms')]
    public function testMalformedTransportNeverMutates(array $post): void
    {
        $backend = $this->createMock(InstallerBackend::class);
        $backend->expects($this->never())->method('configure');
        $backend->expects($this->never())->method('start');
        $backend->expects($this->never())->method('inspect');
        $request = Request::create('/installer', 'POST', $post, server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        $this->assertSame(422, ($this->controller($backend))($request)->getStatusCode());
    }

    public function testSuccessfulSaveUsesPostRedirectGetAndInstallationSubdirectory(): void
    {
        $backend = $this->createMock(InstallerBackend::class);
        $backend->expects($this->once())->method('authorize');
        $backend->expects($this->once())->method('configure')->with($this->callback(
            static fn($options): bool => $options->values['AutomationMode'] === '0',
        ))->willReturn([]);
        $request = Request::create('/installer', 'POST', ['action' => 'configure', 'form_complete' => '1',
            'options' => ['Eula' => '1', 'AutomationMode' => '0']]);
        $response = ($this->controller($backend))($request);
        $this->assertSame(303, $response->getStatusCode());
        $this->assertSame('/monitor/install/install.php', $response->headers->get('Location'));
    }

    public function testDirectSymfonyRouteDoesNotLoadNativeDatabaseConfiguration(): void
    {
        $kernel = new \Kadupul\Kernel('test', false);
        $response = $kernel->handle(Request::create('/installer'));
        $this->assertSame(403, $response->getStatusCode());
        $kernel->shutdown();
    }

    public function testRenderedInstallerPreservesLocalLinksAndSelectedControls(): void
    {
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 2) . '/templates'), ['strict_variables' => true]);
        $twig->addExtension(new TranslationExtension(new Translator('en')));
        $plan = [
            'mode' => 'install', 'target' => '1.2.38', 'schema' => 'new_install', 'collector' => 1,
            'job' => ['state' => 'not_started', 'phase' => 'ready', 'progress' => 0, 'error' => ''],
            'checks' => [], 'reviewed' => false, 'ready' => true,
            'profiles' => [['id' => 1, 'name' => 'First'], ['id' => 2, 'name' => 'Second']], 'profile' => 2,
            'discovery' => true, 'override' => true, 'range' => '127.0.0.1/32',
            'languages' => ['en' => 'English', 'fr' => 'French'], 'language' => 'fr',
            'themes' => ['classic', 'modern'], 'theme' => 'modern',
            'snmp' => [['name' => 'version', 'label' => 'Version', 'choices' => [1 => '1', 2 => '2'], 'value' => 2]],
            'tables' => [], 'cron' => 300, 'paths' => [],
            'templates' => [['key' => 'first', 'name' => 'First', 'selected' => true],
                ['key' => 'second', 'name' => 'Second', 'selected' => false]],
        ];
        foreach (['', '/monitor'] as $root) {
            $html = $twig->render('platform/installer.html.twig', ['plan' => $plan, 'error' => '',
                'view' => ['action' => $root . '/install/install.php', 'root' => $root, 'locale' => 'en', 'csrf' => 'fixture-token']]);
            $document = new \DOMDocument();
            $this->assertTrue($document->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING));
            $xpath = new \DOMXPath($document);
            $this->assertSame($root . '/install/install.php', $xpath->evaluate('string(//a[normalize-space(.)="Check again"]/@href)'));
            foreach (['profile' => '2', 'language' => 'fr', 'theme' => 'modern', 'snmp-version' => '2'] as $id => $value) {
                $this->assertSame($value, $xpath->evaluate('string(//select[@id="' . $id . '"]/option[@selected]/@value)'));
                $this->assertSame(1, $xpath->query('//select[@id="' . $id . '"]/option[@selected]')->length);
            }
            $this->assertSame(3, $xpath->query('//input[@type="checkbox"][@checked]')->length);
            $this->assertSame(0, $xpath->query('//input[@name="options[Templates][second]"][@checked]')->length);
        }
    }
}
