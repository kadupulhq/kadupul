<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Symfony;

/** A web request outwaited its bound while cache:clear replaced the cache. */
final class CacheRefreshInProgress extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('The Symfony cache is being refreshed. Retry shortly.');
    }
}
