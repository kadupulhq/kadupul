<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Asset;

use Kadupul\Platform\Infrastructure\Symfony\InstallationContext;
use Symfony\Component\Asset\PathPackage;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * The "legacy" package for asset(path, 'legacy'): include/ and images/ files
 * at the URLs legacy pages emit, below url_path whichever front controller
 * served the request.
 */
#[AutoconfigureTag('assets.package', ['package' => 'legacy'])]
final class LegacyAssetPackage extends PathPackage
{
    public function __construct(CompiledAssetVersionStrategy $versions, InstallationContext $context)
    {
        parent::__construct('/', $versions, $context);
    }
}
