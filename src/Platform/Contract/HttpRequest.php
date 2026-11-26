<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Contract;

/**
 * Immutable outbound HTTP request specification.
 */
final readonly class HttpRequest
{
    public const float DEFAULT_TIMEOUT_SECONDS = 10.0;
    public const float MAX_TIMEOUT_SECONDS = 30.0;
    public const int MAX_REDIRECTS = 5;

    /** @var list<string> */
    private const array ALLOWED_METHODS = ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE'];

    public string $method;

    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        string $method,
        public string $url,
        public array $headers = [],
        public ?string $body = null,
        public float $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS,
        public int $maxRedirects = 0,
        public bool $allowPrivateNetwork = false,
    ) {
        $normalized = strtoupper(trim($method));
        if (!in_array($normalized, self::ALLOWED_METHODS, true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported HTTP method "%s".', $method));
        }

        if ($this->timeoutSeconds <= 0.0 || $this->timeoutSeconds > self::MAX_TIMEOUT_SECONDS) {
            throw new \InvalidArgumentException(sprintf('HTTP timeout must be between 0 and %.1f seconds.', self::MAX_TIMEOUT_SECONDS));
        }

        if ($this->maxRedirects < 0 || $this->maxRedirects > self::MAX_REDIRECTS) {
            throw new \InvalidArgumentException(sprintf('HTTP maxRedirects must be between 0 and %d.', self::MAX_REDIRECTS));
        }

        foreach ($this->headers as $name => $value) {
            if ($name === '' || preg_match('/[\r\n\x00]/', $name) === 1 || preg_match('/[\r\n\x00]/', $value) === 1) {
                throw new \InvalidArgumentException('HTTP header names and values must not contain control characters.');
            }
        }

        $this->method = $normalized;
    }

    /**
     * @param array<string, string> $headers
     */
    public static function get(
        string $url,
        array $headers = [],
        float $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS,
        int $maxRedirects = 0,
        bool $allowPrivateNetwork = false,
    ): self {
        return new self('GET', $url, $headers, null, $timeoutSeconds, $maxRedirects, $allowPrivateNetwork);
    }
}
