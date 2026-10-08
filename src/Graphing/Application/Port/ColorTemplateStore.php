<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Application\Port;

use Kadupul\Graphing\Domain\ColorTemplate;
use Kadupul\Graphing\Domain\ColorTemplateFilters;
use Kadupul\Graphing\Domain\ColorTemplateItem;
use Kadupul\Graphing\Domain\ColorTemplatePage;

interface ColorTemplateStore
{
    public const int MAX_SELECTION = 100;

    public function defaultRows(): int;

    public function defaultHasGraphs(): bool;

    public function list(ColorTemplateFilters $filters): ColorTemplatePage;

    public function find(int $id): ?ColorTemplate;

    /** @param list<int> $ids @return list<ColorTemplate> */
    public function findMany(array $ids): array;

    /** @return list<ColorTemplateItem> in sequence order */
    public function items(int $templateId): array;

    /** @return list<array{id: int, name: string, hex: string}> */
    public function colors(): array;

    /**
     * Revisions over each template's name and items, as a confirmation shows them.
     *
     * @param list<int> $ids
     * @return array<int, string>
     */
    public function selectionRevisions(array $ids): array;

    public function save(int $actorId, ?int $id, string $name, ?string $revision): int;

    public function saveItem(int $actorId, int $templateId, ?int $itemId, int $colorId, ?string $revision): int;

    public function removeItem(int $actorId, int $templateId, int $itemId, string $revision): void;

    /** @param list<int> $itemIds the complete new order */
    public function reorder(int $actorId, int $templateId, array $itemIds, string $revision): void;

    /** @param list<int> $ids @param array<int|string, mixed> $revisions */
    public function delete(int $actorId, array $ids, array $revisions): void;

    /** @param list<int> $ids @param array<int|string, mixed> $revisions */
    public function duplicate(int $actorId, array $ids, string $titleFormat, array $revisions): void;
}
