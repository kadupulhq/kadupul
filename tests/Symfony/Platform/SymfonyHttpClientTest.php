<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests\Platform;

use Kadupul\Platform\Contract\HttpClientException;
use Kadupul\Platform\Contract\HttpRequest;
use Kadupul\Platform\Infrastructure\Http\SymfonyHttpClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class SymfonyHttpClientTest extends TestCase
{
    public function testSendsRequestWithTlsVerificationAndBoundedOptions(): void
    {
        $capturedMethod = '';
        $capturedUrl = '';
        /** @var array<string, mixed> $capturedOptions */
        $capturedOptions = [];

        $mock = new MockHttpClient(static function (string $method, string $url, array $options) use (&$capturedMethod, &$capturedUrl, &$capturedOptions): MockResponse {
            $capturedMethod = $method;
            $capturedUrl = $url;
            $capturedOptions = $options;

            return new MockResponse('{"ok":true}', [
                'http_code' => 200,
                'response_headers' => ['content-type: application/json'],
            ]);
        });

        $client = new SymfonyHttpClient($mock);
        $response = $client->send(new HttpRequest(
            'POST',
            'https://example.org/webhook',
            ['Accept' => 'application/json'],
            '{"event":"ping"}',
            5.0,
            0,
        ));

        $this->assertSame('POST', $capturedMethod);
        $this->assertSame('https://example.org/webhook', $capturedUrl);
        $this->assertTrue($capturedOptions['verify_peer']);
        $this->assertTrue($capturedOptions['verify_host']);
        $this->assertSame(0, $capturedOptions['max_redirects']);
        $this->assertSame(5.0, $capturedOptions['timeout']);
        $this->assertSame('{"event":"ping"}', $capturedOptions['body']);

        $this->assertSame(200, $response->statusCode);
        $this->assertTrue($response->isSuccessful());
        $this->assertSame('{"ok":true}', $response->body);
        $this->assertSame(['application/json'], $response->headers['content-type']);
    }

    public function testBlocksPrivateMetadataAddressBeforeTouchingTransport(): void
    {
        $called = false;
        $mock = new MockHttpClient(static function () use (&$called): MockResponse {
            $called = true;

            return new MockResponse('should not run');
        });

        $client = new SymfonyHttpClient($mock);

        try {
            $client->send(HttpRequest::get('http://169.254.169.254/latest/meta-data/'));
            $this->fail('Expected OutboundUrlPolicy rejection.');
        } catch (HttpClientException $error) {
            $this->assertStringContainsString('blocked address range', $error->getMessage());
        }

        $this->assertFalse($called);
    }

    public function testWrapsTransportErrorsInHttpClientException(): void
    {
        $mock = new MockHttpClient(static function (): MockResponse {
            return new MockResponse('', ['error' => 'connection timed out']);
        });

        $client = new SymfonyHttpClient($mock);
        $this->expectException(HttpClientException::class);
        $this->expectExceptionMessage('connection timed out');
        $client->send(HttpRequest::get('https://example.org/timeout'));
    }

    public function testHttpRequestValidatesMethodTimeoutAndRedirects(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new HttpRequest('TRACE', 'https://example.org/');
    }
}
