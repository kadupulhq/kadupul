<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Application\Command;

/** Remove Boost graph images older than the expiry; the scheduler sends one every hour. */
final readonly class PurgeBoostImageCache
{
    /** The age boost_purge_cached_png_files() has always used. */
    public const int EXPIRY_SECONDS = 3600;
}
