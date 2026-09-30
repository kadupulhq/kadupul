<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Domain;

final readonly class GprintPreset
{
    public string $revision;

    public function __construct(
        public int $id,
        public string $name,
        public string $gprintText,
        public int $graphs,
        public int $templates,
        string $hash,
    ) {
        $this->revision = hash('sha256', json_encode([$id, $name, $gprintText, $hash], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    public function isDeletable(): bool
    {
        return $this->graphs === 0 && $this->templates === 0;
    }
}
