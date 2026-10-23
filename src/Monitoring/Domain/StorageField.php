<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Monitoring\Domain;

/**
 * One field of one stored series. A legacy data source can hold several
 * fields in the same RRD file, so the local data id alone does not identify
 * what a binding writes.
 */
final readonly class StorageField
{
    public function __construct(public int $localDataId, public RrdDataSourceName $name)
    {
        if ($localDataId < 1) {
            throw new InvalidBinding(sprintf('A storage field needs a positive local data id, not %d.', $localDataId));
        }
    }

    public function equals(self $other): bool
    {
        return $this->localDataId === $other->localDataId && $this->name->equals($other->name);
    }

    public function toString(): string
    {
        return $this->localDataId . ':' . $this->name->value;
    }
}
