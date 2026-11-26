<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Extensions\Application\Port;

use Kadupul\Extensions\Domain\PluginSlug;
use Kadupul\Extensions\Domain\RegisteredPlugin;

/**
 * Outbound port reading registered plugin records from persistence.
 */
interface PluginStore
{
    /** @return list<RegisteredPlugin> */
    public function findAll(): array;

    public function findBySlug(PluginSlug $slug): ?RegisteredPlugin;
}
