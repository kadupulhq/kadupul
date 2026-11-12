<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Domain;

/**
 * A destination for placing a device's graphs: a position in a graph tree, or a report.
 */
final readonly class DevicePlacement
{
    public int $targetId;
    public int $parentId;
    /**
     * Validates the destination and, for reports, the display settings.
     *
     * @param string $kind Either "tree" or "report".
     * @param string $destination "<tree id>:<parent id>" for a tree (parent 0 means the root), or the report id.
     * @param int $timespan Report timespan, 1 to 28; must be 0 for a tree.
     * @param int $alignment Report alignment, 1 to 3; must be 0 for a tree.
     * @throws \InvalidArgumentException When the destination or display settings are invalid.
     */
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
