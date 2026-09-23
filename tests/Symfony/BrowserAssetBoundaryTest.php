<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use PHPUnit\Framework\TestCase;

final class BrowserAssetBoundaryTest extends TestCase
{
    public function testTwigTemplatesDoNotLoadRemoteBrowserAssets(): void
    {
        $root = dirname(__DIR__, 2) . '/templates/';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        $patterns = [
            '/<script\b[^>]*\bsrc\s*=\s*["\']\s*(?:https?:)?\/\//i',
            '/<link\b[^>]*\bhref\s*=\s*["\']\s*(?:https?:)?\/\//i',
            '/<(?:img|iframe|audio|video|source|embed|object)\b[^>]*\b(?:src|srcset|poster|data)\s*=\s*["\']\s*(?:https?:)?\/\//i',
            '/(?:url\(|@import\s+(?:url\()?)\s*["\']?\s*(?:https?:)?\/\//i',
        ];
        $templates = 0;
        foreach ($files as $file) {
            if ($file->getExtension() !== 'twig') {
                continue;
            }
            ++$templates;
            $relative = substr($file->getPathname(), strlen($root));
            $source = file_get_contents($file->getPathname());
            foreach ($patterns as $pattern) {
                self::assertDoesNotMatchRegularExpression($pattern, $source, $relative . ' loads a remote browser asset');
            }
        }
        self::assertGreaterThan(0, $templates);
    }
}
