<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Application\Port;

use Kadupul\Graphing\Domain\GprintPreset;
use Kadupul\Graphing\Domain\GprintPresetFilters;
use Kadupul\Graphing\Domain\GprintPresetPage;

interface GprintPresetStore
{
    public const int MAX_DELETE_SELECTION = 100;

    public function defaultRows(): int;
    public function defaultHasGraphs(): bool;
    public function find(int $id): ?GprintPreset;
    /** @param list<int> $ids @return list<GprintPreset> */
    public function findMany(array $ids): array;
    public function list(GprintPresetFilters $filters): GprintPresetPage;
    public function save(int $actorId, ?int $id, string $name, string $gprintText, ?string $revision): int;

    /** @param list<int> $ids @param array<int, string> $revisions */
    public function delete(int $actorId, array $ids, array $revisions): void;
}
