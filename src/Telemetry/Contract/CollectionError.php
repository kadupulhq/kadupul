<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Telemetry\Contract;

final readonly class CollectionError
{
    public function __construct(
        public CollectionErrorCode $code,
        public string $message,
        public ?\DateTimeImmutable $at = null,
    ) {}
}
