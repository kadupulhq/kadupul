<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Http;

use Kadupul\Platform\Contract\HttpClient as HttpClientContract;
use Kadupul\Platform\Contract\HttpClientException;
use Kadupul\Platform\Contract\HttpRequest;
use Kadupul\Platform\Contract\HttpResponse;
use Kadupul\Platform\Domain\OutboundUrlPolicy;
use Symfony\Component\HttpClient\CurlHttpClient;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\NativeHttpClient;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Component\HttpClient\ScopingHttpClient;
use Symfony\Component\HttpClient\UriTemplateHttpClient;

/**
 * Outbound HTTP adapter backed by symfony/http-client. Always enforces TLS peer
 * and host verification, bounded timeouts, bounded redirects, and OutboundUrlPolicy
 * (plus NoPrivateNetworkHttpClient socket-level filtering on live transports).
 */
final readonly class SymfonyHttpClient implements HttpClientContract
{
    public function __construct(
        private UriTemplateHttpClient|ScopingHttpClient|NoPrivateNetworkHttpClient|CurlHttpClient|NativeHttpClient|MockHttpClient|null $client = null,
        private OutboundUrlPolicy $urlPolicy = new OutboundUrlPolicy(),
    ) {}

    #[\Override]
    public function send(HttpRequest $request): HttpResponse
    {
        try {
            $this->urlPolicy->assertAllowed($request->url, $request->allowPrivateNetwork);
        } catch (\InvalidArgumentException $error) {
            throw new HttpClientException($error->getMessage(), 0, $error);
        }

        $options = [
            'headers' => $request->headers,
            'timeout' => $request->timeoutSeconds,
            'max_duration' => $request->timeoutSeconds,
            'max_redirects' => $request->maxRedirects,
            'verify_peer' => true,
            'verify_host' => true,
        ];
        if ($request->body !== null) {
            $options['body'] = $request->body;
        }

        $transport = $this->client ?? HttpClient::create([
            'verify_peer' => true,
            'verify_host' => true,
            'max_redirects' => 0,
            'timeout' => HttpRequest::DEFAULT_TIMEOUT_SECONDS,
        ]);

        if (!$request->allowPrivateNetwork && !$transport instanceof MockHttpClient && !$transport instanceof NoPrivateNetworkHttpClient) {
            $transport = new NoPrivateNetworkHttpClient($transport);
        }

        try {
            $response = $transport->request($request->method, $request->url, $options);
            $statusCode = $response->getStatusCode();
            $headers = $response->getHeaders(false);
            $body = $response->getContent(false);
        } catch (\Throwable $error) {
            throw new HttpClientException('Outbound HTTP request failed: ' . $error->getMessage(), 0, $error);
        }

        return new HttpResponse($statusCode, $headers, $body);
    }
}
