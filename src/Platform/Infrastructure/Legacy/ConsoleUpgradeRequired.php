<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Legacy;

use Kadupul\Platform\Contract\InstallationUpgradeRequired;

final class ConsoleUpgradeRequired extends \RuntimeException implements InstallationUpgradeRequired
{
    public function __construct(public readonly string $location)
    {
        parent::__construct('The installation requires an upgrade.');
    }

    public function location(): string
    {
        return $this->location;
    }
}
