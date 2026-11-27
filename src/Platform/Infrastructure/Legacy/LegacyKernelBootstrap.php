<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Legacy;

use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Boots the Symfony Kernel through config/bootstrap.php for legacy cli/*.php
 * shims without leaking a require statement into non-Legacy infrastructure.
 */
final class LegacyKernelBootstrap
{
    public static function create(): KernelInterface
    {
        $kernel = require dirname(__DIR__, 4) . '/config/bootstrap.php';
        if (!$kernel instanceof KernelInterface) {
            throw new \RuntimeException('Bootstrap did not return a Symfony KernelInterface instance.');
        }

        return $kernel;
    }
}
