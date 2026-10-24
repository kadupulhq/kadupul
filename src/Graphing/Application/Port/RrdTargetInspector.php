<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Graphing\Application\Port;

interface RrdTargetInspector
{
    /**
     * Reads the file's data sources and last update without changing it.
     *
     * @throws StorageRejected when the file is missing (TargetMissing) or cannot be read
     */
    public function inspect(RrdTarget $target): RrdFileInfo;
}
