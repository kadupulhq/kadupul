<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Kadupul\IdentityAccess\Application\ReadModel;

final readonly class LocalLoginFailure
{
    public function __construct(
        public bool $accountFound,
        public bool $disabled,
        public bool $locked,
        public mixed $failedAttempts,
        public int $limit,
    ) {}
}
