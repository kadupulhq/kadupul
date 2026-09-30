<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Application\Port;

use Kadupul\Graphing\Domain\PaletteColor;
use Kadupul\Graphing\Domain\PaletteColorFilters;
use Kadupul\Graphing\Domain\PaletteColorPage;

interface PaletteColorStore
{
    public function defaultRows(): int;
    public function defaultHasGraphs(): bool;
    public function find(int $id): ?PaletteColor;
    /** @param list<int> $ids @return list<PaletteColor> */
    public function findMany(array $ids): array;
    public function list(PaletteColorFilters $filters): PaletteColorPage;
    public function save(int $actorId, ?int $id, string $name, string $hex, ?string $revision): int;

    /** @param list<int> $ids */
    public function delete(int $actorId, array $ids, array $revisions = []): void;
    public function snapshot(): string;
    public function export(PaletteColorFilters $filters): array;
    public function import(int $actorId, array $rows, bool $allowUpdate, string $revision): array;
}
