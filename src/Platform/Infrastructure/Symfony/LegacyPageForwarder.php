<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Symfony;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\KernelInterface;

/** Route fixed legacy entry points through Symfony without replaying their old handlers. */
final class LegacyPageForwarder
{
    public static function run(KernelInterface $kernel, string $projectDir, string $fixedUri): void
    {
        if (!str_starts_with($fixedUri, 'app.php/')) {
            throw new \InvalidArgumentException('Invalid fixed legacy route.');
        }
        $request = self::request(Request::createFromGlobals(), $projectDir, substr($fixedUri, 7));
        $response = $kernel->handle($request);
        $response->send();
        $kernel->terminate($request, $response);
    }

    public static function request(Request $original, string $projectDir, string $routePath): Request
    {
        if (!preg_match('~\A/[a-z0-9/-]+\z~D', $routePath)) {
            throw new \InvalidArgumentException('Invalid fixed legacy route.');
        }
        $base = rtrim(str_replace('\\', '/', dirname($original->getBaseUrl())), '/.');
        $script = $base . '/app.php';
        return $original->duplicate(server: array_replace($original->server->all(), [
            'SCRIPT_FILENAME' => $projectDir . '/app.php',
            'SCRIPT_NAME' => $script,
            'PHP_SELF' => $script . $routePath,
            'REQUEST_URI' => $script . $routePath,
        ]));
    }
}
