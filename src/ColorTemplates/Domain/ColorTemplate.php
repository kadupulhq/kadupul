<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors.
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\ColorTemplates\Domain;

final readonly class ColorTemplate
{
    public string $revision;

    public function __construct(
        public int $id,
        public string $name,
        public int $graphs,
        public int $templates,
        public int $items,
    ) {
        $this->revision = hash('sha256', json_encode([$id, $name], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    public function deletable(): bool
    {
        return $this->graphs === 0 && $this->templates === 0;
    }
}
