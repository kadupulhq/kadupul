<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests\Security;

use Kadupul\DataInput\Domain\DataInputState;
use Kadupul\Platform\Domain\RedirectTarget;
use Kadupul\Platform\Infrastructure\Symfony\ConsoleHomeResponse;
use Kadupul\Platform\Infrastructure\Symfony\SecurityHeaders;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class HttpHeadersRedirectAndFormHardeningTest extends TestCase
{
    public function testRedirectTargetRejectsDoubleAndTripleEncodedExternalAndJavascriptUrls(): void
    {
        $this->assertSame('index.php', RedirectTarget::validate('https%253A%252F%252Fevil.example%252F', 'index.php', 'kadupul.example'));
        $this->assertSame('index.php', RedirectTarget::validate('javascript%253Aalert(1)', 'index.php', 'kadupul.example'));
        $this->assertSame('index.php', RedirectTarget::validate('%252F%252Fevil.example', 'index.php', 'kadupul.example'));
        $this->assertSame('index.php', RedirectTarget::validate('https%25253A%25252F%25252Fevil.example', 'index.php', 'kadupul.example'));
        $this->assertSame('index.php', RedirectTarget::validate("/index.php\r\nX-Injected: 1", 'index.php', 'kadupul.example'));
        $this->assertSame('/graph_view.php?action=tree', RedirectTarget::validate('/graph_view.php?action=tree', 'index.php', 'kadupul.example'));
        $this->assertSame('/graph_view.php?id=1', RedirectTarget::validate('https://kadupul.example/graph_view.php?id=1', 'index.php', 'kadupul.example'));
    }

    public function testSecurityHeadersParityAndDualNonceReportPolicy(): void
    {
        $kernel = $this->createMock(HttpKernelInterface::class);
        $request = Request::create('https://kadupul.example/about');
        $response = new Response('ok');
        $event = new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response);

        (new SecurityHeaders())->onResponse($event);

        $this->assertSame('same-origin', $response->headers->get('Cross-Origin-Opener-Policy'));
        $this->assertSame('same-origin', $response->headers->get('Cross-Origin-Resource-Policy'));
        $this->assertSame('camera=(), geolocation=(), interest-cohort=(), microphone=(), payment=(), usb=()', $response->headers->get('Permissions-Policy'));
        $this->assertSame('max-age=31536000; includeSubDomains', $response->headers->get('Strict-Transport-Security'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        // A ConsoleHomeResponse with only Report-Only compatibility policy must retain enforcing CSP.
        $consoleResponse = new ConsoleHomeResponse('ok', [
            'Content-Security-Policy-Report-Only' => "default-src 'self'; script-src 'nonce-abc'",
        ]);
        $consoleEvent = new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $consoleResponse);
        (new SecurityHeaders())->onResponse($consoleEvent);

        $this->assertNotNull($consoleResponse->headers->get('Content-Security-Policy'));
        $this->assertSame("default-src 'self'; script-src 'nonce-abc'", $consoleResponse->headers->get('Content-Security-Policy-Report-Only'));
    }

    public function testLegacyNonceReportReturnsBothEnforcingAndReportOnlyPolicies(): void
    {
        $root = dirname(__DIR__, 3);
        $code = 'function read_config_option($name) { return $name === "content_security_policy_script" ? "nonce-report" : ""; } '
            . 'require ' . var_export($root . '/lib/headers_secure.php', true) . '; '
            . 'echo json_encode(CactiSecureHeaders::contentSecurityPolicies(), JSON_THROW_ON_ERROR);';
        $output = [];
        $status = 0;
        exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code), $output, $status);
        $this->assertSame(0, $status);
        /** @var array<string, string> $policies */
        $policies = json_decode(implode('', $output), true, 512, JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey('Content-Security-Policy', $policies);
        $this->assertArrayHasKey('Content-Security-Policy-Report-Only', $policies);
        $this->assertStringContainsString("default-src 'self'", $policies['Content-Security-Policy']);
        $this->assertStringContainsString('nonce-', $policies['Content-Security-Policy-Report-Only']);
    }

    public function testDataInputStateRejectsNonIdentifierDataName(): void
    {
        $valid = DataInputState::field([
            'name' => 'Hostname',
            'data_name' => 'host_name_1',
            'input_output' => 'in',
            'type_code' => 'hostname',
            'regexp_match' => '',
            'update_rra' => false,
            'allow_nulls' => false,
        ]);
        $this->assertSame('host_name_1', $valid['data_name']);

        $this->expectException(\InvalidArgumentException::class);
        DataInputState::field([
            'name' => 'Bad Field',
            'data_name' => "x' onmouseover='alert(1)",
            'input_output' => 'in',
            'type_code' => '',
            'regexp_match' => '',
            'update_rra' => false,
            'allow_nulls' => false,
        ]);
    }
}
