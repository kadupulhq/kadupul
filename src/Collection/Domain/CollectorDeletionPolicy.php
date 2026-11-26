<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Collection\Domain;

/**
 * Protects the primary collector (id = 1) from deletion or disablement.
 */
final readonly class CollectorDeletionPolicy
{
    public function canDelete(CollectorId $id): bool
    {
        return !$id->isPrimary();
    }

    public function assertDeletable(CollectorId $id): void
    {
        if (!$this->canDelete($id)) {
            throw new \DomainException('The primary collector cannot be deleted.');
        }
    }

    public function canDisable(CollectorId $id): bool
    {
        return !$id->isPrimary();
    }

    public function assertDisableable(CollectorId $id): void
    {
        if (!$this->canDisable($id)) {
            throw new \DomainException('The primary collector cannot be disabled.');
        }
    }
}
