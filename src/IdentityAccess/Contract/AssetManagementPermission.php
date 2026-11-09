<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Kadupul\IdentityAccess\Contract;

interface AssetManagementPermission
{
    /** The bounded asset pilot is restricted to installation administrators (realm 15). */
    public function mayManageAssets(Actor $actor): bool;
}
