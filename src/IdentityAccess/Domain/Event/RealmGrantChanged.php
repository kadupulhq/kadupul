<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Domain\Event;

/** Recorded when a direct user or group realm grant is added or removed. */
final readonly class RealmGrantChanged
{
    public function __construct(
        public int $principalId,
        public bool $isGroup,
        public int $realmId,
        public bool $granted,
        public \DateTimeImmutable $occurredAt,
    ) {}
}
