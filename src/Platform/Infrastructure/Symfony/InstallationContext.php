<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Symfony;

use Kadupul\Platform\Contract\LegacyConfiguration;
use Symfony\Component\Asset\Context\ContextInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Attribute\AsTwigFunction;

/**
 * URLs below the installation root, from the configured url_path legacy pages use.
 *
 * The request base path depends on the front controller: /app.php gives the
 * installation root, but /public/index.php gives <root>/public. Legacy pages,
 * include/ files and link.php always live below url_path.
 */
final class InstallationContext implements ContextInterface
{
    private ?string $basePath = null;

    public function __construct(private readonly LegacyConfiguration $configuration, private readonly RequestStack $requests) {}

    /** The installation root without a trailing slash: "" or "/kadupul". */
    public function getBasePath(): string
    {
        return $this->basePath ??= $this->configuredBasePath();
    }

    public function isSecure(): bool
    {
        return $this->requests->getMainRequest()?->isSecure() ?? false;
    }

    /** URL of a page or file given relative to the installation root, such as "host.php". */
    #[AsTwigFunction('installation_path')]
    public function path(string $relativePath): string
    {
        return $this->getBasePath() . '/' . ltrim($relativePath, '/');
    }

    private function configuredBasePath(): string
    {
        try {
            $path = $this->configuration->values()['url_path'] ?? '/';
        } catch (\RuntimeException) {
            // Without readable configuration the page cannot load its data
            // either; the root keeps the layout renderable.
            return '';
        }
        // Same acceptance as the VDEF item handler URL: one absolute path, no
        // scheme, host, backslash, control character, query or fragment.
        if (!is_string($path) || !str_starts_with($path, '/') || str_starts_with($path, '//')
            || str_contains($path, '\\') || preg_match('/[\x00-\x20?#]/', $path) === 1) {
            return '';
        }

        return rtrim($path, '/');
    }
}
