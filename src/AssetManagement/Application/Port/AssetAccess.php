<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Kadupul\AssetManagement\Application\Port;

use Kadupul\IdentityAccess\Contract\Actor;

/**
 * Decides who may use the asset register in the current context.
 */
interface AssetAccess
{
    /** Resolve and revalidate the current actor; throws before protected data is read. */
    public function requireActor(): Actor;
}
