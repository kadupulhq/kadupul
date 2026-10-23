<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Graphing\Application\Port;

use Kadupul\Monitoring\Contract\StorageReference;

interface RrdTargetLocator
{
    /**
     * Turns an opaque storage reference into a file inside the configured root.
     *
     * @throws StorageRejected when the reference is unknown, the file is missing, or it resolves outside the root
     */
    public function locate(StorageReference $reference): RrdTarget;
}
