<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Extensions\Domain;

/**
 * Validated plugin metadata; restricts optional webpage URLs to http/https.
 */
final readonly class PluginMetadata
{
    public string $name;
    public string $version;
    public string $author;
    public ?string $webpage;

    public function __construct(
        public PluginSlug $slug,
        string $name,
        string $version,
        string $author = '',
        ?string $webpage = null,
    ) {
        $trimmedName = trim($name);
        if ($trimmedName === '' || preg_match('/[\x00-\x1F\x7F]/', $trimmedName) === 1) {
            throw new \InvalidArgumentException('Plugin name must be a non-empty single-line string.');
        }

        $trimmedVersion = trim($version);
        if (preg_match('/[\x00-\x1F\x7F]/', $trimmedVersion) === 1) {
            throw new \InvalidArgumentException('Plugin version must not contain control characters.');
        }

        $trimmedAuthor = trim($author);
        if (preg_match('/[\x00-\x1F\x7F]/', $trimmedAuthor) === 1) {
            throw new \InvalidArgumentException('Plugin author must not contain control characters.');
        }

        $this->name = $trimmedName;
        $this->version = $trimmedVersion;
        $this->author = $trimmedAuthor;
        $this->webpage = self::validateWebpage($webpage);
    }

    public static function sanitizeWebpage(?string $webpage): ?string
    {
        if ($webpage === null || trim($webpage) === '') {
            return null;
        }

        try {
            return self::validateWebpage($webpage);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    private static function validateWebpage(?string $webpage): ?string
    {
        if ($webpage === null) {
            return null;
        }

        $trimmed = trim($webpage);
        if ($trimmed === '') {
            return null;
        }

        if (preg_match('/[\x00-\x1F\x7F\s]/', $trimmed) === 1) {
            throw new \InvalidArgumentException('Plugin webpage URL must not contain whitespace or control characters.');
        }

        $parts = parse_url($trimmed);
        if ($parts === false) {
            throw new \InvalidArgumentException('Plugin webpage must be a valid URL.');
        }

        $scheme = isset($parts['scheme']) ? strtolower($parts['scheme']) : '';
        $host = $parts['host'] ?? '';
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new \InvalidArgumentException('Plugin webpage must use an http or https URL with a host.');
        }

        return $trimmed;
    }
}
