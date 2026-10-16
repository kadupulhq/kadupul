<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Symfony;

use Kadupul\Platform\Infrastructure\Legacy\InstallationConfiguration;

final readonly class ConsoleLoginLocation
{
    public function __construct(private InstallationConfiguration $configuration) {}

    public function path(): string
    {
        return $this->configuration->urlPath() . '/login.php';
    }
}
