<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Tests\Monitoring\Fakes;

use Kadupul\Monitoring\Application\Port\WriterOwnershipProbe;
use Kadupul\Monitoring\Domain\StorageField;
use Kadupul\Monitoring\Domain\WriterOwnership;

/** Legacy ownership by storage field, as the legacy discovery adapter would report it. */
final class FixedWriterOwnershipProbe implements WriterOwnershipProbe
{
    /** @param array<string, WriterOwnership> $owners keyed by StorageField::toString() */
    public function __construct(public array $owners = []) {}

    public function ownership(StorageField $field): WriterOwnership
    {
        return $this->owners[$field->toString()] ?? WriterOwnership::Unassigned;
    }
}
