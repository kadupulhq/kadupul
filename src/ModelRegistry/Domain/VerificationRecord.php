<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\ModelRegistry\Domain;

final class VerificationRecord
{
    /**
     * @param list<string> $supportedPaths
     * @param list<string> $unsupportedPaths
     */
    public function __construct(private readonly \DateTimeImmutable $observedAt, private readonly array $supportedPaths, private readonly array $unsupportedPaths)
    {
        if ($supportedPaths === []) {
            throw new InvalidAssociationTransition('A verification record needs at least one supported path; use an explicit unsupported finding otherwise.');
        }
    }

    public function observedAt(): \DateTimeImmutable
    {
        return $this->observedAt;
    }

    /** @return list<string> */
    public function supportedPaths(): array
    {
        return $this->supportedPaths;
    }

    /** @return list<string> */
    public function unsupportedPaths(): array
    {
        return $this->unsupportedPaths;
    }

    public function resultingState(): AssociationState
    {
        return $this->unsupportedPaths === [] ? AssociationState::Verified : AssociationState::Partial;
    }
}
