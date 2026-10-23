<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Monitoring\Infrastructure\Persistence;

use Kadupul\Monitoring\Application\Port\WriterOwnershipProbe;
use Kadupul\Monitoring\Domain\OwnershipClaimRefused;
use Kadupul\Monitoring\Domain\StorageField;
use Kadupul\Monitoring\Domain\WriterOwnership;

/**
 * Model-driven write claims, held in memory, combined with what the legacy
 * probe reports. A claim is only ever made by an explicit claim() call and
 * is refused for a field the legacy poller writes; there is no automatic
 * handoff in either direction. If the legacy poller starts writing a field
 * after it was claimed, both sides claim it and the answer is a conflict.
 */
final class WriterOwnershipRegistry implements WriterOwnershipProbe
{
    /** @var array<string, StorageField> */
    private array $claims = [];

    public function __construct(private readonly WriterOwnershipProbe $legacy) {}

    public function ownership(StorageField $field): WriterOwnership
    {
        $legacy = $this->legacy->ownership($field);
        if (!isset($this->claims[$field->toString()])) {
            return $legacy;
        }

        return $this->legacyHolds($legacy) ? WriterOwnership::Conflict : WriterOwnership::ModelDriven;
    }

    public function claim(StorageField $field): WriterOwnership
    {
        if (isset($this->claims[$field->toString()])) {
            return $this->ownership($field);
        }
        $legacy = $this->legacy->ownership($field);
        if ($this->legacyHolds($legacy)) {
            throw new OwnershipClaimRefused(sprintf('Storage field %s is %s; model-driven collection cannot claim it, and no handoff is implemented.', $field->toString(), $legacy->value));
        }
        $this->claims[$field->toString()] = $field;

        return WriterOwnership::ModelDriven;
    }

    /** @return list<StorageField> */
    public function claims(): array
    {
        return array_values($this->claims);
    }

    private function legacyHolds(WriterOwnership $legacy): bool
    {
        return $legacy === WriterOwnership::LegacyPoller || $legacy === WriterOwnership::Conflict;
    }
}
