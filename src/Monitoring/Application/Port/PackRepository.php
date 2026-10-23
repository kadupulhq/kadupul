<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Monitoring\Application\Port;

use Kadupul\Monitoring\Domain\InstalledPack;

/** One stored version per pack name; an upgrade replaces it. */
interface PackRepository
{
    public function find(string $name): ?InstalledPack;

    public function save(InstalledPack $pack): void;

    /** @return list<InstalledPack> ordered by name */
    public function all(): array;
}
