<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors.
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\ColorTemplates\Application\Port;

use Kadupul\ColorTemplates\Domain\ColorTemplate;
use Kadupul\ColorTemplates\Domain\ColorTemplateFilters;
use Kadupul\ColorTemplates\Domain\ColorTemplateItem;
use Kadupul\ColorTemplates\Domain\ColorTemplatePage;

interface ColorTemplateStore
{
    public function defaultRows(): int;

    public function defaultHasGraphs(): bool;

    public function list(ColorTemplateFilters $filters): ColorTemplatePage;

    public function find(int $id): ?ColorTemplate;

    /** @return list<ColorTemplateItem> */
    public function items(int $templateId): array;

    /** @return list<array{id:int,name:string,hex:string}> */
    public function colors(): array;

    /** @param list<ColorTemplate> $templates @return array<int,string> */
    public function actionRevisions(array $templates): array;

    public function saveTemplate(int $actorId, ?int $id, string $name, ?string $revision): int;

    public function saveItem(int $actorId, int $templateId, ?int $itemId, int $colorId, ?string $revision): int;

    public function removeItem(int $actorId, int $templateId, int $itemId, ?string $revision): void;

    /** @param list<int> $orderedItemIds */
    public function reorder(int $actorId, int $templateId, array $orderedItemIds, ?string $revision): void;

    /** @param list<int> $ids */
    public function delete(int $actorId, array $ids, array $revisions): void;

    /** @param list<int> $ids */
    public function duplicate(int $actorId, array $ids, string $titleFormat, array $revisions): void;
}
