<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Monitoring\Domain;

/** Carries every violation at once so an author can fix a pack in one pass. */
final class InvalidPack extends \DomainException
{
    /**
     * @param list<PackViolation> $violations
     */
    public function __construct(public readonly string $source, public readonly array $violations)
    {
        parent::__construct(sprintf(
            "Monitoring pack %s is invalid and was not installed:\n%s",
            $source,
            implode("\n", array_map(static fn(PackViolation $v): string => $source . ': ' . $v->toString(), $violations)),
        ));
    }
}
