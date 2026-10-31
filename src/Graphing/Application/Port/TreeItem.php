<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Application\Port;

/** A tree node as far as a parent check needs it. */
final readonly class TreeItem
{
    public function __construct(public string $title, public bool $hasGraph, public bool $hasDevice, public bool $hasSite) {}

    /** Only a node with a title and nothing attached can hold children. */
    public function isHeader(): bool
    {
        return $this->title !== '' && !$this->hasGraph && !$this->hasDevice && !$this->hasSite;
    }
}
