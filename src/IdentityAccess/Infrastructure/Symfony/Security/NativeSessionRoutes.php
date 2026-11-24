<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Infrastructure\Symfony\Security;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestMatcherInterface;

/** Route names preserve front-controller and subdirectory installations. */
final class NativeSessionRoutes implements RequestMatcherInterface
{
    public function matches(Request $request): bool
    {
        return in_array($request->attributes->get('_route'), ['session', 'platform_about', 'platform_about_legacy'], true);
    }
}
