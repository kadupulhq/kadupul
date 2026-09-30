<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors.
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\ColorTemplates\Domain;

final readonly class ColorTemplateItem
{
    public string $revision;

    public function __construct(public int $id, public int $templateId, public int $colorId, public int $sequence, public string $hex)
    {
        $this->revision = hash('sha256', json_encode([$id, $templateId, $colorId, $sequence], JSON_THROW_ON_ERROR));
    }
}
