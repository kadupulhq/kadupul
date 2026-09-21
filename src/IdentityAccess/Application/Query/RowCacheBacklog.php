<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Application\Query;

use Kadupul\IdentityAccess\Domain\RowCacheInvalidation;

final readonly class RowCacheBacklog
{
    public function __construct(public RowCacheInvalidation $invalidation, public int $rows) {}
}
