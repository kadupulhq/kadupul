<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Symfony;

use Symfony\Component\HttpFoundation\Response;

/** Only trusted native extension output (console fragments, a custom_denied page) retains legacy CSP modes. */
final class ConsoleHomeResponse extends Response
{
    /** @param array<string, string> $compatibilityPolicies */
    public function __construct(string $html, public readonly array $compatibilityPolicies)
    {
        foreach ($compatibilityPolicies as $name => $value) {
            if (!in_array($name, ['Content-Security-Policy', 'Content-Security-Policy-Report-Only'], true)
                || !is_string($value) || preg_match('/[\x00-\x1f\x7f]/', $value)) {
                throw new \InvalidArgumentException('Invalid console security policy.');
            }
        }
        parent::__construct($html, 200, ['Cache-Control' => 'private, no-store']);
    }
}
