<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Domain;

final readonly class ColorTemplateItem
{
    public string $revision;

    public function __construct(public int $id, public int $templateId, public int $colorId, public int $sequence, public string $hex, public string $colorName = '')
    {
        $this->revision = hash('sha256', json_encode([$id, $templateId, $colorId, $sequence], JSON_THROW_ON_ERROR));
    }

    /** @param list<self> $items in stored order */
    public static function orderRevision(array $items): string
    {
        return hash('sha256', json_encode(array_map(static fn(self $item): array => [$item->id, $item->colorId, $item->sequence], $items), JSON_THROW_ON_ERROR));
    }
}
