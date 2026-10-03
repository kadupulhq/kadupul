<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Domain;

final readonly class DevicePlacement
{
    public int $targetId;
    public int $parentId;
    public function __construct(public string $kind, public string $destination, public int $timespan = 0, public int $alignment = 0)
    {
        if (!in_array($kind, ['tree', 'report'], true) || !preg_match($kind === 'tree' ? '/^[1-9][0-9]{0,9}:(0|[1-9][0-9]{0,9})$/' : '/^[1-9][0-9]{0,9}$/', $destination)) {
            throw new \InvalidArgumentException('Select a valid placement destination.');
        }
        $parts = explode(':', $destination);
        $this->targetId = (int) $parts[0];
        $this->parentId = (int) ($parts[1] ?? 0);
        if ($this->targetId > 4294967295 || $this->parentId > 4294967295) {
            throw new \InvalidArgumentException('Select a valid placement destination.');
        }
        if (($kind === 'tree' && ($timespan !== 0 || $alignment !== 0)) || ($kind === 'report' && ($timespan < 1 || $timespan > 28 || $alignment < 1 || $alignment > 3))) {
            throw new \InvalidArgumentException('Select valid report display settings.');
        }
    }
}
