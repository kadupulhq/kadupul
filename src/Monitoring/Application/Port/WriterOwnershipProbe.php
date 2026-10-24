<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Monitoring\Application\Port;

use Kadupul\Monitoring\Domain\StorageField;
use Kadupul\Monitoring\Domain\WriterOwnership;

/** Answers who writes a storage field today. It never changes the answer. */
interface WriterOwnershipProbe
{
    public function ownership(StorageField $field): WriterOwnership;
}
