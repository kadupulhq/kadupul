<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Contract;

interface DeviceTreePlacement
{
    /** @return array<string, string> Accessible destination IDs and labels. */
    public function destinations(int $actorId): array;
    /** Called inside an authenticated primary transaction with all devices locked. */
    public function place(int $actorId, array $deviceIds, int $treeId, int $parentId): void;
}
