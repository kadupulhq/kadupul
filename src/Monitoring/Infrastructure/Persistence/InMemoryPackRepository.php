<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Monitoring\Infrastructure\Persistence;

use Kadupul\Monitoring\Application\Port\PackRepository;
use Kadupul\Monitoring\Domain\InstalledPack;

/** Holds installed packs for one process; lost when it ends. */
final class InMemoryPackRepository implements PackRepository
{
    /** @var array<string, InstalledPack> */
    private array $packs = [];

    /** The installed pack with this name, or null. */
    public function find(string $name): ?InstalledPack
    {
        return $this->packs[$name] ?? null;
    }

    /** Stores the pack, replacing the one with the same name. */
    public function save(InstalledPack $pack): void
    {
        $this->packs[$pack->pack->name] = $pack;
        ksort($this->packs, SORT_STRING);
    }

    /** All installed packs, ordered by name. */
    public function all(): array
    {
        return array_values($this->packs);
    }
}
