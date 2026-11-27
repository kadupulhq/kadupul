<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Extensions\Contract;

/**
 * Read-only plugin registry exposed to other bounded contexts.
 */
interface PluginRegistry
{
    /** @return list<PluginSummary> */
    public function listAll(): array;

    public function findBySlug(string $slug): ?PluginSummary;

    public function isEnabled(string $slug): bool;
}
